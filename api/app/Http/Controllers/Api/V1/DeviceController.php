<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeviceStatus;
use App\Http\Requests\Api\V1\Device\LinkIotModuleRequest;
use App\Http\Requests\Api\V1\Device\StoreDeviceRequest;
use App\Http\Requests\Api\V1\Device\TransferDeviceRequest;
use App\Http\Requests\Api\V1\Device\UpdateDeviceRequest;
use App\Http\Requests\Api\V1\Device\UpdateDeviceStatusRequest;
use App\Http\Resources\DeviceOwnershipHistoryResource;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceOwnershipHistory;
use App\Models\IotModule;
use App\Models\User;
use App\Support\LikePattern;
use App\Traits\PaginatesLists;
use App\Traits\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @group Devices
 *
 * APIs for managing tracked devices - registration, updates, ownership, and IoT module linking.
 */
class DeviceController extends BaseController
{
    use PaginatesLists;
    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['serial_number', 'brand', 'model', 'status', 'created_at', 'updated_at'];

    /**
     * List user's devices with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);
        $query = Device::query()
            ->with(['category', 'iotModule', 'lastLocation'])
            ->where('owner_id', $request->user()->id);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by serial number, brand, model
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw('serial_number LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('brand LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('model LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('unique_device_code LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
            });
        }

        // Sort
        $this->applySort($query, $request, self::SORTABLE, ['created_at', 'desc']);

        $devices = $query->paginate($this->perPage($request));

        return $this->paginated(DeviceResource::collection($devices));
    }

    /**
     * Register a new device.
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validated();
        $data['unique_device_code'] = generate_device_code();
        $data['owner_id'] = $request->user()->id;
        $data['status'] = DeviceStatus::ACTIVE;

        // Handle photo uploads
        if ($request->hasFile('photos')) {
            $photos = [];
            foreach ($request->file('photos') as $photo) {
                $photos[] = $photo->store('devices/photos', 'public');
            }
            $data['photos'] = $photos;
        }

        // A device without its opening ownership record is unrecoverable: the
        // history is the only trace of who it was registered to. Photos are
        // already written to storage above and are deliberately outside this —
        // files cannot be rolled back.
        $device = DB::transaction(function () use ($data, $request) {
            $device = Device::create($data);

            // Create initial ownership history
            DeviceOwnershipHistory::create([
                'device_id' => $device->id,
                'from_user_id' => null,
                'to_user_id' => $request->user()->id,
                'transfer_reason' => 'Initial registration',
                'transferred_at' => now(),
            ]);

            return $device;
        });

        $device->load(['category', 'owner']);

        return $this->created(
            new DeviceResource($device),
            'Device registered successfully.'
        );
    }

    /**
     * View a specific device.
     */
    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $device->load(['category', 'owner', 'iotModule', 'lastLocation']);

