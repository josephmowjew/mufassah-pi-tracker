<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IotModule\StoreIotModuleRequest;
use App\Http\Requests\Api\V1\IotModule\UpdateIotModuleRequest;
use App\Http\Resources\IotModuleResource;
use App\Models\IotModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group IoT Modules
 *
 * APIs for IoT module management - register and configure tracking hardware.
 */
class IotModuleController extends BaseController
{
    /**
     * List all IoT modules (admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IotModule::class);
        $query = IotModule::query()->with('device');
        if (! $request->user()->hasRole('super_admin')) {
            $branchId = $request->user()->requiredBranchScopeId();
            $query->whereHas('device', fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by modem type
        if ($request->has('modem_type')) {
            $query->where('modem_type', $request->modem_type);
        }

        // Filter by online status
        if ($request->has('online')) {
            if ($request->boolean('online')) {
                $query->online();
            } else {
                $query->where(function ($q) {
                    $q->whereNull('last_ping')
                        ->orWhere('last_ping', '<', now()->subMinutes(5));
                });
            }
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('module_id', 'like', "%{$search}%")
                    ->orWhere('sim_number', 'like', "%{$search}%")
                    ->orWhere('imei', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $modules = $query->paginate($request->get('per_page', 15));

        return $this->paginated(IotModuleResource::collection($modules));
    }

    /**
     * View a specific IoT module.
     */
    public function show(IotModule $iotModule): JsonResponse
    {
        $this->authorize('view', $iotModule);

        $iotModule->load('device');

        return $this->success(new IotModuleResource($iotModule));
    }

    /**
     * Register a new IoT module (admin only).
     */
    public function store(StoreIotModuleRequest $request): JsonResponse
    {
        $this->authorize('create', IotModule::class);

        $data = $request->validated();

        // Generate API token for the module
        $data['api_token'] = Str::random(64);
        $data['status'] = \App\Enums\IotModuleStatus::Inactive;

        $module = IotModule::create($data);

        // Return the plain token only on creation
        return $this->created([
            'module' => new IotModuleResource($module),
            'api_token' => $data['api_token'], // Only visible once!
        ], 'IoT module registered successfully. Save the API token - it will not be shown again.');
    }

    /**
     * Update an IoT module (admin only).
     */
    public function update(UpdateIotModuleRequest $request, IotModule $iotModule): JsonResponse
    {
        $this->authorize('update', $iotModule);

        $iotModule->update($request->validated());
        $iotModule->load('device');

        return $this->success(
            new IotModuleResource($iotModule),
            'IoT module updated successfully.'
        );
    }

    /**
     * Delete an IoT module (admin only).
     */
    public function destroy(IotModule $iotModule): JsonResponse
    {
        $this->authorize('delete', $iotModule);

        // Check if module is linked to a device
        if ($iotModule->device) {
            return $this->error(
                'Cannot delete module that is linked to a device. Unlink it first.',
                400
            );
        }

        $iotModule->delete();

        return $this->success(null, 'IoT module deleted successfully.');
    }

    /**
     * Regenerate API token for an IoT module.
     */
    public function regenerateToken(IotModule $iotModule): JsonResponse
    {
        $this->authorize('update', $iotModule);

        $newToken = Str::random(64);
        $iotModule->update(['api_token' => $newToken]);

        return $this->success([
            'api_token' => $newToken,
        ], 'API token regenerated successfully. Save it - it will not be shown again.');
    }

    /**
     * Activate an IoT module.
     */
    public function activate(IotModule $iotModule): JsonResponse
    {
        $this->authorize('update', $iotModule);

        $iotModule->update([
            'status' => \App\Enums\IotModuleStatus::Active,
            'activated_at' => $iotModule->activated_at ?? now(),
        ]);

        return $this->success(
            new IotModuleResource($iotModule),
            'IoT module activated successfully.'
        );
    }

    /**
     * Deactivate an IoT module.
     */
    public function deactivate(IotModule $iotModule): JsonResponse
    {
        $this->authorize('update', $iotModule);

        $iotModule->update([
            'status' => \App\Enums\IotModuleStatus::Inactive,
        ]);

        return $this->success(
            new IotModuleResource($iotModule),
            'IoT module deactivated successfully.'
        );
    }
}
