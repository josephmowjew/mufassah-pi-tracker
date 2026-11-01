<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerServiceSaleController extends BaseController
{
    /**
     * List sales for the current customer service representative.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $userId = $request->user()->id;

        $query = Sale::query()
            ->forCurrentUserBranch()
            ->where('sold_by_id', $userId)
            ->with(['customer', 'soldBy', 'branch'])
            ->withCount('items');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('sale_date', '>=', LocalTime::startOfDayUtc($request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->where('sale_date', '<=', LocalTime::endOfDayUtc($request->date_to));
        }

        // Search by sale number or customer name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $sales = $query->paginate($perPage);

        return $this->paginated(SaleResource::collection($sales));
    }

    /**
     * Get a specific sale with full details.
     */
    public function show(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        // Ensure the sale belongs to the current user and branch
        if ($sale->sold_by_id !== $user->id || ! $user->canAccessBranch($sale->branch_id)) {
            return $this->error('You do not have permission to view this sale', 403);
        }

        $sale->load(['customer', 'soldBy', 'branch', 'items.device.category', 'items.subscriptionPlan']);

        return $this->success(new SaleResource($sale));
    }
}
