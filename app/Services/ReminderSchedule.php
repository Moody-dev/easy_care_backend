<?php

namespace App\Services;

use App\Models\ReminderRevision;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReminderSchedule
{
    public const TIMEZONE = 'Africa/Lagos';

    public function due(ReminderRevision $revision, int $sequence): ?CarbonImmutable
    {
        if ($sequence<1 || $sequence>$revision->occurrence_count) { return null; }
        $start=$revision->starts_at->setTimezone(self::TIMEZONE);
        $offset=($sequence-1)*$revision->interval;
        $due=match ($revision->frequency) {
            'once'=>$start,
            'daily'=>$start->addDays($offset),
            'weekly'=>$start->addWeeks($offset),
            'monthly'=>$start->addMonthsNoOverflow($offset),
        };
        if ($due->year>9999 || $due->year<1000) {
            throw ValidationException::withMessages(['occurrence_count'=>['The schedule exceeds the supported calendar range.']]);
        }
        $due=$due->utc();
        return $revision->ends_at!==null && $due->greaterThan($revision->ends_at) ? null : $due;
    }

    public function validate(ReminderRevision $revision): void
    {
        $this->due($revision, $revision->occurrence_count);
    }
}
