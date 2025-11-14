<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
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

            // Convert response to array safely
            $json = $response->json() ?? [];

            // Check if the response has authenticated flag
            $authenticated = $json['data']['authenticated'] ?? false;

            if ($authenticated !== true) {
                return response()->json(['message' => 'Unauthorized: Token invalid'], 403);
            }

        } catch (Exception $e) {
            Log::error('Auth verification failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Unauthorized: Token verification failed'], 403);
        }

        return $next($request);
    }
}
