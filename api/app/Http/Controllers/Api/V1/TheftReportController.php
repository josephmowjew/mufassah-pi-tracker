<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeviceStatus;
use App\Enums\TheftReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TheftReport\AddEvidenceRequest;
use App\Http\Requests\Api\V1\TheftReport\AssignReportRequest;
use App\Http\Requests\Api\V1\TheftReport\StoreTheftReportRequest;
use App\Http\Requests\Api\V1\TheftReport\UpdateStatusRequest;
use App\Http\Requests\Api\V1\TheftReport\UpdateTheftReportRequest;
use App\Http\Resources\TheftReportResource;
use App\Http\Resources\TheftReportUpdateResource;
use App\Models\Device;
use App\Models\TheftReport;
use App\Models\TheftReportUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @group Theft Reports
 *
 * APIs for theft reporting - report stolen devices, track recovery, and manage status.
 */
class TheftReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TheftReport::class);

        $query = TheftReport::query()
            ->with(['device', 'reporter', 'assignee']);

        // Filter by user's own reports for non-admin users
        if (! $request->user()->hasRole(['super_admin', 'store_admin', 'customer_service'])) {
            $query->where(function ($q) use ($request) {
                $q->where('reported_by', $request->user()->id)
                    ->orWhereHas('device', fn ($d) => $d->where('owner_id', $request->user()->id));
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by device
        if ($request->has('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        // Filter open reports only
        if ($request->boolean('open_only')) {
            $query->open();
        }

        // Search by location or police report number
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('theft_location', 'like', "%{$search}%")
                    ->orWhere('police_report_number', 'like', "%{$search}%")
                    ->orWhere('police_station', 'like', "%{$search}%");
            });
        }

        $reports = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => TheftReportResource::collection($reports),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    public function store(StoreTheftReportRequest $request): JsonResponse
    {
        $this->authorize('create', TheftReport::class);

        $device = Device::findOrFail($request->device_id);

        // Verify user owns the device or is admin
        if ($device->owner_id !== $request->user()->id && ! $request->user()->hasRole(['super_admin', 'store_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'You can only report theft for your own devices.',
            ], 403);
        }

        // Check if device already has an open theft report
        $existingReport = TheftReport::where('device_id', $device->id)
            ->open()
            ->first();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'This device already has an open theft report.',
            ], 400);
        }

        return DB::transaction(function () use ($request, $device) {
            // Handle evidence photo uploads
            $evidencePhotos = [];
            if ($request->hasFile('evidence_photos')) {
                foreach ($request->file('evidence_photos') as $photo) {
                    $path = $photo->store('evidence/'.$device->id, 'public');
                    $evidencePhotos[] = $path;
                }
            }

            $report = TheftReport::create([
                'device_id' => $device->id,
                'reported_by' => $request->user()->id,
                'status' => TheftReportStatus::Reported,
                'theft_date' => $request->theft_date,
                'theft_location' => $request->theft_location,
                'theft_latitude' => $request->theft_latitude,
                'theft_longitude' => $request->theft_longitude,
                'description' => $request->description,
                'police_report_number' => $request->police_report_number,
                'police_station' => $request->police_station,
                'officer_name' => $request->officer_name,
                'officer_contact' => $request->officer_contact,
                'evidence_photos' => $evidencePhotos,
            ]);

            // Update device status to stolen
            $device->update(['status' => DeviceStatus::STOLEN]);

            // Create initial update record
            TheftReportUpdate::create([
                'theft_report_id' => $report->id,
                'user_id' => $request->user()->id,
                'old_status' => null,
                'new_status' => TheftReportStatus::Reported,
                'notes' => 'Theft report created.',
                'is_public' => true,
                'created_at' => now(),
            ]);

            $report->load(['device', 'reporter']);

            return response()->json([
                'success' => true,
                'message' => 'Theft report created successfully.',
                'data' => new TheftReportResource($report),
            ], 201);
        });
    }

    public function show(TheftReport $theftReport): JsonResponse
    {
        $this->authorize('view', $theftReport);

        $theftReport->load(['device.category', 'device.owner', 'reporter', 'assignee', 'updates.user']);

        return response()->json([
            'success' => true,
            'data' => new TheftReportResource($theftReport),
        ]);
    }

    public function update(UpdateTheftReportRequest $request, TheftReport $theftReport): JsonResponse
    {
        $this->authorize('update', $theftReport);

        $theftReport->update($request->validated());
        $theftReport->load(['device', 'reporter', 'assignee']);

        return response()->json([
            'success' => true,
            'message' => 'Theft report updated successfully.',
            'data' => new TheftReportResource($theftReport),
        ]);
    }

    public function destroy(TheftReport $theftReport): JsonResponse
    {
        $this->authorize('delete', $theftReport);

        // Only allow deletion if report is still in 'reported' status
        if ($theftReport->status !== TheftReportStatus::Reported) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a report that is already being investigated.',
            ], 400);
        }

        // Reset device status if needed
        if ($theftReport->device && $theftReport->device->status === DeviceStatus::STOLEN) {
            $theftReport->device->update(['status' => DeviceStatus::ACTIVE]);
        }

        $theftReport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Theft report deleted successfully.',
        ]);
    }

    public function updateStatus(UpdateStatusRequest $request, TheftReport $theftReport): JsonResponse
    {
        $this->authorize('updateStatus', $theftReport);

        $oldStatus = $theftReport->status;
        $newStatus = TheftReportStatus::from($request->status);

        return DB::transaction(function () use ($request, $theftReport, $oldStatus, $newStatus) {
            $updateData = ['status' => $newStatus];

            // Handle recovery-specific fields
            if ($newStatus === TheftReportStatus::Recovered) {
                $updateData['recovered_at'] = now();
                $updateData['recovery_location'] = $request->recovery_location;
                $updateData['recovery_notes'] = $request->recovery_notes;

                // Update device status back to active
                if ($theftReport->device) {
                    $theftReport->device->update(['status' => DeviceStatus::ACTIVE]);
                }
            }

            $theftReport->update($updateData);

            // Create status update record
            TheftReportUpdate::create([
                'theft_report_id' => $theftReport->id,
                'user_id' => $request->user()->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $request->notes,
                'is_public' => $request->boolean('is_public', true),
                'created_at' => now(),
            ]);

            $theftReport->load(['device', 'reporter', 'assignee']);

            return response()->json([
                'success' => true,
                'message' => 'Report status updated successfully.',
                'data' => new TheftReportResource($theftReport),
            ]);
        });
    }

    public function assign(AssignReportRequest $request, TheftReport $theftReport): JsonResponse
    {
        $this->authorize('assign', $theftReport);

        $oldStatus = $theftReport->status;

        return DB::transaction(function () use ($request, $theftReport, $oldStatus) {
            $theftReport->update([
                'assigned_to' => $request->assigned_to,
                'status' => TheftReportStatus::Investigating,
            ]);

            // Create update record
            TheftReportUpdate::create([
                'theft_report_id' => $theftReport->id,
                'user_id' => $request->user()->id,
                'old_status' => $oldStatus,
                'new_status' => TheftReportStatus::Investigating,
                'notes' => $request->notes ?? 'Report assigned for investigation.',
                'is_public' => true,
                'created_at' => now(),
            ]);

            $theftReport->load(['device', 'reporter', 'assignee']);

            return response()->json([
                'success' => true,
                'message' => 'Report assigned successfully.',
                'data' => new TheftReportResource($theftReport),
            ]);
        });
    }

    public function addEvidence(AddEvidenceRequest $request, TheftReport $theftReport): JsonResponse
    {
        $this->authorize('addEvidence', $theftReport);

        $existingPhotos = $theftReport->evidence_photos ?? [];

        if (count($existingPhotos) >= 20) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum number of evidence photos (20) reached.',
            ], 400);
        }

        $newPhotos = [];
        foreach ($request->file('photos') as $photo) {
            $path = $photo->store('evidence/'.$theftReport->device_id, 'public');
            $newPhotos[] = $path;
        }

        $theftReport->update([
            'evidence_photos' => array_merge($existingPhotos, $newPhotos),
        ]);

        return response()->json([
            'success' => true,
            'message' => count($newPhotos).' evidence photo(s) added successfully.',
            'data' => [
                'added_photos' => $newPhotos,
                'total_photos' => count($theftReport->evidence_photos),
            ],
        ]);
    }

    public function removeEvidence(Request $request, TheftReport $theftReport): JsonResponse
    {
        $this->authorize('addEvidence', $theftReport);

        $request->validate([
            'photo_path' => ['required', 'string'],
        ]);

        $photos = $theftReport->evidence_photos ?? [];
        $photoPath = $request->photo_path;

        if (! in_array($photoPath, $photos)) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found in evidence.',
            ], 404);
        }

        // Delete from storage
        Storage::disk('public')->delete($photoPath);

        // Remove from array
        $photos = array_values(array_filter($photos, fn ($p) => $p !== $photoPath));
        $theftReport->update(['evidence_photos' => $photos]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence photo removed successfully.',
        ]);
    }

    public function updates(TheftReport $theftReport): JsonResponse
    {
        $this->authorize('viewUpdates', $theftReport);

        $query = $theftReport->updates()->with('user')->latest('created_at');

        // Non-admin users only see public updates
        if (! request()->user()->hasRole(['super_admin', 'store_admin', 'customer_service'])) {
            $query->where('is_public', true);
        }

        $updates = $query->get();

        return response()->json([
            'success' => true,
            'data' => TheftReportUpdateResource::collection($updates),
        ]);
    }
}
