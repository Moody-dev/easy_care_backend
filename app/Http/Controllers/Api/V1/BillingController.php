<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BillingCommandRequest;
use App\Models\BillingAccount;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\AuditTrail;
use App\Services\BillingBalances;
use App\Services\BillingWorkflows;
use App\Services\IdempotentCommands;
use App\Support\BillingMoney;
use App\Support\FixedPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['currency' => 'NGN', 'money_decimal_places' => 2, 'rounding' => 'half_up_per_line',
            'payments_processed_by_platform' => false, 'refunds_processed_by_platform' => false,
            'methods' => ['cash','bank_transfer','pos','other'], 'independent_change_approval' => true, 'timezone' => 'Africa/Lagos']]);
    }

    public function catalog(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes','integer','min:1'], 'per_page' => ['sometimes','integer','min:1','max:100']]);
        $query = $request->route('billingService') === null ? Service::query() : ServicePrice::query()->where('service_id', $request->route('billingService'));
        $audit->record('billing.catalog_viewed', $request->user('web')->id, 'billing_catalog');

        return response()->json($query->orderByDesc('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(Request $request, BillingWorkflows $service, AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request, $service, $audit): JsonResponse {
            $patient = Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account = BillingAccount::query()->where('patient_id', $patient->id)->lockForUpdate()->first();
            $audit->record('billing.viewed', $request->user('web')->id, 'billing_account', $account?->id);
            if ($account === null) {
                return response()->json(['data' => ['id' => null, 'patient_id' => $patient->id, 'version' => 0, 'currency' => 'NGN',
                    'totals' => array_fill_keys(['charges','credited','allocated','outstanding','received','refunded','refund_reserved','available_credit','patient_charges',
                        'insurer_liability','insurer_approved','insurer_settled','insurer_reserved','insurer_unreviewed','insurer_disputed','insurer_outstanding'], '0.00'),
                    'payments_processed_by_platform' => false]]);
            }

            return $service->response($account);
        });
    }

    public function records(Request $request, BillingBalances $balances, AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request, $balances, $audit): JsonResponse {
            $data = $request->validate(['page' => ['sometimes','integer','min:1'], 'per_page' => ['sometimes','integer','min:1','max:100']]);
            Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account = BillingAccount::query()->where('patient_id', $request->route('patient'))->lockForUpdate()->firstOrFail();
            $kind = $request->route('kind');
            $table = ['invoices' => 'billing_invoices', 'receipts' => 'billing_receipts', 'changes' => 'billing_changes',
                'performed-services' => 'billing_performed_services', 'history' => 'billing_events',
                'allocations' => 'billing_allocations', 'allocation-releases' => 'billing_allocation_releases'][$kind];
            $state = in_array($kind, ['receipts','invoices'], true) ? $balances->snapshot($account) : null;
            if ($kind === 'allocations') {
                $query = DB::table($table)->join('billing_receipts as c', 'c.id', '=', $table.'.receipt_id')->where('c.account_id', $account->id)->select($table.'.*')
                    ->selectRaw('(SELECT COALESCE(SUM(r.amount_kobo),0) FROM billing_allocation_releases r WHERE r.allocation_id = billing_allocations.id) as released_kobo');
            } elseif ($kind === 'allocation-releases') {
                $query = DB::table($table)->join('billing_allocations as a', 'a.id', '=', $table.'.allocation_id')
                    ->join('billing_receipts as c', 'c.id', '=', 'a.receipt_id')->where('c.account_id', $account->id)->select($table.'.*');
            } else {
                $query = DB::table($table)->where('account_id', $account->id);
            }
            if ($kind === 'changes') {
                $query->leftJoin('billing_change_reviews as v', 'v.change_id', '=', 'billing_changes.id')
                    ->select('billing_changes.*', 'v.id as review_id', 'v.decision', 'v.actor_id as reviewed_by', 'v.recorded_at as reviewed_at',
                        'v.reason as review_reason', 'v.payout_reference', 'v.payout_at', 'v.payout_method', 'v.payee_name', 'v.payout_details', 'v.payout_confirmed');
            }
            $page = $query->orderByDesc($table.'.id')->paginate($data['per_page'] ?? 25);
            $page->through(function (\stdClass $row) use ($state, $kind): array {
                $result = $this->formatRecord($row);
                if ($state !== null) {
                    $result['balance'] = array_map(BillingMoney::format(...), $state[$kind === 'receipts' ? 'receiptData' : 'invoiceData'][$row->id]);
                }

                return $result;
            });
            $audit->record($kind === 'history' ? 'billing.history_viewed' : 'billing.viewed', $request->user('web')->id, 'billing_account', $account->id);

            return response()->json($page);
        });
    }

    public function invoice(Request $request, AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request, $audit): JsonResponse {
            Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account = BillingAccount::query()->where('patient_id', $request->route('patient'))->lockForUpdate()->firstOrFail();
            $invoice = DB::table('billing_invoices')->where('account_id', $account->id)->lockForUpdate()->find($request->route('billingInvoice'));
            abort_if($invoice === null, 404);
            $state = app(BillingBalances::class)->snapshot($account);
            $audit->record('billing.viewed', $request->user('web')->id, 'billing_account', $account->id);

            return response()->json(['data' => $this->formatRecord($invoice) + [
                'balance' => array_map(BillingMoney::format(...), $state['invoiceData'][$invoice->id]),
                'items' => $state['items']->where('invoice_id', $invoice->id)->map($this->formatRecord(...))->values()->all()]]);
        });
    }

    public function reconciliation(Request $request, BillingBalances $balances, AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request, $balances, $audit): JsonResponse {
            Patient::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('patient'));
            $account = BillingAccount::query()->where('patient_id', $request->route('patient'))->lockForUpdate()->firstOrFail();
            $state = $balances->snapshot($account);
            $issues = [];
            foreach ($state['items'] as $item) {
                try {
                    $expected = BillingMoney::charge(FixedPoint::format((int) $item->unit_price_micros), FixedPoint::format((int) $item->quantity_micros));
                    if ($expected !== (int) $item->amount_kobo) { $issues[] = ['kind' => 'invoice_item', 'id' => $item->id]; }
                } catch (\InvalidArgumentException) {
                    $issues[] = ['kind' => 'invoice_item', 'id' => $item->id];
                }
            }
            foreach ($state['invoiceData'] as $id => $row) {
                if ($row['due'] < 0 || $row['credited'] > $row['total']
                    || $row['total'] !== (int) $state['items']->where('invoice_id', $id)->sum('amount_kobo')
                    || DB::table('billing_invoice_posts')->where('invoice_id', $id)->lockForUpdate()->first() === null) {
                    $issues[] = ['kind' => 'invoice', 'id' => $id];
                }
            }
            foreach ($state['receiptData'] as $id => $row) {
                if ($row['available'] < 0) { $issues[] = ['kind' => 'receipt', 'id' => $id]; }
            }
            $audit->record('billing.reconciled', $request->user('web')->id, 'billing_account', $account->id);

            return response()->json(['data' => ['id' => $account->id, 'balanced' => $issues === [], 'issues' => $issues]]);
        });
    }

    public function sources(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate(['encounter_id' => ['required','integer','min:1'], 'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100']]);
        Gate::authorize('encounters.view');
        Patient::query()->visibleTo($request->user('web'))->findOrFail($request->route('patient'));
        $visit = Encounter::query()->visibleTo($request->user('web'))->where('patient_id', $request->route('patient'))->findOrFail($data['encounter_id']);
        $kind = $request->route('sourceKind');
        if ($kind === 'performed') {
            $query = DB::table('billing_performed_services as s')->where('s.encounter_id', $visit->id)->select('s.id','s.quantity_micros','s.occurred_at','s.service_id','s.reference');
        } elseif ($kind === 'investigation') {
            $query = DB::table('investigation_items as s')->join('investigation_tests as t', 't.id', '=', 's.test_id')
                ->where('s.encounter_id', $visit->id)->where('s.status', 'completed')->select('s.id','s.last_event_at as occurred_at','t.service_id');
        } else {
            $query = DB::table('medication_dispenses as s')->join('medication_authorizations as a', 'a.id', '=', 's.authorization_id')
                ->join('prescription_items as i', 'i.id', '=', 'a.prescription_item_id')->join('clinical_records as c', 'c.id', '=', 'i.prescription_id')
                ->where('c.encounter_id', $visit->id)->select('s.id','s.quantity_micros','s.recorded_at as occurred_at','a.drug_id');
        }
        $query->whereNotExists(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->selectRaw('1')->from('billing_invoice_items as b')->where('b.source_type', $kind)->whereColumn('b.source_id', 's.id'));
        $audit->record('billing.viewed', $request->user('web')->id, 'billing_account');

        return response()->json($query->orderBy('s.id')->paginate($data['per_page'] ?? 25)->through($this->formatRecord(...)));
    }

    private function formatRecord(\stdClass $row): array
    {
        $data = (array) $row;
        foreach (array_keys($data) as $field) {
            if ($data[$field] !== null && str_ends_with($field, '_kobo')) {
                $data[substr($field, 0, -5)] = BillingMoney::format((int) $data[$field]);
                unset($data[$field]);
            } elseif ($data[$field] !== null && str_ends_with($field, '_micros')) {
                $data[substr($field, 0, -7)] = FixedPoint::format((int) $data[$field]);
                unset($data[$field]);
            } elseif ($data[$field] !== null && in_array($field, ['externally_confirmed','payout_confirmed'], true)) {
                $data[$field] = (bool) $data[$field];
            } elseif ($data[$field] !== null && str_ends_with($field, '_at')) {
                $data[$field] = \Carbon\CarbonImmutable::parse($data[$field], 'UTC')->toISOString();
            } elseif ($data[$field] !== null && in_array($field, ['transaction_details','snapshot','payout_details'], true)) {
                $data[$field] = json_decode($data[$field], true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }

    public function write(Request $request, BillingWorkflows $service, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, fn (): JsonResponse => $service->execute($request, $request->user('web'), app(BillingCommandRequest::class)->validated()));
    }
}
