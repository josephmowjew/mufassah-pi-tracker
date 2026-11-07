<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Models\Sale;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomerServiceController extends BaseController
{
    /**
     * Get dashboard stats for the current customer service representative.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $branchId = $user->requiredBranchScopeId();

        // Load branch relationship
        $user->load('branch');

        $stats = [
            'branch' => $user->branch ? [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'code' => $user->branch->code,
            ] : null,
            'totalSales' => Sale::where('sold_by_id', $userId)->count(),
            'totalRevenue' => (int) Sale::where('sold_by_id', $userId)
                ->where('status', 'completed')
                ->sum('total_amount'),
            'totalCustomers' => User::whereHas('salesAsCustomer', function ($query) use ($userId) {
                $query->where('sold_by_id', $userId);
            })->distinct()->count(),
            'stockCount' => Device::where('branch_id', $branchId)
                ->where('inventory_status', 'in_stock')
                ->count(),
            'activeDevices' => Sale::where('sold_by_id', $userId)
                ->where('status', 'completed')
                ->whereHas('items')
                ->count(),
            'pendingWarranties' => 0, // TODO: Implement when warranty claims are added
            'todaySales' => Sale::where('sold_by_id', $userId)
                ->whereBetween('created_at', [LocalTime::now()->startOfDay()->utc(), LocalTime::now()->endOfDay()->utc()])
                ->count(),
        ];

        return $this->success($stats);
    }

    /**
     * List customers served by the current customer service representative.
     */
    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        $query = User::role('customer', 'api')
            ->withCount(['salesAsCustomer' => function ($query) use ($userId) {
                $query->where('sold_by_id', $userId);
            }]);

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $customers = $query->paginate($perPage);

        return $this->paginated(UserResource::collection($customers));
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            // users.phone_number carries a unique index, so without this rule a
            // duplicate reaches MySQL and surfaces as a 500 instead of telling
            // the operator which field is wrong. Walk-in customers sharing a
            // phone number is a common front-desk mistake.
            'phone_number' => ['nullable', 'string', 'max:20', 'unique:users,phone_number'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'national_id' => ['nullable', 'string', 'max:50', 'unique:users,national_id'],
        ]);

        $customer = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'password' => Hash::make($validated['password'] ?? Str::random(32)),
            'branch_id' => $request->user()->requiredBranchScopeId(),
            'national_id' => $validated['national_id'] ?? null,
        ]);
        $customer->assignRole('customer');
        $customer->load('roles');

        return $this->success(new UserResource($customer), 'Customer created successfully', 201);
    }

    /**
     * List warranty claims (stub for future implementation).
     */
    public function warranties(Request $request): JsonResponse
    {
        // TODO: Implement when warranty claims system is added
        return $this->success([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 15,
                'total' => 0,
            ],
        ], 'Warranty claims feature coming soon');
    }
}
