<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\BillingAccount;
use App\Models\DrugPrice;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use App\Support\BillingMoney;
use App\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class BillingWorkflows
{
    public const PERMISSIONS = ['service' => 'billing.catalog.manage', 'price' => 'billing.catalog.manage', 'performed' => 'billing.sources.record',
        'invoice' => 'billing.invoices.post', 'receipt' => 'billing.receipts.record', 'allocate' => 'billing.allocate',
        'credit-request' => 'billing.credits.request', 'refund-request' => 'billing.refunds.request', 'approve' => 'billing.changes.approve', 'reject' => 'billing.changes.approve'];

    public function __construct(private StockAuthority $authority, private BillingBalances $balances, private AuditTrail $audit) {}

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public static function isCommand(string $route): bool
    {
        return str_starts_with($route, 'api.v1.billing.') && isset(self::PERMISSIONS[substr($route, strrpos($route, '.') + 1)]);
    }

    public static function isCatalog(Request $request): bool
    {
        return in_array(self::action($request), ['service', 'price'], true);
    }

    public function authorize(Request $request, User $actor): void
    {
        $this->authority->permission($actor, self::PERMISSIONS[self::action($request)]);
        $this->authority->permission($actor, self::isCatalog($request) ? 'billing.catalog.view' : 'billing.view');
        $this->authority->password($request, $actor);
        if (self::isCatalog($request)) {
            return;
        }
        $this->authority->permission($actor, 'patients.view');
        $patient = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('patient'));
        $account = BillingAccount::query()->where('patient_id', $patient->id)->lockForUpdate()->first();
        $request->attributes->set('billing_patient', $patient);
        $request->attributes->set('billing_account', $account);
        if (in_array(self::action($request), ['approve', 'reject'], true)) {
            $change = DB::table('billing_changes')->where('account_id', $account?->id)->lockForUpdate()->find($request->route('billingChange'));
            abort_if($change === null, 404);
            abort_if($change->actor_id === $actor->id, 403);
        }
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): JsonResponse
    {
        $action = self::action($request);
        if (self::isCatalog($request)) {
            return $this->catalog($request, $actor, $data);
        }
        $account = $request->attributes->get('billing_account');
        $last = $account === null ? null : DB::table('billing_events')->where('account_id', $account->id)->orderByDesc('version')->lockForUpdate()->first();
        $this->authority->version((int) ($last->version ?? 0), $data['account_version']);
        $time = $this->time($last?->recorded_at);
        $signature = ['actor_id' => $actor->id, 'recorded_at' => $time->format('Y-m-d H:i:s.u'), 'reason' => $data['reason']];
        if ($account === null) {
            $account = new BillingAccount;
            $account->forceFill(['patient_id' => $request->attributes->get('billing_patient')->id, 'currency' => 'NGN'] + $signature)->save();
        }
        $state = $this->balances->snapshot($account);
        if ($action === 'performed' || $action === 'invoice') {
            $this->authority->permission($actor, 'encounters.view');
            $visit = Encounter::query()->visibleTo($actor)->where('patient_id', $account->patient_id)->lockForUpdate()->findOrFail($data['encounter_id']);
            $this->authority->version($visit->version, $data['encounter_version']);
        }
        if ($action === 'performed') {
            $service = Service::query()->lockForUpdate()->findOrFail($data['service_id']);
            if (in_array($service->category, ['laboratory', 'radiology'], true)) {
                $this->invalid('service_id', 'Investigation charges must use their completed investigation item, not a duplicate manual service.');
            }
            $observed = CarbonImmutable::parse($data['occurred_at'])->utc();
            if ($observed->greaterThan($time) || $observed->lessThan($visit->opened_at)
                || $visit->closed_at !== null && $observed->greaterThan($visit->closed_at) || $visit->status === 'cancelled') {
                $this->invalid('occurred_at', 'Confirm a service actually performed during this visit, no later than now.');
            }
            $quantity = $this->authority->decimal($data['quantity'], 'quantity', true);
            $referenceKey = hash('sha256', mb_strtoupper(trim($data['reference'])));
            if (DB::table('billing_performed_services')->where('encounter_id', $visit->id)->where('reference_key', $referenceKey)->lockForUpdate()->first() !== null) {
                throw new WriteConflict('billing_source_exists', 'This visit already has that performed-service reference.');
            }
            $recordId = DB::table('billing_performed_services')->insertGetId(['account_id' => $account->id, 'encounter_id' => $visit->id,
                'service_id' => $service->id, 'quantity_micros' => $quantity, 'occurred_at' => $observed->format('Y-m-d H:i:s.u'),
                'reference' => $data['reference'], 'reference_key' => $referenceKey] + $signature);
        } elseif ($action === 'invoice') {
            $lines = [];
            foreach ($data['sources'] as $source) {
                $lines[] = $this->source($account, $visit, $source['type'], (int) $source['id'], $time, $source['price_id']);
            }
            $recordId = $this->postInvoice($account, $visit->id, $lines, $signature);
        } elseif ($action === 'receipt') {
            $amount = $this->amount($data['amount']);
            $this->range($state['totals']['received'] + $amount);
            $method = $data['method'];
            $details = $data['transaction_details'] ?? [];
            $reference = $data['reference'] ?? null;
            if ($method !== 'cash' && (empty($reference) || empty($details['provider']) || ! ($data['externally_confirmed'] ?? false))) {
                $this->invalid('reference', 'Record the externally confirmed transaction reference and provider.');
            }
            if ($method === 'other' && empty($details['method_name'])) {
                $this->invalid('transaction_details.method_name', 'Describe the external payment method.');
            }
            if ($method === 'cash' && ($data['externally_confirmed'] ?? false)) {
                $this->invalid('externally_confirmed', 'Cash is recorded directly by the cashier.');
            }
            $paid = CarbonImmutable::parse($data['paid_at'])->utc();
            if ($paid->greaterThan($time)) {
                $this->invalid('paid_at', 'The actual external transaction cannot be in the future.');
            }
            $key = $reference === null ? null : hash('sha256', $method.'|'.mb_strtoupper(trim($details['provider'] ?? '')).'|'.mb_strtoupper(trim($reference)));
            if ($key !== null && DB::table('billing_receipts')->where('reference_key', $key)->lockForUpdate()->first() !== null) {
                throw new WriteConflict('billing_payment_recorded', 'This external transaction reference was already recorded.');
            }
            $recordId = DB::table('billing_receipts')->insertGetId(['account_id' => $account->id, 'number' => 'REC-'.\Illuminate\Support\Str::uuid(),
                'kind' => $data['kind'], 'amount_kobo' => $amount, 'currency' => 'NGN', 'method' => $method, 'reference' => $reference,
                'reference_key' => $key, 'payer_name' => $data['payer_name'], 'payer_phone' => $data['payer_phone'] ?? null,
                'externally_confirmed' => $method !== 'cash', 'paid_at' => $paid->format('Y-m-d H:i:s.u'),
                'transaction_details' => json_encode((object) $details, JSON_THROW_ON_ERROR)] + $signature);
        } elseif ($action === 'allocate') {
            $receipt = $state['receipts']->get($data['receipt_id']);
            $invoice = $state['invoices']->get($data['invoice_id']);
            abort_if($receipt === null || $invoice === null, 404);
            $amount = $this->amount($data['amount']);
            if ($amount > $state['receiptData'][$receipt->id]['available'] || $amount > $state['invoiceData'][$invoice->id]['due']) {
                throw new WriteConflict('billing_allocation_exceeded', 'Allocate only available receipt credit up to this invoice balance.');
            }
            $recordId = DB::table('billing_allocations')->insertGetId(['receipt_id' => $receipt->id, 'invoice_id' => $invoice->id, 'amount_kobo' => $amount] + $signature);
        } elseif (in_array($action, ['credit-request', 'refund-request'], true)) {
            $amount = $this->amount($data['amount']);
            $credit = $action === 'credit-request';
            $target = $credit ? $state['items']->get($data['item_id']) : $state['receipts']->get($data['receipt_id']);
            abort_if($target === null, 404);
            if ($credit && DB::table('hmo_invoice_shares')->where('item_id', $target->id)->lockForUpdate()->first() !== null) {
                throw new WriteConflict('hmo_payer_correction_required', 'This charge has a frozen payer split. An approved payer-aware financial correction is required; self-pay credits cannot change HMO liability.');
            }
            $available = $credit ? $state['itemData'][$target->id]['available'] : $state['receiptData'][$target->id]['available'];
            if ($amount > $available) {
                throw new WriteConflict('billing_change_exceeded', 'This request exceeds the unreserved charge or unallocated receipt credit.');
            }
            $replacement = null;
            if (isset($data['replacement_quantity'])) {
                $replacement = $this->authority->decimal($data['replacement_quantity'], 'replacement_quantity', true);
                if (! $credit || $amount !== (int) $target->amount_kobo - $state['itemData'][$target->id]['credited']
                    || $state['itemData'][$target->id]['reserved'] !== 0) {
                    $this->invalid('replacement_quantity', 'A replacement must credit the entire remaining original line with no other pending credits.');
                }
                $this->lineAmount((int) $target->unit_price_micros, $replacement);
            }
            $recordId = DB::table('billing_changes')->insertGetId(['account_id' => $account->id, 'kind' => $credit ? 'credit' : 'refund',
                'item_id' => $credit ? $target->id : null, 'receipt_id' => $credit ? null : $target->id,
                'amount_kobo' => $amount, 'replacement_quantity_micros' => $replacement] + $signature);
        } else {
            $change = $state['changes']->get($request->route('billingChange'));
            abort_if($change === null, 404);
            abort_if($change->actor_id === $actor->id, 403);
            if ($state['reviews']->has($change->id)) {
                throw new WriteConflict('billing_change_reviewed', 'This request already has an independent decision.');
            }
            $payout = $payoutKey = $payoutTime = null;
            $payoutMethod = $payee = $payoutDetails = null;
            if (($action !== 'approve' || $change->kind !== 'refund')
                && array_intersect(array_keys($data), ['payout_reference','payout_at','payout_confirmed','payout_method','payee_name','payout_details']) !== []) {
                $this->invalid('payout_reference', 'Payout details apply only to recording an approved physical refund.');
            }
            if ($action === 'approve' && $change->kind === 'refund') {
                if (! ($data['payout_confirmed'] ?? false) || empty($data['payout_reference']) || empty($data['payout_at'])
                    || empty($data['payout_method']) || empty($data['payee_name']) || ! array_key_exists('payout_details', $data)) {
                    $this->invalid('payout_reference', 'Confirm the physical refund and record its voucher/transaction reference and actual time.');
                }
                $payout = $data['payout_reference'];
                $payoutMethod = $data['payout_method'];
                $payee = $data['payee_name'];
                $payoutDetails = $data['payout_details'];
                if ($payoutMethod !== 'cash' && empty($payoutDetails['provider']) || $payoutMethod === 'other' && empty($payoutDetails['method_name'])) {
                    $this->invalid('payout_details', 'Record the external refund provider and any other payment-method description.');
                }
                $payoutTime = CarbonImmutable::parse($data['payout_at'])->utc();
                if ($payoutTime->greaterThan($time)) {
                    $this->invalid('payout_at', 'The actual refund cannot be in the future.');
                }
                $payoutKey = hash('sha256', mb_strtoupper(trim($payout)));
                $previousPayouts = DB::table('billing_change_reviews')->where(function (\Illuminate\Database\Query\Builder $query) use ($payoutKey): void {
                    $query->where('payout_key', $payoutKey)->orWhere(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->whereNull('payout_key')->whereNotNull('payout_reference'));
                })->lockForUpdate()->get();
                foreach ($previousPayouts as $previousPayout) {
                    if (hash('sha256', mb_strtoupper(trim($previousPayout->payout_reference))) === $payoutKey) {
                        throw new WriteConflict('billing_refund_recorded', 'This external refund reference was already recorded.');
                    }
                }
            }
            $recordId = DB::table('billing_change_reviews')->insertGetId(['change_id' => $change->id,
                'decision' => $action === 'approve' ? 'approved' : 'rejected', 'payout_reference' => $payout,
                'payout_key' => $payoutKey, 'payout_at' => $payoutTime?->format('Y-m-d H:i:s.u'), 'payout_confirmed' => $payout !== null,
                'payout_method' => $payoutMethod, 'payee_name' => $payee,
                'payout_details' => $payoutDetails === null ? null : json_encode((object) $payoutDetails, JSON_THROW_ON_ERROR)] + $signature);
            if ($action === 'approve' && $change->kind === 'credit') {
                $item = $state['items'][$change->item_id];
                $invoiceId = $item->invoice_id;
                $excess = max(0, -($state['invoiceData'][$invoiceId]['due'] - (int) $change->amount_kobo));
                foreach ($state['allocations']->where('invoice_id', $invoiceId)->sortByDesc('id') as $allocation) {
                    $release = min($excess, $state['allocationData'][$allocation->id]);
                    if ($release > 0) {
                        DB::table('billing_allocation_releases')->insert(['allocation_id' => $allocation->id, 'review_id' => $recordId, 'amount_kobo' => $release] + $signature);
                        $excess -= $release;
                    }
                }
                if ($change->replacement_quantity_micros !== null) {
                    $quantity = (int) $change->replacement_quantity_micros;
                    $line = ['source_type' => 'correction', 'source_id' => $change->id, 'source_key' => hash('sha256', 'correction|'.$change->id),
                        'service_price_id' => $item->service_price_id, 'drug_price_id' => $item->drug_price_id, 'corrects_item_id' => $item->id,
                        'quantity_micros' => $quantity, 'unit_price_micros' => (int) $item->unit_price_micros,
                        'amount_kobo' => $this->lineAmount((int) $item->unit_price_micros, $quantity), 'description' => $item->description,
                        'snapshot' => array_replace(json_decode($item->snapshot, true, flags: JSON_THROW_ON_ERROR), ['corrects_item_id' => $item->id])];
                    $this->postInvoice($account, $state['invoices'][$invoiceId]->encounter_id, [$line], $signature);
                }
            }
        }
        $eventId = DB::table('billing_events')->insertGetId(['account_id' => $account->id, 'version' => (int) ($last->version ?? 0) + 1,
            'action' => $action, 'record_id' => $recordId] + $signature);
        $this->balances->snapshot($account);
        $this->audit->record('billing.'.$action, $actor->id, 'billing_account', $account->id, ['version' => (int) ($last->version ?? 0) + 1, 'record_id' => $eventId]);

        return $this->response($account)->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    public function source(BillingAccount $account, Encounter $visit, string $kind, int $id, CarbonImmutable $time, int $expectedPrice): array
    {
        ['serviceId'=>$serviceId, 'drugId'=>$drugId, 'quantity'=>$quantity, 'occurred'=>$occurred, 'snapshot'=>$snapshot, 'key'=>$key]
            = $this->sourceFacts($account, $visit, $kind, $id);
        if ($serviceId !== null) {
            $price = ServicePrice::query()->where('service_id', $serviceId)->where('effective_at', '<=', $occurred)
                ->orderByDesc('effective_at')->orderByDesc('version')->lockForUpdate()->first();
            if ($price === null || ! $price->is_active) {
                throw new WriteConflict('billing_price_missing', 'No active approved service price applies at the actual service time.');
            }
            $description = $price->service->name;
        } else {
            $price = DrugPrice::query()->where('drug_id', $drugId)->where('kind', 'selling')->where('currency', 'NGN')
                ->where('effective_at', '<=', $occurred)->orderByDesc('effective_at')->orderByDesc('version')->lockForUpdate()->first();
            if ($price === null || ! StockCatalog::approved('prices', $price)) {
                throw new WriteConflict('billing_price_missing', 'No approved NGN selling price applies at the original issue time.');
            }
            $description = mb_substr($price->drug->name, 0, 191);
        }
        $this->authority->version($price->id, $expectedPrice);
        $snapshot += ['service_id' => $serviceId, 'drug_id' => $drugId, 'occurred_at' => CarbonImmutable::parse($occurred, 'UTC')->toISOString(), 'price_version' => $price->version,
            'currency' => 'NGN', 'rounding' => 'line_half_up_2dp', 'self_pay_confirmed' => true];

        return ['source_type' => $kind, 'source_id' => $id, 'source_key' => $key, 'service_price_id' => $serviceId === null ? null : $price->id,
            'drug_price_id' => $drugId === null ? null : $price->id, 'corrects_item_id' => null, 'quantity_micros' => $quantity,
            'unit_price_micros' => $price->amount_micros, 'amount_kobo' => $this->lineAmount($price->amount_micros, $quantity),
            'description' => $description, 'snapshot' => $snapshot];
    }

    public function sourceFacts(BillingAccount $account, Encounter $visit, string $kind, int $id): array
    {
        $serviceId = $drugId = null;
        $snapshot = ['source_type' => $kind, 'source_id' => $id, 'encounter_id' => $visit->id];
        if ($kind === 'performed') {
            $row = DB::table('billing_performed_services')->where('account_id', $account->id)->where('encounter_id', $visit->id)->lockForUpdate()->find($id);
            abort_if($row === null, 404);
            $serviceId = $row->service_id;
            $quantity = (int) $row->quantity_micros;
            $occurred = $row->occurred_at;
            $snapshot['reference'] = $row->reference;
        } elseif ($kind === 'investigation') {
            $row = DB::table('investigation_items as i')->join('investigation_tests as t', 't.id', '=', 'i.test_id')
                ->where('i.id', $id)->where('i.encounter_id', $visit->id)->select('i.*', 't.service_id')->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ($row->status !== 'completed') {
                throw new WriteConflict('billing_source_incomplete', 'Only a completed investigation can become a charge.');
            }
            $serviceId = $row->service_id;
            $quantity = FixedPoint::SCALE;
            $occurred = $row->last_event_at;
        } else {
            $row = DB::table('medication_dispenses as d')->join('medication_authorizations as a', 'a.id', '=', 'd.authorization_id')
                ->join('prescription_items as i', 'i.id', '=', 'a.prescription_item_id')->join('clinical_records as c', 'c.id', '=', 'i.prescription_id')
                ->where('d.id', $id)->where('c.encounter_id', $visit->id)->select('d.*', 'a.drug_id')->lockForUpdate()->first();
            abort_if($row === null, 404);
            $drugId = $row->drug_id;
            $quantity = (int) $row->quantity_micros;
            $occurred = $row->recorded_at;
        }
        $this->time($occurred);
        $key = hash('sha256', $kind.'|'.$id);
        if (DB::table('billing_invoice_items')->where('source_key', $key)->lockForUpdate()->first() !== null) {
            throw new WriteConflict('billing_source_charged', 'This source already has a retained charge. Use a reasoned credit/correction.');
        }
        return compact('serviceId','drugId','quantity','occurred','snapshot','key');
    }

    public function postInvoice(BillingAccount $account, int $encounterId, array $lines, array $signature): int
    {
        $total = array_sum(array_column($lines, 'amount_kobo'));
        $this->range($total);
        $current = $this->balances->snapshot($account);
        $this->range($current['totals']['charges'] + $total);
        $id = DB::table('billing_invoices')->insertGetId(['account_id' => $account->id, 'encounter_id' => $encounterId,
            'number' => 'INV-'.\Illuminate\Support\Str::uuid(), 'currency' => 'NGN', 'item_count' => count($lines), 'total_kobo' => $total] + $signature);
        foreach ($lines as $line) {
            $line['snapshot'] = json_encode($line['snapshot'], JSON_THROW_ON_ERROR);
            DB::table('billing_invoice_items')->insert(['invoice_id' => $id] + $line + $signature);
        }
        DB::table('billing_invoice_posts')->insert(['invoice_id' => $id] + $signature);

        return $id;
    }

    private function catalog(Request $request, User $actor, array $data): JsonResponse
    {
        $time = $this->time();
        if (self::action($request) === 'service') {
            if (Service::query()->where('code', $data['code'])->lockForUpdate()->first() !== null) {
                throw new WriteConflict('billing_service_exists', 'This service code already has a retained identity.');
            }
            $record = new Service;
            $record->forceFill(['code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'is_active' => true, 'created_by' => $actor->id])->save();
        } else {
            $service = Service::query()->lockForUpdate()->findOrFail($request->route('billingService'));
            $previous = ServicePrice::query()->where('service_id', $service->id)->orderByDesc('version')->lockForUpdate()->first();
            $this->authority->version($previous?->version ?? 0, $data['expected_version']);
            $this->time($previous?->recorded_at);
            $effective = isset($data['effective_at']) ? CarbonImmutable::parse($data['effective_at'])->utc() : $time;
            if ($effective->lessThan($time) || $previous !== null && $effective->lessThan($previous->effective_at)) {
                $this->invalid('effective_at', 'Publish a current/future price without changing earlier effective history.');
            }
            $record = new ServicePrice;
            $record->forceFill(['service_id' => $service->id, 'version' => ($previous?->version ?? 0) + 1,
                'amount_micros' => $this->authority->decimal($data['unit_price'], 'unit_price'), 'currency' => 'NGN', 'effective_at' => $effective,
                'is_active' => $data['is_active'], 'approval_reference' => $data['approval_reference'],
                'actor_id' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason']])->save();
        }
        $this->audit->record('billing.'.self::action($request), $actor->id, 'billing_catalog', $record->id);

        return response()->json(['data' => $record->toArray() + ['version' => $record->version ?? 1]])->setStatusCode(201);
    }

    public function response(BillingAccount $account): JsonResponse
    {
        return (new \App\Http\Resources\BillingAccountResource($account))->response();
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        if (self::isCatalog($request)) {
            $record = self::action($request) === 'service' ? Service::findOrFail($receipt->resource_id) : ServicePrice::findOrFail($receipt->resource_id);

            return response()->json(['data' => $record->toArray() + ['version' => $record->version ?? 1]]);
        }
        $account = BillingAccount::query()->where('patient_id', $request->attributes->get('billing_patient')->id)->lockForUpdate()->findOrFail($receipt->resource_id);
        $last = DB::table('billing_events')->where('account_id', $account->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $this->authority->version((int) $last->version, $receipt->resource_version);

        return $this->response($account);
    }

    private function amount(mixed $value): int
    {
        try {
            $result = BillingMoney::parse($value);
            if ($result === 0) {
                throw new \InvalidArgumentException;
            }

            return $result;
        } catch (\InvalidArgumentException) {
            $this->invalid('amount', 'Supply a positive exact NGN amount with at most two decimals.');
        }
    }

    private function lineAmount(int $price, int $quantity): int
    {
        try {
            return BillingMoney::charge(FixedPoint::format($price), FixedPoint::format($quantity));
        } catch (\InvalidArgumentException) {
            $this->invalid('sources', 'The complete charge exceeds the supported monetary range.');
        }
    }

    private function range(mixed $amount): void
    {
        if (! is_int($amount) || $amount < 0 || $amount > BillingMoney::MAX) {
            throw new WriteConflict('billing_amount_range', 'This amount exceeds the supported exact monetary range.');
        }
    }

    private function time(mixed ...$previous): CarbonImmutable
    {
        $time = CarbonImmutable::now('UTC');
        foreach ($previous as $value) {
            if ($value !== null && $time->lessThan($value)) {
                throw new WriteConflict('billing_clock_conflict', 'The server clock precedes a saved financial/source event.');
            }
        }

        return $time;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
