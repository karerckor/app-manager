<?php

namespace Tests\Feature;

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

        putenv('TRUSTED_PROXIES=*');
        $_ENV['TRUSTED_PROXIES'] = '*';
        $_SERVER['TRUSTED_PROXIES'] = '*';

        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => ['secure' => $request->isSecure()]);
    }

    protected function tearDown(): void
    {
        if ($this->priorEnvValue === false) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        } else {
            putenv("TRUSTED_PROXIES={$this->priorEnvValue}");
            $_ENV['TRUSTED_PROXIES'] = $this->priorEnvValue;
            $_SERVER['TRUSTED_PROXIES'] = $this->priorEnvValue;
        }

        parent::tearDown();
    }

    public function test_forwarded_proto_is_honoured_when_proxies_are_trusted(): void
    {
        $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertSuccessful()
            ->assertJson(['secure' => true]);
    }
}
