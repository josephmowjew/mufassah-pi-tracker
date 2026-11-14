<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subscription\CancelSubscriptionRequest;
use App\Http\Requests\Api\V1\Subscription\InitiatePaymentRequest;
use App\Http\Requests\Api\V1\Subscription\UpgradeDowngradeRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\PayChanguService;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscriptions
 *
 * APIs for subscription management - view, cancel, and reactivate subscriptions.
 */
class SubscriptionController extends Controller
{
    use PaginatesLists;

    public function __construct(protected PayChanguService $payChanguService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $query = Subscription::query()->with(['plan', 'user', 'lastPayment']);
        if (! $request->user()->hasRole('super_admin')) {
            $branchId = $request->user()->requiredBranchScopeId();
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('branch_id', $branchId));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $subscriptions = $query->latest()->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => SubscriptionResource::collection($subscriptions),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->with(['plan', 'lastPayment', 'payments' => fn ($q) => $q->latest()->limit(5)])
            ->latest()
            ->first();

        // Return null data with 200 status - no subscription is a valid state, not an error
        if (! $subscription) {
            return response()->json([
                'success' => true,
                'message' => 'No active subscription.',
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        $subscription->load(['plan', 'user', 'lastPayment', 'payments']);

        return response()->json([
            'success' => true,
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    public function subscribe(InitiatePaymentRequest $request): JsonResponse
    {
        $user = $request->user();

        $existingActive = Subscription::where('user_id', $user->id)
            ->active()
            ->first();

        if ($existingActive) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active subscription. Please upgrade or cancel your current subscription first.',
            ], 400);
        }

        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        if (! $plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'The selected plan is not available.',
            ], 400);
        }

        $result = $this->payChanguService->initiatePayment(
            user: $user,
            plan: $plan,
            billingCycle: $request->billing_cycle,
            paymentMethod: \App\Enums\PaymentMethod::from($request->payment_method),
            phoneNumber: $request->phone_number,
            returnUrl: $request->return_url
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment initiated successfully.',
            'data' => [
                'checkout_url' => $result['checkout_url'],
                'tx_ref' => $result['tx_ref'],
                'payment_id' => $result['payment']->id,
            ],
        ]);
    }

    public function cancel(CancelSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('cancel', $subscription);

        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already cancelled.',
            ], 400);
        }

        if (! $subscription->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Only active subscriptions can be cancelled.',
            ], 400);
        }

        $subscription->update([
            'status' => $request->boolean('immediate') ? SubscriptionStatus::Cancelled : $subscription->status,
            'cancelled_at' => now(),
            'cancellation_reason' => $request->reason,
            'auto_renew' => false,
        ]);

        if ($request->boolean('immediate')) {
            $subscription->update(['ends_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'message' => $request->boolean('immediate')
                ? 'Subscription cancelled immediately.'
                : 'Subscription will be cancelled at the end of the billing period.',
            'data' => new SubscriptionResource($subscription->fresh(['plan'])),
        ]);
    }

    public function changePlan(UpgradeDowngradeRequest $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        if (! $subscription->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Can only change plan for active subscriptions.',
            ], 400);
        }

        $newPlan = SubscriptionPlan::findOrFail($request->plan_id);

        if (! $newPlan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'The selected plan is not available.',
            ], 400);
        }

        if ($subscription->plan_id === $newPlan->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are already subscribed to this plan.',
            ], 400);
        }

        $user = $request->user();
        $billingCycle = $request->billing_cycle ?? $subscription->billing_cycle;

        $result = $this->payChanguService->initiatePayment(
            user: $user,
            plan: $newPlan,
            billingCycle: $billingCycle,
            paymentMethod: \App\Enums\PaymentMethod::MobileMoney,
            phoneNumber: $request->phone_number,
            returnUrl: $request->return_url
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan change payment initiated.',
            'data' => [
                'checkout_url' => $result['checkout_url'],
                'tx_ref' => $result['tx_ref'],
                'payment_id' => $result['payment']->id,
            ],
        ]);
    }

    public function toggleAutoRenew(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorize('update', $subscription);

        $subscription->update([
            'auto_renew' => ! $subscription->auto_renew,
        ]);

        return response()->json([
            'success' => true,
            'message' => $subscription->auto_renew
                ? 'Auto-renewal enabled.'
                : 'Auto-renewal disabled.',
            'data' => new SubscriptionResource($subscription->fresh(['plan'])),
        ]);
    }

    public function suspend(Subscription $subscription): JsonResponse
    {
        $this->authorize('suspend', $subscription);

        if ($subscription->status === SubscriptionStatus::Suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already suspended.',
            ], 400);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Suspended,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription suspended.',
            'data' => new SubscriptionResource($subscription->fresh(['plan'])),
        ]);
    }

    public function reactivate(Subscription $subscription): JsonResponse
    {
        $this->authorize('reactivate', $subscription);

        if ($subscription->status !== SubscriptionStatus::Suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Only suspended subscriptions can be reactivated.',
            ], 400);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription reactivated.',
            'data' => new SubscriptionResource($subscription->fresh(['plan'])),
        ]);
    }
}
