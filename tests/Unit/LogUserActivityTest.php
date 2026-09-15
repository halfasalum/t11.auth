<?php

namespace Tests\Unit;

use App\Http\Middleware\LogUserActivity;
use App\Services\UserLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Payload;

class LogUserActivityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function config(): void
    {
        config()->set('activity', [
            'enabled' => true,
            'log_reads' => true,
            'exclude' => ['*authenticate', '*/logout'],
            'sensitive_keys' => ['password', 'pin', 'token'],
            'max_body_bytes' => 8192,
        ]);
    }

    private function reset(): void
    {
        $ref = new \ReflectionProperty(LogUserActivity::class, 'recorded');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    public function test_it_records_an_authenticated_request_with_redacted_body(): void
    {
        $this->config();
        $this->reset();

        $payload = Mockery::mock(Payload::class);
        $payload->shouldReceive('get')->with('user_id')->andReturn(7);
        $payload->shouldReceive('get')->with('company')->andReturn(3);

        JWTAuth::shouldReceive('parseToken->getPayload')->andReturn($payload);

        $captured = null;
        $service = Mockery::mock(UserLogService::class);
        $service->shouldReceive('logRequest')->once()->andReturnUsing(function ($attrs) use (&$captured) {
            $captured = $attrs;

            return null;
        });

        $request = Request::create('/api/v2/customers/register', 'POST', [
            'name' => 'Jane',
            'password' => 'secret',
            'nested' => ['pin' => '0000', 'ok' => 'keep'],
        ]);
        $response = new Response('{}', 201);

        (new LogUserActivity($service))->terminate($request, $response);

        $this->assertNotNull($captured);
        $this->assertSame(7, $captured['user_id']);
        $this->assertSame(3, $captured['company']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame(201, $captured['status_code']);

        $details = json_decode($captured['details'], true);
        $this->assertSame('***', $details['body']['password']);
        $this->assertSame('***', $details['body']['nested']['pin']);
        $this->assertSame('keep', $details['body']['nested']['ok']);
    }

    public function test_it_skips_unauthenticated_requests(): void
    {
        $this->config();
        $this->reset();

        JWTAuth::shouldReceive('parseToken->getPayload')->andThrow(new \Exception('no token'));

        $service = Mockery::mock(UserLogService::class);
        $service->shouldReceive('logRequest')->never();

        $request = Request::create('/api/v2/customers', 'GET');
        (new LogUserActivity($service))->terminate($request, new Response('{}', 200));

        $this->addToAssertionCount(1);
    }

    public function test_it_skips_excluded_paths(): void
    {
        $this->config();
        $this->reset();

        $service = Mockery::mock(UserLogService::class);
        $service->shouldReceive('logRequest')->never();

        $request = Request::create('/api/v2/authenticate', 'POST', ['username' => 'x']);
        (new LogUserActivity($service))->terminate($request, new Response('{}', 200));

        $this->addToAssertionCount(1);
    }
}
