<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UntrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => ['secure' => $request->isSecure()]);
    }

    public function test_forwarded_proto_is_ignored_when_no_proxy_is_trusted(): void
    {
        $this->get('/__proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertSuccessful()
            ->assertJson(['secure' => false]);
    }
}
