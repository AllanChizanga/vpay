<?php

namespace Tests\Unit;

use Tests\TestCase;
use Mockery;
use Exception;
use App\Http\Middleware\VerifyAuthToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyAuthTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_no_bearer_token_returns_403()
    {
        $request = Request::create('/', 'GET');
        $middleware = new VerifyAuthToken();

        $next = function ($req) {
            return response('next', 200);
        };

        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('Unauthorized: No bearer token provided', $data['message']);
    }

    public function test_non_200_from_auth_service_logs_warning_and_returns_403()
    {
        $token = 'test-token';
        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        // Mock response object returned by Http::post
        $responseMock = Mockery::mock();
        $responseMock->shouldReceive('ok')->andReturn(false);
        $responseMock->shouldReceive('status')->andReturn(500);
        $responseMock->shouldReceive('body')->andReturn('error body');

        // Mock Http facade chain
        Http::shouldReceive('withHeaders')->once()->andReturnSelf();
        Http::shouldReceive('timeout')->once()->andReturnSelf();
        Http::shouldReceive('post')->once()->andReturn($responseMock);

        // Expect a warning log with appropriate message and context
        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
            return $message === 'Auth service returned non-200'
                && is_array($context)
                && array_key_exists('status', $context)
                && array_key_exists('body', $context);
        });

        $middleware = new VerifyAuthToken();

        $next = function ($req) {
            return response('next', 200);
        };

        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Unauthorized: Token verification failed', $data['message']);
    }

    public function test_exception_during_verification_logs_error_and_returns_403()
    {
        $token = 'test-token';
        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        // Make Http::withHeaders throw to simulate an exception during verification
        Http::shouldReceive('withHeaders')->once()->andThrow(new Exception('boom'));

        // Expect an error log with message and context containing the exception message
        Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
            return $message === 'Auth verification failed'
                && is_array($context)
                && array_key_exists('error', $context)
                && strpos($context['error'], 'boom') !== false;
        });

        $middleware = new VerifyAuthToken();

        $next = function ($req) {
            return response('next', 200);
        };

        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Unauthorized: Token verification failed', $data['message']);
    }

    public function test_authenticated_token_passes_to_next()
    {
        $token = 'valid-token';
        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        // Mock response object returned by Http::post
        $responseMock = Mockery::mock();
        $responseMock->shouldReceive('ok')->andReturn(true);
        $responseMock->shouldReceive('json')->andReturn(['data' => ['authenticated' => true]]);

        // Mock Http facade chain
        Http::shouldReceive('withHeaders')->once()->andReturnSelf();
        Http::shouldReceive('timeout')->once()->andReturnSelf();
        Http::shouldReceive('post')->once()->andReturn($responseMock);

        // No logs expected for success, but if any are called it's not critical; we don't assert logs here.

        $middleware = new VerifyAuthToken();

        $next = function ($req) {
            return response('next', 200);
        };

        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('next', $response->getContent());
    }
}