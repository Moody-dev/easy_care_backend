<?php

namespace App\Console\Commands;

use App\Jobs\DeliverDueReminders;
use App\Models\Reminder;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';
    protected $description = 'Queue a bounded batch of due staff-only in-app reminders';

    public function handle(): int
    {
        if (!config('reminders.enabled')) { $this->info('Reminder delivery is disabled.'); return self::SUCCESS; }
        if (Reminder::query()->where('is_active',true)->where('next_due_at','<=',now('UTC')->format('Y-m-d H:i:s.u'))->exists()) {
            DeliverDueReminders::dispatch();
            $this->info('The due-reminder queue dispatch was requested.');
        } else { $this->info('No reminders are due.'); }
        return self::SUCCESS;
    }
}
