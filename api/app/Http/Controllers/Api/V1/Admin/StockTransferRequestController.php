<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\StockTransferRequestResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\StockTransferRequest;
use App\Models\User;
use App\Notifications\StockTransferDecisionNotification;
use App\Notifications\StockTransferRequestNotification;
use App\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferRequestController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = StockTransferRequest::with(['fromBranch', 'toBranch', 'requestedBy', 'decidedBy'])->withCount('items')->latest();
        if (! $user->hasRole('super_admin')) $query->where(fn ($q) => $q->where('from_branch_id', $user->branch_id)->orWhere('to_branch_id', $user->branch_id));
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->string('direction')->toString() === 'incoming') $query->where('to_branch_id', $user->branch_id);
        if ($request->string('direction')->toString() === 'outgoing') $query->where('from_branch_id', $user->branch_id);
        return $this->paginated(StockTransferRequestResource::collection($query->paginate(min((int) $request->get('per_page', 15), 100))));
    }

    public function show(Request $request, StockTransferRequest $transferRequest): JsonResponse
    {
        $this->viewable($request->user(), $transferRequest);
        return $this->success(new StockTransferRequestResource($transferRequest->load(['fromBranch', 'toBranch', 'requestedBy', 'decidedBy', 'items.device.category'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['device_ids'=>'required|array|min:1|max:100','device_ids.*'=>'integer|distinct|exists:devices,id','to_branch_id'=>'required|integer|exists:branches,id','reason'=>'required|string|max:255','notes'=>'nullable|string|max:1000','from_branch_id'=>'nullable|integer|exists:branches,id']);
        return DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $devices = Device::whereIn('id', $data['device_ids'])->lockForUpdate()->get();
            $fromBranchId = $user->hasRole('super_admin') ? (int) ($data['from_branch_id'] ?? $devices->first()?->branch_id) : (int) $user->branch_id;
            if (! $fromBranchId || $devices->count() !== count($data['device_ids']) || $devices->contains(fn ($d) => (int) $d->branch_id !== $fromBranchId)) throw ValidationException::withMessages(['device_ids'=>['Devices must all belong to the source branch.']]);
            $destination = Branch::with('manager.roles')->findOrFail($data['to_branch_id']);
            if (! $destination->is_active || $fromBranchId === (int) $destination->id) throw ValidationException::withMessages(['to_branch_id'=>['Choose a different active destination branch.']]);
            if (! $destination->manager || (! $destination->manager->hasRole('store_admin') && ! $destination->manager->hasRole('super_admin'))) throw ValidationException::withMessages(['to_branch_id'=>['The destination branch needs an assigned store or super admin manager.']]);
            foreach ($devices as $device) if ($device->stock_transfer_request_id || ! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true)) throw ValidationException::withMessages(['device_ids'=>["{$device->serial_number} is no longer available for transfer."]]);
            $transfer = StockTransferRequest::create(['request_number'=>StockTransferRequest::nextNumber(),'from_branch_id'=>$fromBranchId,'to_branch_id'=>$destination->id,'requested_by_id'=>$user->id,'reason'=>$data['reason'],'notes'=>$data['notes'] ?? null]);
            foreach ($devices as $device) { $transfer->items()->create(['device_id'=>$device->id]); $device->update(['stock_transfer_request_id'=>$transfer->id]); }
            $transfer->load(['fromBranch','toBranch','requestedBy'])->loadCount('items');
            $recipients = User::role('super_admin')->get()->push($destination->manager)->unique('id')->reject(fn ($recipient) => $recipient->id === $user->id);
            foreach ($recipients as $recipient) $recipient->notify(new StockTransferRequestNotification($transfer));
            return $this->success(new StockTransferRequestResource($transfer), 'Stock transfer request created.', 201);
        });
    }

    public function approve(Request $request, StockTransferRequest $transferRequest, InventoryMovementService $movementService): JsonResponse
    {
        return DB::transaction(function () use ($request, $transferRequest, $movementService) {
            $transfer = StockTransferRequest::whereKey($transferRequest->id)->lockForUpdate()->firstOrFail(); $this->approvable($request->user(), $transfer);
            if ($transfer->status !== StockTransferRequest::PENDING) throw ValidationException::withMessages(['request'=>['This request is no longer pending.']]);
            $devices = Device::whereIn('id', $transfer->items()->pluck('device_id'))->lockForUpdate()->get();
            if ($devices->contains(fn ($d) => $d->stock_transfer_request_id !== $transfer->id || (int) $d->branch_id !== (int) $transfer->from_branch_id || ! in_array($d->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true))) throw ValidationException::withMessages(['request'=>['One or more devices are no longer eligible.']]);
            foreach ($devices as $device) { $device->update(['branch_id'=>$transfer->to_branch_id,'stock_transfer_request_id'=>null]); $movementService->recordTransfer($device->fresh(), $transfer->from_branch_id, $transfer->to_branch_id, $request->user(), $transfer->reason, $transfer->notes); }
            $transfer->update(['status'=>StockTransferRequest::APPROVED,'decided_by_id'=>$request->user()->id,'decided_at'=>now()]);
            $transfer->requestedBy->notify(new StockTransferDecisionNotification($transfer));
            return $this->success(new StockTransferRequestResource($transfer->fresh()->load(['fromBranch','toBranch','requestedBy','decidedBy'])->loadCount('items')), 'Stock transfer approved.');
        });
    }

    public function reject(Request $request, StockTransferRequest $transferRequest): JsonResponse { return $this->close($request, $transferRequest, StockTransferRequest::REJECTED, true); }
    public function cancel(Request $request, StockTransferRequest $transferRequest): JsonResponse { return $this->close($request, $transferRequest, StockTransferRequest::CANCELLED, false); }
    private function close(Request $request, StockTransferRequest $transferRequest, string $status, bool $approval): JsonResponse {
        $data=$request->validate(['reason'=>$approval?'required|string|max:1000':'nullable|string|max:1000']);
        return DB::transaction(function () use ($request,$transferRequest,$status,$approval,$data) { $transfer=StockTransferRequest::whereKey($transferRequest->id)->lockForUpdate()->firstOrFail(); if($approval) $this->approvable($request->user(),$transfer); elseif($transfer->requested_by_id!==$request->user()->id) abort(403,'Only the requester can cancel this request.'); if($transfer->status!==StockTransferRequest::PENDING) throw ValidationException::withMessages(['request'=>['This request is no longer pending.']]); Device::where('stock_transfer_request_id',$transfer->id)->update(['stock_transfer_request_id'=>null]); $transfer->update(['status'=>$status,'decided_by_id'=>$request->user()->id,'decided_at'=>now(),'decision_reason'=>$data['reason']??null]); if($transfer->requested_by_id!==$request->user()->id) $transfer->requestedBy->notify(new StockTransferDecisionNotification($transfer)); return $this->success(new StockTransferRequestResource($transfer->fresh()->load(['fromBranch','toBranch','requestedBy','decidedBy'])->loadCount('items')), "Stock transfer {$status}."); });
    }
    private function viewable(User $user, StockTransferRequest $transfer): void { if(! $user->hasRole('super_admin') && ! in_array((int)$user->branch_id,[(int)$transfer->from_branch_id,(int)$transfer->to_branch_id],true)) abort(403); }
    private function approvable(User $user, StockTransferRequest $transfer): void { if($user->hasRole('super_admin')) return; if($user->hasRole('store_admin') && (int)$transfer->toBranch->manager_id === (int)$user->id) return; abort(403,'Only the destination branch manager can approve this request.'); }
}
