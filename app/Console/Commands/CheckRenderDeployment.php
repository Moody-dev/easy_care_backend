<?php

namespace App\Console\Commands;

use App\Services\HostedDeploymentChecks;
use App\Services\RenderDeploymentChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('deploy:check-render {--wait=0 : Seconds to wait for the private MySQL service, up to 300}')]
#[Description('Check the Render runtime and private database without revealing connection secrets')]
class CheckRenderDeployment extends CheckHostedDeployment
{
    protected function checks(): HostedDeploymentChecks
    {
        return app(RenderDeploymentChecks::class);
    }
}
