<?php

namespace App\Http\Middleware;

use Closure;
use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyAuthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthorized: No bearer token provided'], 403);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->timeout(5)
            ->post('https://authentication.zomacdigital.co.zw/api/user/verify-token');

            if (! $response->ok()) {
                Log::warning('Auth service returned non-200', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json(['message' => 'Unauthorized: Token verification failed'], 403);
            }

            $json = $response->json() ?: [];

            // Use data_get to safely retrieve nested values without notices
            $authenticated = data_get($json, 'data.authenticated', false);

            if ($authenticated !== true) {
                return response()->json(['message' => 'Unauthorized: Token invalid'], 403);
            }

        } catch (Throwable $e) {
            Log::error('Auth verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Unauthorized: Token verification failed'], 403);
        }

        return $next($request);
    }
}