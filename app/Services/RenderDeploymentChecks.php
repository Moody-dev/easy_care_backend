<?php

namespace App\Services;

class RenderDeploymentChecks extends HostedDeploymentChecks
{
    /** @return array<string, bool> */
    public function run(): array
    {
        return ['render_runtime' => config('deployment.render') === true] + parent::run();
    }
}
