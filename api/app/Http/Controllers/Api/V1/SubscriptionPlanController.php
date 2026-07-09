<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscription Plans
 *
 * APIs for subscription plans - browse available plans and pricing.
 */
class SubscriptionPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SubscriptionPlan::query();

        if (! $request->user()?->hasRole(['super_admin', 'store_admin'])) {
            $query->active();
        }

        $plans = $query->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => SubscriptionPlanResource::collection($plans),
        ]);
    }

    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new SubscriptionPlanResource($subscriptionPlan),
        ]);
    }
}
