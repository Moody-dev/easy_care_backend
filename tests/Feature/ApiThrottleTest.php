<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_staff_have_individual_limits_even_when_they_share_a_hospital_or_proxy_ip(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk',['patients.view']);
        $first=$this->createStaffWithRole('frontdesk'); $second=$this->createStaffWithRole('frontdesk');
        $key=\Illuminate\Support\Facades\Auth::guard('web')->getName();
        $this->withSession([$key=>$first->id]);
        for ($request=0;$request<120;$request++) {
            \Illuminate\Support\Facades\Auth::forgetGuards();
            $this->getJson('/api/v1/patients/options')->assertOk();
        }
        $this->getJson('/api/v1/patients/options')->assertStatus(429)->assertHeader('Retry-After');
        $this->flushSession(); $this->withSession([$key=>$second->id]); \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/v1/patients/options')->assertOk();
        $this->flushSession(); $this->withSession([$key=>$first->id]); \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.2'])->getJson('/api/v1/patients/options')->assertStatus(429);
    }

    public function test_unauthenticated_requests_keep_an_ip_limit(): void
    {
        for ($request=0;$request<120;$request++) { $this->getJson('/api/v1/health')->assertOk(); }
        $this->getJson('/api/v1/health')->assertStatus(429)->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.2'])->getJson('/api/v1/health')->assertOk();
    }
}
