<?php

namespace App\Jobs;

use App\Services\ReminderDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DeliverDueReminders implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 45;
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onConnection(config('reminders.queue_connection'));
        $this->onQueue(config('reminders.queue'));
        $this->afterCommit();
    }

    public function uniqueId(): string { return 'staff-reminder-delivery'; }
    public function uniqueVia(): Repository { return Cache::store(config('reminders.unique_store')); }
    public function backoff(): array { return [10,30,60,120]; }

    public function handle(ReminderDelivery $delivery): void
    {
        if (config('reminders.enabled')) { $delivery->run(config('reminders.batch_size')); }
    }
}