        return $this->success(new DeviceResource($device));
    }

    /**
     * Update a device.
     */
    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        return DB::transaction(function () use ($request, $device) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->authorize('update', $device);
            $device->assertNotHeldForLayby();
            $device->update($request->validated());
            $device->load(['category', 'owner', 'iotModule', 'lastLocation']);

            return $this->success(
                new DeviceResource($device),
                'Device updated successfully.'
            );
        });
    }

    /**
     * Device deletion is intentionally handled by the super-admin
     * impact-preview and PIN workflow. Keep the generic API resource route
     * closed so owners cannot bypass the inventory safeguards.
     */
    public function destroy(Device $device): JsonResponse
    {
        abort(403, 'Device deletion is only available through the super-admin deletion workflow.');
    }

    /**
     * Update device status.
     */
    public function updateStatus(UpdateDeviceStatusRequest $request, Device $device): JsonResponse
    {
        $this->authorize('updateStatus', $device);

        return DB::transaction(function () use ($request, $device) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->authorize('updateStatus', $device);
            $device->assertNotHeldForLayby();
            $device->update([
                'status' => $request->status,
                'notes' => $request->notes ?? $device->notes,
            ]);

            $device->load(['category', 'owner', 'iotModule']);

            return $this->success(
                new DeviceResource($device),
                'Device status updated successfully.'
            );
        });
    }

    /**
     * Upload photos for a device.
     */
    public function uploadPhotos(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ]);

        $existingPhotos = $device->photos ?? [];
        $newPhotos = [];

        foreach ($request->file('photos') as $photo) {
            $newPhotos[] = $photo->store('devices/photos', 'public');
        }

        // Limit total photos to 5
        $allPhotos = array_merge($existingPhotos, $newPhotos);
        if (count($allPhotos) > 5) {
            $allPhotos = array_slice($allPhotos, -5);
        }

        $device->update(['photos' => $allPhotos]);
        $device->load(['category', 'owner']);

        return $this->success(
            new DeviceResource($device),
            'Photos uploaded successfully.'
        );
    }

    /**
     * Delete a photo from a device.
     */
    public function deletePhoto(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $request->validate([
            'photo_index' => ['required', 'integer', 'min:0'],
        ]);

        $photos = $device->photos ?? [];
        $index = $request->photo_index;

        if (! isset($photos[$index])) {
            return $this->error('Photo not found.', 404);
        }

        // Delete from storage
        Storage::disk('public')->delete($photos[$index]);

        // Remove from array
        unset($photos[$index]);
        $device->update(['photos' => array_values($photos)]);

        return $this->success(null, 'Photo deleted successfully.');
    }

    /**
     * Transfer device ownership.
     */
    public function transfer(TransferDeviceRequest $request, Device $device): JsonResponse
    {
        $this->authorize('transfer', $device);

        $newOwner = User::where('email', $request->to_user_email)->firstOrFail();

        if ($newOwner->id === $device->owner_id) {
            return $this->error('Device is already owned by this user.', 400);
        }

        DB::transaction(function () use ($device, $newOwner, $request) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->authorize('transfer', $device);
            $device->assertNotHeldForLayby();
            $previousOwnerId = $device->owner_id;

            // Update device owner
            $device->update(['owner_id' => $newOwner->id]);

            // Create ownership history record
            DeviceOwnershipHistory::create([
                'device_id' => $device->id,
                'from_user_id' => $previousOwnerId,
                'to_user_id' => $newOwner->id,
                'transfer_reason' => $request->transfer_reason,
                'notes' => $request->notes,
                'transferred_at' => now(),
            ]);
        });

        $device->refresh()->load(['category', 'owner']);

        return $this->success(
            new DeviceResource($device),
            'Device transferred successfully.'
        );
    }

    /**
     * Get device ownership history.
     */
    public function ownershipHistory(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $history = DeviceOwnershipHistory::where('device_id', $device->id)
            ->with(['fromUser', 'toUser'])
            ->orderBy('transferred_at', 'desc')
            ->get();

        return $this->success(DeviceOwnershipHistoryResource::collection($history));
    }

    /**
     * Link IoT module to device.
     */
    public function linkModule(LinkIotModuleRequest $request, Device $device): JsonResponse
    {
        $this->authorize('linkModule', $device);

        $module = IotModule::findOrFail($request->iot_module_id);

        $device->update(['iot_module_id' => $module->id]);
        $device->load(['category', 'owner', 'iotModule']);

        return $this->success(
            new DeviceResource($device),
            'IoT module linked successfully.'
        );
    }

    /**
     * Unlink IoT module from device.
     */
    public function unlinkModule(Device $device): JsonResponse
    {
        $this->authorize('linkModule', $device);

        if (! $device->iot_module_id) {
            return $this->error('Device does not have an IoT module linked.', 400);
        }

        $device->update(['iot_module_id' => null]);
        $device->load(['category', 'owner']);

        return $this->success(
            new DeviceResource($device),
            'IoT module unlinked successfully.'
        );
    }
}
