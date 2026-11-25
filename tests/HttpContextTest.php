<?php

namespace IdeiaCv\BugCatcher\Tests;

use Illuminate\Auth\GenericUser;
use RuntimeException;

class HttpContextTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/checkout/{pedido}', function () {
            throw new RuntimeException('Falhou o checkout');
        });
    }

    public function test_uncaught_exception_in_a_request_carries_request_route_and_user(): void
    {
        $this->actingAs(new GenericUser(['id' => 42, 'email' => 'maria@example.cv', 'name' => 'Maria', 'remember_token' => null]));

        $this->withHeaders(['Authorization' => 'Bearer segredo', 'X-Trace' => 'abc'])
            ->postJson('/checkout/9?origem=app', ['quantidade' => 2, 'password' => 'p'])
            ->assertStatus(500);

        $event = $this->lastEvent();
        $this->assertSame('Falhou o checkout', $event['exception']['message']);
        $this->assertFalse($event['handled']);
        $this->assertSame('POST /checkout/{pedido}', $event['context']);
        $this->assertSame(['id' => '42', 'email' => 'maria@example.cv', 'name' => 'Maria'], $event['user']);

        $request = $event['request'];
        $this->assertSame('POST', $request['httpMethod']);
        $this->assertSame('http://localhost/checkout/9?origem=app', $request['url']);
        $this->assertSame('127.0.0.1', $request['clientIp']);
        $this->assertSame('[FILTRADO]', $request['headers']['authorization']);
        $this->assertSame('abc', $request['headers']['x-trace']);
        $this->assertSame(['quantidade' => 2, 'password' => '[FILTRADO]', 'origem' => 'app'], $request['params']);
    }
}
