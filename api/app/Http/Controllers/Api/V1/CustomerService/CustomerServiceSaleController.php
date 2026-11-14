<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Support\LikePattern;
use App\Support\LocalTime;
use App\Traits\PaginatesLists;
use App\Traits\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerServiceSaleController extends BaseController
{
    use PaginatesLists;
    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['sale_number', 'sale_date', 'total_amount', 'status', 'created_at'];

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
                $q->whereRaw('sale_number LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                            ->orWhereRaw('email LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
                    });
            });
        }

        // Sort
        $this->applySort($query, $request, self::SORTABLE, ['created_at', 'desc']);

        // Paginate
        $perPage = $this->perPage($request);
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
