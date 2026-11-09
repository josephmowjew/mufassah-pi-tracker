<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Shift;
use App\Models\StockTransferRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user()->hasRole('super_admin')
            ? null
            : $request->user()->requiredBranchScopeId();
        $query = Branch::with('manager')
            ->withCount('devices', 'users', 'sales')
            ->when($branchId, fn ($query) => $query->whereKey($branchId));

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $branches = $query
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 15));

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request)
    {
        $this->authorizeGlobalBranchManagement($request);
        $validated = $request->validated();
        $this->validateManagerAssignment($validated['manager_id'] ?? null);

        // A soft-deleted branch still occupies its unique `code`, and MySQL has no partial
        // unique index, so the code cannot simply be reused. Resurrect the trashed row
        // rather than inserting: the code becomes reusable and the branch id stays stable
        // for anything that referenced it. Validation permits this case (see
        // StoreBranchRequest), and a live clash is still rejected before we get here.
        $branch = Branch::withTrashed()->where('code', $validated['code'])->first();

        if ($branch?->trashed()) {
            $branch->restore();
            $branch->update($validated);
        } else {
            $branch = Branch::create($validated);
        }

        $this->syncManagerBranch($branch, $validated['manager_id'] ?? null);

        return new BranchResource($branch->load('manager'));
    }

    public function show(Request $request, Branch $branch)
    {
        $this->authorizeBranchView($request, $branch);

        return new BranchResource($branch->load('manager', 'devices', 'users', 'sales'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $this->authorizeGlobalBranchManagement($request);
        if ($request->has('is_active') && ! $request->boolean('is_active') && StockTransferRequest::where('status', StockTransferRequest::PENDING)->where(fn ($query) => $query->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))->exists()) {
            return response()->json(['message' => 'Cancel or decide pending stock transfer requests before deactivating this branch.'], 422);
        }
        $validated = $request->validated();
        if (array_key_exists('manager_id', $validated)) {
            $this->validateManagerAssignment($validated['manager_id'], $branch);
        }
        $branch->update($validated);
        if (array_key_exists('manager_id', $validated)) {
            $this->syncManagerBranch($branch, $validated['manager_id']);
        }

        return new BranchResource($branch->load('manager'));
    }

    public function destroy(Request $request, Branch $branch)
    {
        $this->authorizeGlobalBranchManagement($request);
        $linkedRecords = [
            'devices' => $branch->devices()->count(),
            'users' => $branch->users()->count(),
            'sales' => $branch->sales()->count(),
            'laybys' => \App\Models\Layby::where('branch_id', $branch->id)->count(),
            'receipts' => InventoryReceipt::where('branch_id', $branch->id)->count(),
            'inventory_batches' => InventoryBatch::where('branch_id', $branch->id)->count(),
            'stock_movements' => InventoryMovement::where(function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)
                    ->orWhere('from_branch_id', $branch->id)
                    ->orWhere('to_branch_id', $branch->id);
            })->count(),
            'shifts' => Shift::where('branch_id', $branch->id)->count(),
            // stock_transfer_requests.*_branch_id is ON DELETE RESTRICT, so a transfer
            // in ANY status blocks the delete at the database level - not just PENDING
            // ones. Omitting this made the guard pass and the RESTRICT surface as an
            // unhandled SQLSTATE 1451 (a 500) instead of this 422.
            'stock_transfer_requests' => StockTransferRequest::where(function ($query) use ($branch) {
                $query->where('from_branch_id', $branch->id)
                    ->orWhere('to_branch_id', $branch->id);
            })->count(),
        ];

        $hasLinkedRecords = collect($linkedRecords)->contains(fn (int $count) => $count > 0);

        if ($hasLinkedRecords) {
            return response()->json([
                'message' => 'This branch has linked records. Deactivate it instead of deleting it.',
                'errors' => [
                    'branch' => ['Branches with stock, users, sales, receipts, movements, shifts, or stock transfer requests cannot be deleted.'],
                ],
                'linked_records' => $linkedRecords,
            ], 422);
        }

        $branch->delete();

        return response()->noContent();
    }

    public function stats(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranchView($request, $branch);
        $stats = [
            'total_devices' => $branch->devices()->count(),
            'in_stock' => $branch->devices()->where('inventory_status', 'in_stock')->count(),
            'sold' => $branch->devices()->where('inventory_status', 'sold')->count(),
            'total_users' => $branch->users()->whereHas('roles', function ($q) {
                $q->where('name', 'customer_service');
            })->count(),
            'total_sales' => $branch->sales()->where('status', 'completed')->count(),
            'revenue' => $branch->sales()->where('status', 'completed')->sum('total_amount'),
        ];

        return response()->json($stats);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    private function syncManagerBranch(Branch $branch, ?int $managerId): void
    {
        if (! $managerId) {
            return;
        }

        $manager = $branch->manager()->first();
        if ($manager && ! $manager->hasRole('super_admin') && (int) $manager->branch_id !== (int) $branch->id) {
            $manager->update(['branch_id' => $branch->id]);
        }
    }

    private function authorizeGlobalBranchManagement(Request $request): void
    {
        abort_unless($request->user()->hasRole('super_admin'), 403, 'Only super admins can manage branches.');
    }

    private function authorizeBranchView(Request $request, Branch $branch): void
    {
        abort_unless(
            $request->user()->canAccessBranch($branch->id),
            403,
            'You cannot access another branch.'
        );
    }

    private function validateManagerAssignment(?int $managerId, ?Branch $currentBranch = null): void
    {
        if (! $managerId) {
            return;
        }

        $manager = User::findOrFail($managerId);
        if (! $manager->hasAnyRole(['super_admin', 'store_admin'])) {
            throw ValidationException::withMessages([
                'manager_id' => ['The branch manager must be a store admin or super admin.'],
            ]);
        }

        $alreadyAssigned = Branch::query()
            ->where('manager_id', $managerId)
            ->when($currentBranch, fn ($query) => $query->where('id', '!=', $currentBranch->id))
            ->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'manager_id' => ['This administrator already manages another branch.'],
            ]);
        }
    }
}
