<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\BillingAccount;
use App\Support\BillingMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BillingBalances
{
    /** @return array<string, mixed> */
    public function snapshot(BillingAccount $account, bool $lock = true): array
    {
        $fetch = fn (Builder $query): \Illuminate\Support\Collection => $query->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get()->keyBy('id');
        $invoices = $fetch(DB::table('billing_invoices')->where('account_id', $account->id));
        $receipts = $fetch(DB::table('billing_receipts')->where('account_id', $account->id));
        $items = $fetch(DB::table('billing_invoice_items')->whereIn('invoice_id', $invoices->keys()));
        $allocations = $fetch(DB::table('billing_allocations')->whereIn('receipt_id', $receipts->keys()));
        $changes = $fetch(DB::table('billing_changes')->where('account_id', $account->id));
        $reviews = $fetch(DB::table('billing_change_reviews')->whereIn('change_id', $changes->keys()))->keyBy('change_id');
        $releases = $fetch(DB::table('billing_allocation_releases')->whereIn('allocation_id', $allocations->keys()));
        $invoiceData = $receiptData = $itemData = $allocationData = [];
        foreach ($items as $item) {
            $itemData[$item->id] = ['credited' => 0, 'reserved' => 0, 'available' => (int) $item->amount_kobo];
        }
        foreach ($invoices as $invoice) {
            $invoiceData[$invoice->id] = ['total' => (int) $invoice->total_kobo, 'credited' => 0, 'allocated' => 0, 'due' => 0];
        }
        foreach ($receipts as $receipt) {
            $receiptData[$receipt->id] = ['amount' => (int) $receipt->amount_kobo, 'allocated' => 0, 'refunded' => 0, 'reserved' => 0, 'available' => 0];
        }
        foreach ($allocations as $allocation) {
            $net = (int) $allocation->amount_kobo - (int) $releases->where('allocation_id', $allocation->id)->sum('amount_kobo');
            if ($net < 0) {
                throw new WriteConflict('billing_ledger_conflict', 'An allocation has excessive releases. Reconcile this account.');
            }
            $allocationData[$allocation->id] = $net;
            $invoiceData[$allocation->invoice_id]['allocated'] += $net;
            $receiptData[$allocation->receipt_id]['allocated'] += $net;
        }
        foreach ($changes as $change) {
            $decision = $reviews->get($change->id)?->decision;
            if ($decision === 'rejected') {
                continue;
            }
            $amount = (int) $change->amount_kobo;
            if ($change->kind === 'credit') {
                $itemData[$change->item_id][$decision === 'approved' ? 'credited' : 'reserved'] += $amount;
                $itemData[$change->item_id]['available'] -= $amount;
                if ($decision === 'approved') {
                    $invoiceData[$items[$change->item_id]->invoice_id]['credited'] += $amount;
                }
            } else {
                $receiptData[$change->receipt_id][$decision === 'approved' ? 'refunded' : 'reserved'] += $amount;
            }
        }
        foreach ($invoiceData as &$invoice) {
            $invoice['due'] = $invoice['total'] - $invoice['credited'] - $invoice['allocated'];
        }
        unset($invoice);
        foreach ($receiptData as &$receipt) {
            $receipt['available'] = $receipt['amount'] - $receipt['allocated'] - $receipt['refunded'] - $receipt['reserved'];
        }
        unset($receipt);
        $totals = ['charges' => array_sum(array_column($invoiceData, 'total')), 'credited' => array_sum(array_column($invoiceData, 'credited')),
            'allocated' => array_sum(array_column($invoiceData, 'allocated')), 'outstanding' => array_sum(array_column($invoiceData, 'due')),
            'received' => array_sum(array_column($receiptData, 'amount')), 'refunded' => array_sum(array_column($receiptData, 'refunded')),
            'refund_reserved' => array_sum(array_column($receiptData, 'reserved')), 'available_credit' => array_sum(array_column($receiptData, 'available'))];
        foreach ($totals as $value) {
            if (! is_int($value) || abs($value) > BillingMoney::MAX) {
                throw new WriteConflict('billing_amount_range', 'This account exceeds the supported exact monetary range.');
            }
        }

        return compact('invoices', 'receipts', 'items', 'allocations', 'changes', 'reviews', 'releases', 'invoiceData', 'receiptData', 'itemData', 'allocationData', 'totals');
    }
}
