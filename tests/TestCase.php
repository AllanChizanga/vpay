<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\GenericUser;

abstract class TestCase extends BaseTestCase
{
    // Provide createApplication so the TestCase does not depend on a missing CreatesApplication trait.
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the external auth verification endpoint used by the middleware.
        // Uses the configured URL if available, otherwise falls back to the real host.
        $verifyUrl = config('services.auth.verify_url', 'https://authentication.zomacdigital.co.zw/api/user/verify-token');

        Http::fake([
            $verifyUrl => Http::response([
                'data' => [
                    'authenticated' => true,
                    'user' => [
                        'id' => 9999,
                        'name' => 'Test User',
                        'email' => 'test@example.test',
                    ],
                ],
            ], 200),
            // Ensure other HTTP calls don't accidentally hit the network during tests
            '*' => Http::response(null, 200),
        ]);

        // Provide a default Authorization header for feature tests so middleware sees a bearer token.
        // Individual tests can override with $this->withHeaders(...)
        $this->withHeaders([
            'Authorization' => 'Bearer test-token',
        ]);

        // Set a non-persistent GenericUser so auth()->id() works in unit/feature tests that call services directly.
        Auth::setUser(new GenericUser([
            'id' => 9999,
            'name' => 'Test User',
            'email' => 'test@example.test',
        ]));
    }
}