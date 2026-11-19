<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RenderAccessTest extends TestCase
{
    use RefreshDatabase;

    private ?string $cookie = null;

    private ?string $csrf = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cors.allowed_origins' => ['*'], 'session.driver' => 'database', 'session.secure' => true,
            'session.http_only' => true, 'session.same_site' => 'none', 'session.encrypt' => true]);
        $this->withCredentials()->withHeaders(['Origin' => 'https://frontend-team.vercel.app', 'Sec-Fetch-Site' => 'cross-site']);
        $this->app->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery =>
            new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    #[DataProvider('origins')]
    public function test_open_cors_supports_credentialed_preflight_without_bypassing_authentication(string $origin): void
    {
        $this->options('/api/v1/patients', [], ['Origin' => $origin,
            'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'Content-Type, X-CSRF-TOKEN, Idempotency-Key'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response = $this->getJson('/api/v1/auth/me', ['Origin' => $origin])->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('origin', strtolower((string) $response->headers->get('Vary')));
    }

    public static function origins(): array
    {
        return [['https://team-preview.vercel.app'], ['http://localhost:5173'], ['https://another-team.example']];
    }

    public function test_cross_site_session_cookie_csrf_login_restore_permissions_and_logout(): void
    {
        $user = User::factory()->create(['password' => self::STAFF_TEST_PASSWORD]);
        $bootstrap = $this->send('GET', '/api/v1/auth/csrf-token')->assertOk();
        $cookie = collect($bootstrap->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('none', $cookie->getSameSite());
        $this->send('POST', '/api/v1/auth/login', ['email_or_username' => $user->username, 'password' => self::STAFF_TEST_PASSWORD])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->send('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->send('GET', '/api/v1/patients')->assertForbidden();
        $this->send('POST', '/api/v1/auth/logout')->assertNoContent();
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_wildcard_origin_still_requires_a_csrf_token_for_cross_site_writes(): void
    {
        $this->send('GET', '/api/v1/auth/csrf-token')->assertOk();
        $this->csrf = null;
        $this->send('POST', '/api/v1/auth/login', ['email_or_username' => 'unknown', 'password' => 'unused'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_configured_render_proxy_preserves_https_and_client_ip_without_trusting_forwarded_host(): void
    {
        config(['deployment.trusted_proxies' => '*']);
        $this->proxyRoute();
        $this->getJson('/api/v1/test-proxy', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.20',
            'X-Forwarded-Host' => 'untrusted-host.example'])->assertOk()
            ->assertJsonPath('secure', true)->assertJsonPath('ip', '203.0.113.20')->assertJsonPath('host', 'localhost');
    }

    public function test_local_setup_does_not_trust_render_proxy_headers_by_default(): void
    {
        config(['deployment.trusted_proxies' => null]);
        $this->proxyRoute();
        $this->getJson('/api/v1/test-proxy', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.20'])
            ->assertOk()->assertJsonPath('secure', false)->assertJsonPath('ip', '127.0.0.1');
    }

    private function proxyRoute(): void
    {
        Route::get('/api/v1/test-proxy', fn (Request $request): array => [
            'secure' => $request->isSecure(), 'ip' => $request->ip(), 'host' => $request->getHost(),
        ])->middleware('api');
    }

    private function send(string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->unencryptedCookies = $this->cookie ? [config('session.cookie') => $this->cookie] : [];
        $response = $this->json($method, $uri, $data, $this->csrf ? ['X-CSRF-TOKEN' => $this->csrf] : []);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->cookie = $cookie->getValue();
            }
        }
        if ($response->getContent() !== '' && is_string($response->json('data.csrf_token'))) {
            $this->csrf = $response->json('data.csrf_token');
        }

        return $response;
    }
}
