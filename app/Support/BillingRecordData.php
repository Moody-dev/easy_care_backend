<?php

namespace App\Support;

class BillingRecordData
{
    public static function format(\stdClass $row): array
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
}
