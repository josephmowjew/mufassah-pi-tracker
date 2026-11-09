<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Models\Layby;
use App\Models\LaybyBranchPolicy;
use App\Models\LaybyEntry;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LaybyService
{
    public function __construct(
        private readonly InventoryMovementService $movements,
        private readonly SuperAdminSaleNotificationService $saleNotifications,
    ) {}

    public function quote(User $actor, array $data): array
    {
        $device = Device::query()->findOrFail($data['device_id']);
        $policy = $this->policy($actor, $device);
        if (isset($data['price_minor']) || ($data['discount_minor'] ?? 0) > 0) {
            $this->manager($actor);
            $this->require(! empty($data['reason']), 'A pricing approval reason is required.');
        }
        $offer = $this->offer($device, $policy, $data);
        $offer['quote'] = Crypt::encrypt([
            'offer' => $offer, 'options' => $data, 'actor_id' => $actor->id,
            'expires' => now()->addMinutes(15)->timestamp,
        ]);

        unset($offer['cost_minor']);

        return $offer;
    }

    private function offer(Device $device, LaybyBranchPolicy $policy, array $options): array
    {
        $this->require($device->isInStock() || $device->inventory_status === InventoryStatus::RETURNED, 'The device is unavailable.');
        $this->require(! $device->reserved_layby_id && ! $device->reserved_invoice_id && ! $device->sale_id
            && ! $device->stock_transfer_request_id && ! $device->owner_id
            && ! in_array($device->status, [DeviceStatus::STOLEN, DeviceStatus::MAINTENANCE], true), 'The device is unavailable.');
        $device->loadMissing('inventoryBatch');
        $price = $options['price_minor'] ?? (int) round((float) $device->inventoryBatch?->target_selling_price * 100);
        $discount = $options['discount_minor'] ?? 0;
        $this->require($price > $discount, 'A positive selling price after discounts is required.');
        $tax = intdiv(($price - $discount) * $policy->vat_basis_points + 5000, 10000);
        $total = $price - $discount + $tax;
        $this->require($total <= 999999999999, 'The total exceeds the POS limit.');
        $customer = User::query()->findOrFail($options['customer_id']);
        $this->require($customer->hasRole('customer'), 'Select a customer account.');

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'device_id' => $device->id,
            'branch_id' => $device->branch_id,
            'serial_number' => $device->serial_number,
            'description' => trim("{$device->brand} {$device->model} {$device->color}"),
            'reserved_from_status' => $device->inventory_status->value,
            'price_minor' => $price,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'cost_minor' => (int) round((float) ($device->purchase_price ?: $device->inventoryBatch?->buying_price) * 100),
            'total_minor' => $total,
            'minimum_deposit_minor' => intdiv($total * $policy->deposit_percent + 99, 100),
            'vat_basis_points' => $policy->vat_basis_points,
            'deposit_percent' => $policy->deposit_percent,
            'term_days' => $policy->term_days,
            'due_date' => now(config('app.local_timezone'))->addDays($policy->term_days)->toDateString(),
            'accepted_terms' => Layby::TERMS,
        ];
    }

    public function preview(User $actor, string $token): array
    {
        [$offer] = $this->readQuote($actor, $token, false);
        unset($offer['cost_minor']);

        return $offer + ['quote' => $token];
    }

    private function readQuote(User $actor, string $token, bool $lock): array
    {
        try {
            $quote = Crypt::decrypt($token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            throw ValidationException::withMessages(['quote' => 'The quote is invalid.']);
        }
        $this->require($quote['expires'] >= now()->timestamp, 'The quote expired. Request a fresh quote.');
        $device = Device::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($quote['offer']['device_id']);
        $policy = $this->policy($actor, $device, $lock);
        if (isset($quote['options']['price_minor']) || ($quote['options']['discount_minor'] ?? 0) > 0) {
            $approver = User::query()->findOrFail($quote['actor_id']);
            $this->manager($approver);
            $this->branch($approver, $device->branch_id);
            $this->require(! $approver->isSuspended(), 'The pricing approver is suspended.');
        }
        $offer = $this->offer($device, $policy, $quote['options']);
        $this->require($offer === $quote['offer'], 'The quote changed. Review and accept a fresh quote.');

        return [$offer, $device, $quote];
    }

    public function open(User $actor, array $data): Layby
    {
        [$offer, $device, $quote] = $this->readQuote($actor, $data['quote'], true);
        $shift = $this->shift($actor, $device->branch_id);
        $amount = $data['amount_minor'];
        $minimum = $offer['minimum_deposit_minor'];
        $this->require($amount >= $minimum && $amount < $offer['total_minor'], 'The deposit must meet the minimum and leave a balance.');
        unset($offer['minimum_deposit_minor']);
        $layby = Layby::create($offer + [
            'reference' => 'LB-'.strtoupper((string) Str::ulid()),
            'opened_by_id' => $actor->id,
            'original_due_date' => $offer['due_date'],
            'status' => 'active',
        ]);
        $device->update(['inventory_status' => InventoryStatus::RESERVED, 'reserved_layby_id' => $layby->id]);
        $this->reservation($layby, $device, $actor, InventoryMovement::TYPE_RESERVATION, $layby->reserved_from_status);
        $this->entry($layby, $actor, 'collection', [
            'shift_id' => $shift->id, 'amount_minor' => $amount, 'tender' => 'cash',
            'details' => ['deposit' => true, 'quote_actor_id' => $quote['actor_id'], 'price_reason' => $quote['options']['reason'] ?? null,
                'balance_minor' => $layby->total_minor - $amount, 'due_date' => $offer['due_date']],
        ]);

        return $layby;
    }

    public function run(Layby $layby, User $actor, string $action, array $data): Layby
    {
        $layby = Layby::query()->lockForUpdate()->findOrFail($layby->id);
        $this->branch($actor, $layby->branch_id);
        $layby->load('entries');
        match ($action) {
            'collect' => $this->collect($layby, $actor, $data),
            'verify', 'reject' => $this->verify($layby, $actor, $action, $data),
            'extend' => $this->extend($layby, $actor, $data),
            'handover' => $this->handover($layby, $actor, $data),
            'cancel', 'return' => $this->reverse($layby, $actor, $action, $data),
            'correct' => $this->correct($layby, $actor, $data),
            'refund-approve', 'refund-claim', 'refund-result' => $this->refund($layby, $actor, $action, $data),
            'fiscal-reference' => $this->fiscalReference($layby, $actor, $data),
        };

        return $layby;
    }

    private function collect(Layby $layby, User $actor, array $data): void
    {
        $this->require($layby->status === 'active' && ! $layby->isOverdue(), 'The agreement must be active and within its payment term.');
        $this->heldDevice($layby);
        $shift = $this->shift($actor, $layby->branch_id);
        $amount = $data['amount_minor'];
        $tender = $data['tender'];
        $pending = $this->pendingPayments($layby);
        $this->require($amount <= $layby->total_minor - $layby->netReceived() - $pending->sum('amount_minor'), 'The payment exceeds the remaining balance.');
        if ($tender === 'cash') {
            $this->postCollection($layby, $actor, $amount, $tender, $shift->id);

            return;
        }

        $channel = LaybyBranchPolicy::find($layby->branch_id)?->merchant_channels[$tender] ?? null;
        $this->require(is_string($channel) && $channel !== '', 'The branch has no dedicated channel for this tender.');
        $this->require(! empty($data['reference']), 'The merchant transaction reference is required.');
        $reference = strtoupper(trim($data['reference']));
        $key = $this->referenceKey($tender, $channel, $reference);
        $this->require(! LaybyEntry::query()->where('reference_key', $key)->exists(), 'This transaction reference has already been used.');
        $this->require(! $pending->contains(fn (LaybyEntry $entry) => $this->referenceKey(
            $entry->tender, $entry->merchant_channel, $entry->reference
        ) === $key), 'This reference already awaits verification on this agreement.');
        $this->entry($layby, $actor, 'submitted', [
            'shift_id' => $shift->id, 'amount_minor' => $amount, 'tender' => $tender,
            'merchant_channel' => $channel, 'reference' => $reference,
        ]);
    }

    private function verify(Layby $layby, User $actor, string $action, array $data): void
    {
        $this->manager($actor);
        $source = $this->source($layby, $data['entry_id'], 'submitted');
        abort_if($source->actor_id === $actor->id, 403);
        $this->require(! $layby->entries->where('source_id', $source->id)->whereIn('kind', ['collection', 'rejected'])->count(), 'This submission was already reviewed.');
        if ($action === 'reject') {
            $this->entry($layby, $actor, 'rejected', ['source_id' => $source->id, 'evidence' => $data['evidence']]);

            return;
        }

        $this->require($layby->status === 'active', 'The agreement is not accepting payments.');
        $this->heldDevice($layby);
        $this->require($source->amount_minor <= $layby->total_minor - $layby->netReceived(), 'The verified payment exceeds the remaining balance. Reconcile it with the merchant before rejecting the submission.');
        $this->postCollection($layby, $actor, $source->amount_minor, $source->tender, $source->shift_id, $source, $data['evidence']);
    }

    private function postCollection(Layby $layby, User $actor, int $amount, string $tender, int $shiftId, ?LaybyEntry $source = null, ?string $evidence = null): void
    {
        $balance = $layby->total_minor - $layby->netReceived() - $amount;
        $this->entry($layby, $actor, 'collection', [
            'amount_minor' => $amount, 'tender' => $tender, 'shift_id' => $shiftId,
            'source_id' => $source?->id, 'reference' => $source?->reference, 'merchant_channel' => $source?->merchant_channel,
            // Only verified collections claim a reference. Rejection allows an incorrectly filed submission to be corrected.
            'reference_key' => $source ? $this->referenceKey($tender, $source->merchant_channel, $source->reference) : null,
            'evidence' => $evidence, 'occurred_at' => $source?->created_at ?? now(),
            'details' => ['balance_minor' => $balance, 'due_date' => $layby->due_date->toDateString()],
        ]);
        if ($balance === 0) {
            $layby->update(['status' => 'ready']);
        }
    }

    private function extend(Layby $layby, User $actor, array $data): void
    {
        $this->manager($actor);
        $this->require($layby->status === 'active', 'Only an active agreement can be extended.');
        $this->require($data['due_date'] > max($layby->due_date->toDateString(), now(config('app.local_timezone'))->toDateString()), 'The new deadline must be later than both the current deadline and today.');
        $this->entry($layby, $actor, 'extension', ['evidence' => $data['evidence'],
            'details' => ['from' => $layby->due_date->toDateString(), 'to' => $data['due_date']]]);
        $layby->update(['due_date' => $data['due_date']]);
    }

    private function handover(Layby $layby, User $actor, array $data): void
    {
        $this->require($layby->status === 'ready' && $layby->netReceived() === $layby->total_minor, 'A fully settled agreement is required.');
        $this->require($this->pendingPayments($layby)->isEmpty(), 'Resolve submitted payments before pickup.');
        $device = $this->heldDevice($layby);
        $shift = $this->shift($actor, $layby->branch_id);
        $this->require($device->status !== DeviceStatus::STOLEN && $device->status !== DeviceStatus::MAINTENANCE
            && $device->serial_number === $layby->serial_number && $device->serial_number === $data['serial_number'], 'The device serial does not match.');
        $this->require(User::query()->whereKey($layby->customer_id)->exists(), 'The customer account needs to be restored before pickup.');
        $sale = Sale::create([
            'sale_number' => $layby->reference,
            'customer_id' => $layby->customer_id, 'branch_id' => $layby->branch_id,
            'sold_by_id' => $actor->id, 'shift_id' => $shift->id, 'sale_date' => now(),
            'payment_method' => null, 'payment_status' => PaymentStatus::Completed, 'status' => SaleStatus::COMPLETED,
            'subtotal' => $this->decimal($layby->price_minor), 'tax_amount' => $this->decimal($layby->tax_minor),
            'discount_amount' => $this->decimal($layby->discount_minor), 'total_amount' => $this->decimal($layby->total_minor),
            'vat_applicable' => $layby->vat_basis_points > 0,
        ]);
        $item = $sale->items()->create([
            'device_id' => $device->id, 'item_description' => "{$layby->description} ({$layby->serial_number})",
            'unit_price' => $this->decimal($layby->price_minor), 'selling_price' => $this->decimal($layby->price_minor),
            'discount' => $this->decimal($layby->discount_minor), 'tax' => $this->decimal($layby->tax_minor),
            'total_price' => $this->decimal($layby->total_minor), 'cost_price' => $this->decimal($layby->cost_minor),
            'profit_amount' => $this->decimal($layby->price_minor - $layby->discount_minor - $layby->cost_minor),
        ]);
        $device->update(['reserved_layby_id' => null, 'sale_id' => $sale->id, 'inventory_status' => InventoryStatus::SOLD,
            'owner_id' => $layby->customer_id, 'sold_at' => $sale->sale_date, 'sold_by_id' => $actor->id]);
        $this->movements->recordSale($device, $sale, $item, $actor, InventoryStatus::RESERVED->value);
        $this->entry($layby, $actor, 'handover', ['shift_id' => $shift->id, 'evidence' => $data['evidence'],
            'details' => ['tenders' => $layby->tenderBreakdown()]]);
        $layby->update(['status' => 'fulfilled', 'sale_id' => $sale->id, 'handed_over_at' => $sale->sale_date]);
        DB::afterCommit(fn () => rescue(fn () => $this->saleNotifications->notifyPosSale($sale)));
    }

    private function reverse(Layby $layby, User $actor, string $action, array $data): void
    {
        $this->manager($actor);
        if ($action === 'cancel') {
            $this->require(in_array($layby->status, ['active', 'ready'], true), 'Only an unfulfilled agreement can be cancelled.');
            $this->require($this->pendingPayments($layby)->isEmpty(), 'Resolve submitted payments before cancellation.');
            $device = $this->heldDevice($layby);
            $device->update(['reserved_layby_id' => null,
                'inventory_status' => $data['faulty'] || in_array($device->status, [DeviceStatus::STOLEN, DeviceStatus::MAINTENANCE], true)
                    ? InventoryStatus::FAULTY : $layby->reserved_from_status]);
            $this->reservation($layby, $device, $actor, InventoryMovement::TYPE_RESERVATION_CANCELLED, InventoryStatus::RESERVED->value);
            $layby->update(['status' => 'cancelled']);
        } else {
            $this->require($layby->status === 'fulfilled', 'Only a fulfilled layby can be returned.');
            $sale = Sale::query()->lockForUpdate()->findOrFail($layby->sale_id);
            $device = Device::query()->lockForUpdate()->findOrFail($layby->device_id);
            $this->require($device->serial_number === $data['serial_number'] && $device->serial_number === $layby->serial_number
                && $device->sale_id === $sale->id && $device->isSold() && $device->owner_id === $layby->customer_id,
                'The serial or device ownership does not match the fulfilled agreement.');
            $device->update(['sale_id' => null, 'owner_id' => null, 'sold_at' => null, 'sold_by_id' => null,
                'inventory_status' => $data['faulty'] || in_array($device->status, [DeviceStatus::STOLEN, DeviceStatus::MAINTENANCE], true)
                    ? InventoryStatus::FAULTY : InventoryStatus::RETURNED]);
            $this->movements->recordRefund($device, $sale, $sale->items()->firstOrFail(), $actor, $data['evidence'], $device->inventory_status);
            $layby->update(['status' => 'returned', 'returned_at' => now()]);
            $sale->update(['status' => SaleStatus::REFUNDED, 'payment_status' => PaymentStatus::RefundProcessing, 'refund_reason' => $data['evidence']]);
        }
        $this->entry($layby, $actor, $action, ['evidence' => $data['evidence'], 'details' => ['faulty' => $data['faulty']]]);
    }

    private function correct(Layby $layby, User $actor, array $data): void
    {
        $this->manager($actor);
        $source = $this->source($layby, $data['entry_id'], 'collection');
        abort_if($source->actor_id === $actor->id, 403, 'A different supervisor must approve the correction.');
        $state = $layby->entries->where('source_id', $source->id)->last(
            fn (LaybyEntry $entry) => $entry->kind === 'correction' || str_starts_with($entry->kind, 'refund')
        );
        $this->require($state === null || in_array($state->kind, ['refund_approved', 'refund_failed'], true),
            'The payment was already corrected or has a paid or unresolved refund attempt.');
        $this->entry($layby, $actor, 'correction', [
            'source_id' => $source->id, 'amount_minor' => $source->amount_minor, 'tender' => $source->tender,
            'shift_id' => $data['reason'] === 'erroneous_posting' ? $source->shift_id : null,
            'evidence' => $data['evidence'], 'details' => ['reason' => $data['reason']],
        ]);
        if ($layby->status === 'ready') {
            $layby->update(['status' => 'active']);
        }
        $this->settleReturn($layby);
    }

    private function refund(Layby $layby, User $actor, string $action, array $data): void
    {
        $this->require(in_array($layby->status, ['cancelled', 'returned'], true), 'A cancellation or return is required before refunding.');
        $source = $this->source($layby, $data['entry_id'], 'collection');
        $history = $layby->entries->where('source_id', $source->id)->whereIn('kind',
            ['correction', 'refund_approved', 'refund_claimed', 'refund_uncertain', 'refund_failed', 'refund'])->values();
        $approval = $history->firstWhere('kind', 'refund_approved');
        $last = $history->last();
        $attributes = ['source_id' => $source->id, 'amount_minor' => $source->amount_minor, 'tender' => $source->tender,
            'evidence' => $data['evidence'], 'merchant_channel' => $source->merchant_channel];

        if ($action === 'refund-approve') {
            $this->manager($actor);
            $this->require($last === null, 'This payment already has a refund decision or correction.');
            $this->entry($layby, $actor, 'refund_approved', $attributes);

            return;
        }

        $this->require($approval !== null && in_array($last?->kind, ['refund_approved', 'refund_failed', 'refund_claimed', 'refund_uncertain'], true),
            'There is no open refund for this payment.');
        abort_if($approval->actor_id === $actor->id, 403, 'Approval and payout require different staff members.');
        if ($action === 'refund-claim') {
            $this->require(in_array($last->kind, ['refund_approved', 'refund_failed'], true), 'Reconcile the existing payout attempt before retrying.');
            $this->entry($layby, $actor, 'refund_claimed', $attributes + ['shift_id' => $this->payoutShift($layby, $actor, $source)]);

            return;
        }

        $claim = $history->last(fn (LaybyEntry $entry) => $entry->kind === 'refund_claimed');
        $this->require(in_array($last->kind, ['refund_claimed', 'refund_uncertain'], true) && $claim && $claim->id === $data['claim_id'], 'Claim the payout before recording its result.');
        if ($last->kind === 'refund_uncertain' || $claim->actor_id !== $actor->id || $data['outcome'] === 'failed') {
            $this->manager($actor);
            abort_if($claim->actor_id === $actor->id, 403, 'A different supervisor must reconcile the payout.');
        }
        if ($data['outcome'] === 'paid' && $source->tender === 'cash' && $actor->id === $claim->actor_id) {
            $this->require($this->shift($actor, $layby->branch_id)->id === $claim->shift_id,
                'A supervisor must reconcile a payout after its claim shift closes.');
        }
        $kind = match ($data['outcome']) {
            'paid' => 'refund',
            'failed' => 'refund_failed',
            'uncertain' => 'refund_uncertain',
        };
        $reference = strtoupper(trim($data['reference'] ?? ''));
        $this->require($kind !== 'refund' || $reference !== '', 'A payout receipt or merchant transaction reference is required.');
        $occurredAt = isset($data['occurred_at'])
            ? \Illuminate\Support\Carbon::parse($data['occurred_at'], config('app.local_timezone'))->utc()
            : now();
        if ($kind === 'refund') {
            $this->require($occurredAt->betweenIncluded($claim->created_at, now()), 'Payout time must be between the claim and now.');
            if ($claim->shift_id) {
                $claimShift = Shift::query()->findOrFail($claim->shift_id);
                $this->require($occurredAt->betweenIncluded($claimShift->started_at, $claimShift->ended_at ?? now()),
                    'The payout time must fall inside the claimed cash shift.');
            }
        }
        $this->entry($layby, $actor, $kind, $attributes + [
            'occurred_at' => $kind === 'refund' ? $occurredAt : null,
            'shift_id' => $claim->shift_id, 'reference' => $reference ?: null,
            'reference_key' => $kind === 'refund' ? $this->referenceKey($source->tender, $source->merchant_channel ?? "cash:{$layby->branch_id}", $reference) : null,
            'details' => ['claim_id' => $claim->id],
        ]);
        $this->settleReturn($layby);
    }

    private function settleReturn(Layby $layby): void
    {
        if ($layby->status === 'returned' && $layby->load('entries')->netReceived() === 0) {
            Sale::query()->whereKey($layby->sale_id)->update(['payment_status' => PaymentStatus::Refunded]);
        }
    }

    private function fiscalReference(Layby $layby, User $actor, array $data): void
    {
        $this->manager($actor);
        $source = $layby->entries->firstWhere('id', $data['entry_id']);
        $this->require($source && in_array($source->kind, ['collection', 'refund', 'correction', 'handover', 'return'], true), 'Select a fiscal source entry.');
        $reference = strtoupper(trim($data['reference']));
        $this->entry($layby, $actor, 'fiscal_reference', [
            'source_id' => $source->id, 'reference' => $reference, 'evidence' => $data['evidence'],
            'details' => ['document_type' => $data['document_type']],
        ]);
    }

    private function pendingPayments(Layby $layby): \Illuminate\Support\Collection
    {
        $reviewed = $layby->entries->whereIn('kind', ['collection', 'rejected'])->pluck('source_id');

        return $layby->entries->where('kind', 'submitted')->whereNotIn('id', $reviewed);
    }

    private function source(Layby $layby, int $id, string $kind): LaybyEntry
    {
        $entry = $layby->entries->firstWhere('id', $id);
        abort_unless($entry && $entry->kind === $kind, 404);

        return $entry;
    }

    private function entry(Layby $layby, User $actor, string $kind, array $attributes): void
    {
        try {
            $layby->entries()->create($attributes + ['kind' => $kind, 'actor_id' => $actor->id, 'branch_id' => $layby->branch_id]);
        } catch (QueryException $exception) {
            if (isset($attributes['reference_key']) && $exception instanceof \Illuminate\Database\UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['reference' => 'This transaction reference has already been used.']);
            }
            throw $exception;
        }
    }

    private function policy(User $actor, Device $device, bool $lock = false): LaybyBranchPolicy
    {
        $this->require($device->branch_id !== null, 'The device must belong to a branch.');
        $this->branch($actor, $device->branch_id);
        $policy = LaybyBranchPolicy::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($device->branch_id);
        $this->require($policy?->enabled && $policy->review_reference && Branch::query()->whereKey($device->branch_id)->where('is_active', true)->exists(),
            'This branch is not approved and enabled for new layby agreements.');

        return $policy;
    }

    public function branch(User $actor, int $branchId): void
    {
        abort_unless($actor->canAccessBranch($branchId), 403);
    }

    private function manager(User $actor): void
    {
        abort_unless($actor->hasAnyRole(['store_admin', 'super_admin']), 403);
    }

    private function shift(User $actor, int $branchId): Shift
    {
        abort_unless($actor->hasRole('customer_service'), 403, 'An on-shift branch cashier is required.');
        $shift = $actor->activeShift()->lockForUpdate()->first();
        $this->require($shift && $shift->branch_id === $branchId && ! $shift->isDueToEnd(now()) && ! $shift->openBreak()->exists(),
            'An active shift at the originating branch is required.');

        return $shift;
    }

    private function payoutShift(Layby $layby, User $actor, LaybyEntry $source): ?int
    {
        if ($source->tender === 'cash') {
            return $this->shift($actor, $layby->branch_id)->id;
        }
        $this->manager($actor);

        return null;
    }

    private function heldDevice(Layby $layby): Device
    {
        $device = Device::query()->lockForUpdate()->findOrFail($layby->device_id);
        $this->require($device->reserved_layby_id === $layby->id && $device->inventory_status === InventoryStatus::RESERVED
            && $device->branch_id === $layby->branch_id && ! $device->sale_id && ! $device->reserved_invoice_id
            && ! $device->stock_transfer_request_id && ! $device->owner_id, 'The device is no longer held for this agreement.');

        return $device;
    }

    private function reservation(Layby $layby, Device $device, User $actor, string $kind, string $previous): void
    {
        $this->movements->recordDeviceMovement($device, [
            'movement_type' => $kind, 'previous_status' => $previous, 'new_status' => $device->inventory_status->value,
            'reference_type' => Layby::class, 'reference_id' => $layby->id, 'created_by_id' => $actor->id,
            'metadata' => ['layby_reference' => $layby->reference],
        ]);
    }

    private function referenceKey(string $tender, string $channel, string $reference): string
    {
        return hash('sha256', json_encode([$tender, strtoupper(trim($channel)), preg_replace('/\s+/u', '', $reference)], JSON_THROW_ON_ERROR));
    }

    private function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['layby' => $message]);
        }
    }
}
