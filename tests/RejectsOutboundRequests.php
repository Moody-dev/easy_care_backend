<?php

namespace Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

trait RejectsOutboundRequests
{
    protected function setUpRejectsOutboundRequests(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $this->beforeApplicationDestroyed(function (): void {
            Http::assertNothingSent();
            Mail::assertNothingOutgoing();
        });
    }
}