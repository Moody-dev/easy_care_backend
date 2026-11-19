<?php

namespace App\Console\Commands;

use App\Services\SupabaseSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('supabase:check {--probe : Connect using the configured private database credentials}')]
#[Description('Check the PostgreSQL development connection without displaying credentials')]
class CheckSupabaseSetup extends Command
{
    public function handle(SupabaseSetup $setup): int
    {
        $checks = $setup->run((bool) $this->option('probe'));
        foreach ($checks as $name => $passed) {
            $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
        }

        if ($setup->connectionHint() !== null) {
            $this->line($setup->connectionHint());
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
