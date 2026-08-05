<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    /** The environment's prior value, so tearDown() restores it rather than merely unsetting it. `false` means it was not set. */
    private string|false $priorEnvValue;

    protected function setUp(): void
    {
        $this->priorEnvValue = getenv('TRUSTED_PROXIES');

        $this->setTrustedProxiesEnv('*');

        parent::setUp();

        $this->registerProbe();
    }

    protected function tearDown(): void
    {
        if ($this->priorEnvValue === false) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        } else {
            $this->setTrustedProxiesEnv($this->priorEnvValue);
        }

        // TrustProxies::at() is static state that outlives the application
        // instance, so it has to be cleared or it leaks into every later test.
        TrustProxies::flushState();

        parent::tearDown();
    }

    /**
     * Laravel's Env repository is built immutable and refuses to overwrite a
     * variable that already exists, so the real environment is written directly
     * — the same pattern BootstrapInstanceCommandTest uses.
     */
    private function setTrustedProxiesEnv(string $value): void
    {
        putenv("TRUSTED_PROXIES={$value}");
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }

    /**
     * bootstrap/app.php reads app.trusted_proxies in its `booted` callback, so
     * a different value only takes effect on a freshly booted application.
     */
    private function bootWithTrustedProxies(string $value): void
    {
        $this->setTrustedProxiesEnv($value);

        TrustProxies::flushState();
        $this->refreshApplication();
        $this->registerProbe();
    }

    private function registerProbe(): void
    {
        Route::get('/__proxy-probe', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);
    }

    public function test_forwarded_proto_is_honoured_when_proxies_are_trusted(): void
    {
        $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertSuccessful()
            ->assertJson(['secure' => true]);
    }

    public function test_a_comma_separated_list_trusts_only_the_listed_proxies(): void
    {
        // The peer in a test request is 127.0.0.1. Surrounding whitespace is
        // deliberate: the list is split and trimmed, not used verbatim.
        $this->bootWithTrustedProxies('10.0.0.1, 127.0.0.1 ,10.0.0.2');

        $this->get('/__proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
        ])
            ->assertSuccessful()
            ->assertJson(['secure' => true, 'ip' => '203.0.113.7']);
    }

    public function test_a_comma_separated_list_ignores_forwarded_headers_from_an_untrusted_peer(): void
    {
        $this->bootWithTrustedProxies('10.0.0.1,10.0.0.2');

        $this->get('/__proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
        ])
            ->assertSuccessful()
            ->assertJson(['secure' => false, 'ip' => '127.0.0.1']);
    }
}
