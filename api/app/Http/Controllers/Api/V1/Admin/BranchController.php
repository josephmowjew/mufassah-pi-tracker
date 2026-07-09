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
use App\Models\StockTransferRequest;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::with('manager')->withCount('devices', 'users', 'sales');

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
        $branch = Branch::create($request->validated());

        return new BranchResource($branch->load('manager'));
    }

    public function show(Branch $branch)
    {
        return new BranchResource($branch->load('manager', 'devices', 'users', 'sales'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        if ($request->has('is_active') && ! $request->boolean('is_active') && StockTransferRequest::where('status', StockTransferRequest::PENDING)->where(fn ($query) => $query->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))->exists()) {
            return response()->json(['message' => 'Cancel or decide pending stock transfer requests before deactivating this branch.'], 422);
        }
        $branch->update($request->validated());

        return new BranchResource($branch->load('manager'));
    }

    public function destroy(Branch $branch)
    {
        $linkedRecords = [
            'devices' => $branch->devices()->count(),
            'users' => $branch->users()->count(),
            'sales' => $branch->sales()->count(),
            'receipts' => InventoryReceipt::where('branch_id', $branch->id)->count(),
            'inventory_batches' => InventoryBatch::where('branch_id', $branch->id)->count(),
            'stock_movements' => InventoryMovement::where(function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)
                    ->orWhere('from_branch_id', $branch->id)
                    ->orWhere('to_branch_id', $branch->id);
            })->count(),
            'shifts' => Shift::where('branch_id', $branch->id)->count(),
        ];

        $hasLinkedRecords = collect($linkedRecords)->contains(fn (int $count) => $count > 0);

        if ($hasLinkedRecords) {
            return response()->json([
                'message' => 'This branch has linked records. Deactivate it instead of deleting it.',
                'errors' => [
                    'branch' => ['Branches with stock, users, sales, receipts, movements, or shifts cannot be deleted.'],
                ],
                'linked_records' => $linkedRecords,
            ], 422);
        }

        $branch->delete();

        return response()->noContent();
    }

    public function stats(Branch $branch): JsonResponse
    {
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
}
