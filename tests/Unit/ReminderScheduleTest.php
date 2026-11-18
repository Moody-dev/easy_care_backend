<?php

namespace Tests\Unit;

use App\Models\ReminderRevision;
use App\Services\ReminderSchedule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReminderScheduleTest extends TestCase
{
    #[DataProvider('calendarCases')]
    public function test_calendar_recurrence_retains_the_original_local_day_and_clock(string $start,string $frequency,int $interval,int $sequence,string $expected): void
    {
        $revision=new ReminderRevision;
        $revision->forceFill(['starts_at'=>$start,'ends_at'=>null,'frequency'=>$frequency,'interval'=>$interval,'occurrence_count'=>366]);
        $this->assertSame($expected,app(ReminderSchedule::class)->due($revision,$sequence)->toISOString());
    }

    public static function calendarCases(): array
    {
        return [
            ['2026-01-31T09:30:00.123456Z','monthly',1,2,'2026-02-28T09:30:00.123456Z'],
            ['2026-01-31T09:30:00.123456Z','monthly',1,3,'2026-03-31T09:30:00.123456Z'],
            ['2028-01-31T09:30:00.000000Z','monthly',1,2,'2028-02-29T09:30:00.000000Z'],
            ['2026-11-17T23:30:00.000000Z','daily',1,2,'2026-11-18T23:30:00.000000Z'],
            ['2026-11-17T08:00:00.000000Z','weekly',2,2,'2026-12-01T08:00:00.000000Z'],
        ];
    }

    public function test_count_and_end_time_bound_all_generated_occurrences(): void
    {
        $revision=new ReminderRevision;
        $revision->forceFill(['starts_at'=>'2026-01-01T08:00:00Z','ends_at'=>'2026-01-02T08:00:00Z','frequency'=>'daily','interval'=>1,'occurrence_count'=>3]);
        $schedule=app(ReminderSchedule::class);
        $this->assertNotNull($schedule->due($revision,2));
        $this->assertNull($schedule->due($revision,3));
        $this->assertNull($schedule->due($revision,4));
    }

    public function test_unstorable_calendar_ranges_are_validation_errors(): void
    {
        $revision=new ReminderRevision;
        $revision->forceFill(['starts_at'=>'9999-01-01T08:00:00Z','ends_at'=>null,'frequency'=>'monthly','interval'=>365,'occurrence_count'=>366]);
        $this->expectException(ValidationException::class);
        app(ReminderSchedule::class)->validate($revision);
    }
}
