<?php

namespace Tests\Feature;

use App\Services\HostedDeploymentChecks;

class HostedDeploymentChecksTest extends RenderDeploymentChecksTest
{
    protected string $checkCommand = 'deploy:check';

    protected string $checkService = HostedDeploymentChecks::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deployment.render' => false, 'deployment.platform' => 'oracle']);
    }

    public static function invalidSettings(): array
    {
        $settings = parent::invalidSettings();
        array_shift($settings);
        $settings[] = [['deployment.platform' => null]];
        $settings[] = [['deployment.platform' => 'unsupported']];

        return $settings;
    }
}
