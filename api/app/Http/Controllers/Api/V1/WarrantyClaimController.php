<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\LikePattern;
use App\Enums\WarrantyClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Warranty\ProcessClaimRequest;
use App\Http\Requests\Api\V1\Warranty\StoreWarrantyClaimRequest;
use App\Http\Resources\WarrantyClaimResource;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @group Warranty Claims
 *
 * APIs for warranty claims - submit and track warranty claims.
 */
class WarrantyClaimController extends Controller
{
    use PaginatesLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WarrantyClaim::class);

        $query = WarrantyClaim::query()->with(['warranty.device', 'processor']);
        if (! $request->user()->hasRole('super_admin')) {
            $branchId = $request->user()->requiredBranchScopeId();
            $query->whereHas(
                'warranty.device',
                fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId)
            );
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by warranty
        if ($request->has('warranty_id')) {
            $query->where('warranty_id', $request->warranty_id);
        }

        // Search by claim number
        if ($request->has('search')) {
            $query->whereRaw('claim_number LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($request->search)]);
        }

        $claims = $query->latest()->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => WarrantyClaimResource::collection($claims),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    public function store(StoreWarrantyClaimRequest $request, Warranty $warranty): JsonResponse
    {
        $this->authorize('submitClaim', $warranty);

        // Check if warranty is active
        if (! $warranty->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot submit claim for inactive or expired warranty.',
            ], 400);
        }

        // Handle evidence photo uploads
        $evidencePhotos = [];
        if ($request->hasFile('evidence_photos')) {
            foreach ($request->file('evidence_photos') as $photo) {
                $path = $photo->store('claims/'.$warranty->id, 'public');
                $evidencePhotos[] = $path;
            }
        }

        $claim = WarrantyClaim::create([
            'warranty_id' => $warranty->id,
            'claim_number' => 'WC-'.strtoupper(uniqid()),
            'status' => WarrantyClaimStatus::Pending,
            'issue_description' => $request->issue_description,
            'issue_category' => $request->issue_category,
            'evidence_photos' => $evidencePhotos,
        ]);

        $claim->load('warranty');

        return response()->json([
            'success' => true,
            'message' => 'Warranty claim submitted successfully.',
            'data' => new WarrantyClaimResource($claim),
        ], 201);
    }

    public function show(WarrantyClaim $warrantyClaim): JsonResponse
    {
        $this->authorize('view', $warrantyClaim);

        $warrantyClaim->load(['warranty.device.owner', 'processor']);

        return response()->json([
            'success' => true,
            'data' => new WarrantyClaimResource($warrantyClaim),
        ]);
    }

    public function destroy(WarrantyClaim $warrantyClaim): JsonResponse
    {
        $this->authorize('delete', $warrantyClaim);

        if ($warrantyClaim->status !== WarrantyClaimStatus::Pending) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending claims can be deleted.',
            ], 400);
        }

        // Delete evidence photos
        if ($warrantyClaim->evidence_photos) {
            foreach ($warrantyClaim->evidence_photos as $photo) {
                Storage::disk('public')->delete($photo);
            }
        }

        $warrantyClaim->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warranty claim deleted successfully.',
        ]);
    }

    public function process(ProcessClaimRequest $request, WarrantyClaim $warrantyClaim): JsonResponse
    {
        $this->authorize('process', $warrantyClaim);

        $newStatus = WarrantyClaimStatus::from($request->status);

        $warrantyClaim->update([
            'status' => $newStatus,
            'resolution_type' => $request->resolution_type,
            'resolution_notes' => $request->resolution_notes,
            'repair_cost' => $request->repair_cost,
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        $warrantyClaim->load(['warranty', 'processor']);

        return response()->json([
            'success' => true,
            'message' => 'Claim processed successfully.',
            'data' => new WarrantyClaimResource($warrantyClaim),
        ]);
    }

    public function addEvidence(Request $request, WarrantyClaim $warrantyClaim): JsonResponse
    {
        $this->authorize('addEvidence', $warrantyClaim);

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ]);

        $existingPhotos = $warrantyClaim->evidence_photos ?? [];

        if (count($existingPhotos) >= 20) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum number of evidence photos (20) reached.',
            ], 400);
        }

        $newPhotos = [];
        foreach ($request->file('photos') as $photo) {
            $path = $photo->store('claims/'.$warrantyClaim->warranty_id, 'public');
            $newPhotos[] = $path;
        }

        $warrantyClaim->update([
            'evidence_photos' => array_merge($existingPhotos, $newPhotos),
        ]);

        return response()->json([
            'success' => true,
            'message' => count($newPhotos).' evidence photo(s) added successfully.',
            'data' => [
                'added_photos' => $newPhotos,
                'total_photos' => count($warrantyClaim->evidence_photos),
            ],
        ]);
    }

    public function forWarranty(Warranty $warranty): JsonResponse
    {
        $this->authorize('viewClaims', $warranty);

        $claims = $warranty->claims()
            ->with('processor')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => WarrantyClaimResource::collection($claims),
        ]);
    }
}
