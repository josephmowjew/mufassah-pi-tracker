<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Layby;
use App\Models\LaybyBranchPolicy;
use App\Services\LaybyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LaybyController extends Controller
{
    public function __construct(private readonly LaybyService $laybys) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:100',
            'status' => 'sometimes|in:active,ready,fulfilled,cancelled,returned,overdue',
        ]);
        $branchId = $request->user()->hasRole('super_admin')
            ? ($data['branch_id'] ?? null)
            : $request->user()->requiredBranchScopeId();
        $rows = Layby::query()
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when(isset($data['search']), fn (Builder $q) => $q->where(function (Builder $q) use ($data) {
                $search = '%'.$data['search'].'%';
                $q->where('reference', 'like', $search)->orWhere('customer_name', 'like', $search)->orWhere('serial_number', 'like', $search);
            }))
            ->when(isset($data['status']), function (Builder $q) use ($data) {
                if ($data['status'] === 'overdue') {
                    $q->where('status', 'active')->where('due_date', '<', now(config('app.local_timezone'))->toDateString());
                } else {
                    $q->where('status', $data['status']);
                }
            })
            ->with('entries')->latest('id')->paginate(30)->through(fn (Layby $layby) => $this->present($layby, false));

        return response()->json($rows);
    }

    public function show(Request $request, Layby $layby): JsonResponse
    {
        $this->laybys->branch($request->user(), $layby->branch_id);

        return response()->json($this->present($layby));
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'required|integer|min:1',
            'customer_id' => 'required|integer|min:1',
            'price_minor' => 'sometimes|integer|min:1|max:999999999999',
            'discount_minor' => 'sometimes|integer|min:0|max:999999999999',
            'reason' => 'required_with:price_minor|nullable|string|max:500',
        ]);
        foreach (['price_minor', 'discount_minor'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        return response()->json($this->laybys->quote($request->user(), $data));
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['quote' => 'required|string|max:12000']);

        return response()->json($this->laybys->preview($request->user(), $data['quote']));
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['serial_number' => 'required|string|max:100', 'customer' => 'required|string|max:100']);
        $actor = $request->user();
        $device = Device::query()
            ->when(! $actor->hasRole('super_admin'), fn ($query) => $query->where('branch_id', $actor->requiredBranchScopeId()))
            ->where('serial_number', $data['serial_number'])->available()->firstOrFail();
        $customers = \App\Models\User::role('customer')->where(function ($query) use ($data) {
            $query->where('name', 'like', '%'.$data['customer'].'%')
                ->orWhere('phone_number', $data['customer'])->orWhere('email', $data['customer']);
        })->orderBy('name')->limit(15)->get(['id', 'name', 'phone_number']);

        return response()->json(['device_id' => $device->id, 'customers' => $customers]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quote' => 'required|string|max:12000',
            'amount_minor' => 'required|integer|min:1|max:999999999999',
            'terms_accepted' => 'required|accepted',
            'cash_received' => 'required|accepted',
        ]);
        $data['amount_minor'] = (int) $data['amount_minor'];

        return $this->once($request, 'open', $data, fn () => $this->laybys->open($request->user(), $data), 201);
    }

    public function action(Request $request, Layby $layby, string $action): JsonResponse
    {
        $this->laybys->branch($request->user(), $layby->branch_id);
        $evidence = 'required|string|max:1000';
        $source = 'required|integer|min:1';
        $data = $request->validate(match ($action) {
            'collect' => ['amount_minor' => 'required|integer|min:1|max:999999999999',
                'tender' => 'required|in:cash,mobile_money,bank_transfer,card',
                'reference' => 'required_unless:tender,cash|nullable|string|max:150',
                'cash_received' => 'required_if:tender,cash|accepted_if:tender,cash'],
            'verify', 'reject', 'refund-approve', 'refund-claim' => ['entry_id' => $source, 'evidence' => $evidence],
            'extend' => ['due_date' => 'required|date_format:Y-m-d', 'evidence' => $evidence],
            'handover' => ['serial_number' => 'required|string|max:100', 'evidence' => $evidence, 'verified_pickup' => 'required|accepted'],
            'cancel', 'return' => ['faulty' => 'required|boolean', 'evidence' => $evidence,
                'serial_number' => ($action === 'return' ? 'required' : 'nullable').'|string|max:100',
                'physically_received' => $action === 'return' ? 'required|accepted' : 'sometimes|accepted'],
            'correct' => ['entry_id' => $source, 'reason' => 'required|in:erroneous_posting,external_reversal', 'evidence' => $evidence],
            'refund-result' => ['entry_id' => $source, 'claim_id' => $source,
                'outcome' => 'required|in:paid,failed,uncertain', 'occurred_at' => 'sometimes|date_format:Y-m-d\TH:i', 'reference' => 'required_if:outcome,paid|nullable|string|max:150', 'evidence' => $evidence],
            'fiscal-reference' => ['entry_id' => $source, 'document_type' => 'required|in:tax_invoice,credit_note',
                'reference' => 'required|string|max:150', 'evidence' => $evidence],
            default => abort(404),
        });
        foreach (['amount_minor', 'entry_id', 'claim_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $this->once($request, "{$layby->id}:{$action}", $data, fn () => $this->laybys->run($layby, $request->user(), $action, $data));
    }

    public function policy(Request $request, Branch $branch): JsonResponse
    {
        $this->laybys->branch($request->user(), $branch->id);
        $policy = LaybyBranchPolicy::find($branch->id);

        return response()->json($policy ?? [
            'branch_id' => $branch->id, 'enabled' => false, 'deposit_percent' => 20, 'term_days' => 30,
            'vat_basis_points' => 1750, 'review_reference' => null, 'merchant_channels' => [],
        ]);
    }

    public function updatePolicy(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'deposit_percent' => 'required|integer|min:1|max:99',
            'term_days' => 'required|integer|min:1|max:365',
            'vat_basis_points' => 'required|integer|min:0|max:10000',
            'review_reference' => 'nullable|string|max:255',
            'merchant_channels' => 'present|array:mobile_money,bank_transfer,card',
            'merchant_channels.*' => 'required|string|max:100',
        ]);
        abort_if($data['enabled'] && empty($data['review_reference']), 422, 'Record the finance and business approval reference first.');
        $data['merchant_channels'] = array_map(fn (string $channel) => strtoupper(trim($channel)), $data['merchant_channels']);
        $policy = LaybyBranchPolicy::updateOrCreate(['branch_id' => $branch->id], $data + ['updated_by_id' => $request->user()->id]);

        return response()->json($policy);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'sometimes|integer|min:1',
            'from' => 'required|date_format:Y-m-d',
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $branchId = $request->user()->hasRole('super_admin') ? ($data['branch_id'] ?? null) : $request->user()->requiredBranchScopeId();
        $from = \App\Support\LocalTime::startOfDayUtc($data['from']);
        $to = \App\Support\LocalTime::endOfDayUtc($data['to']);
        $entries = \App\Models\LaybyEntry::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereRaw('COALESCE(occurred_at, created_at) BETWEEN ? AND ?', [$from, $to])->whereIn('kind', ['collection', 'refund', 'correction', 'fiscal_reference', 'refund_uncertain'])
            ->orderBy('id')->paginate(100);
        $byTender = \App\Models\LaybyEntry::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereRaw('COALESCE(occurred_at, created_at) BETWEEN ? AND ?', [$from, $to])->whereIn('kind', ['collection', 'refund', 'correction'])
            ->selectRaw('branch_id, shift_id, tender, kind, SUM(amount_minor) AS amount_minor')
            ->groupBy('branch_id', 'shift_id', 'tender', 'kind')->get();
        $agreements = Layby::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $open = $refundsDue = 0;
        (clone $agreements)->whereIn('status', ['active', 'ready', 'cancelled', 'returned'])
            ->with('entries')->chunkById(100, function ($rows) use (&$open, &$refundsDue) {
                foreach ($rows as $row) {
                    if (in_array($row->status, ['active', 'ready'], true)) {
                        $open += $row->netReceived();
                    } else {
                        $refundsDue += $row->netReceived();
                    }
                }
            });
        $returns = (clone $agreements)->whereBetween('returned_at', [$from, $to])
            ->orderBy('id')->paginate(100, ['id', 'reference', 'branch_id', 'sale_id', 'total_minor', 'returned_at'], 'returns_page');

        return response()->json(['entries' => $entries, 'by_shift_and_tender' => $byTender,
            'open_deposits_minor' => $open, 'refunds_due_minor' => $refundsDue, 'returns' => $returns]);
    }

    private function once(Request $request, string $operation, array $data, callable $execute, int $status = 200): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[a-zA-Z0-9_-]{16,80}\z/', $key), 422, 'An Idempotency-Key is required.');
        $identity = ['actor_id' => $request->user()->id, 'request_key' => $key];
        $fingerprint = hash('sha256', json_encode([$operation, $data], JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($identity, $fingerprint, $execute) {
            DB::table('layby_actions')->upsert([$identity + ['fingerprint' => $fingerprint]], ['actor_id', 'request_key'], ['request_key']);
            $record = DB::table('layby_actions')->where($identity)->lockForUpdate()->first();
            abort_unless(hash_equals($record->fingerprint, $fingerprint), 409, 'This idempotency key was used with different details.');
            if ($record->result !== null) {
                $cached = json_decode($record->result, true, flags: JSON_THROW_ON_ERROR);
                $this->laybys->branch(request()->user(), $cached['branch_id']);

                return $cached;
            }

            $result = $this->present($execute()->load('entries'));
            DB::table('layby_actions')->where('id', $record->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 5);

        return response()->json($result, $status);
    }

    private function present(Layby $layby, bool $detail = true): array
    {
        $received = $layby->netReceived();
        $open = in_array($layby->status, ['active', 'ready'], true);
        $data = $layby->attributesToArray();
        unset($data['cost_minor']);
        $data['due_date'] = $layby->due_date->toDateString();
        $data['original_due_date'] = $layby->original_due_date->toDateString();

        return $data + [
            'received_minor' => $received,
            'balance_minor' => $open ? $layby->total_minor - $received : 0,
            'refund_due_minor' => in_array($layby->status, ['cancelled', 'returned'], true) ? $received : 0,
            'overdue' => $layby->isOverdue(),
            'entries' => $detail ? $layby->entries : [],
        ];
    }
}
