<?php

namespace Tests\Unit;

use App\Http\Concerns\ResolvesPageSize;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ResolvesPageSizeTest extends TestCase
{
    use ResolvesPageSize;

    public function test_defaults_clamps_and_floors(): void
    {
        $this->assertSame(25, $this->perPage(Request::create('/')));
        $this->assertSame(50, $this->perPage(Request::create('/?per_page=50')));
        $this->assertSame(100, $this->perPage(Request::create('/?per_page=9999')));
        $this->assertSame(25, $this->perPage(Request::create('/?per_page=0')));
        $this->assertSame(25, $this->perPage(Request::create('/?per_page=abc')));
    }
}
