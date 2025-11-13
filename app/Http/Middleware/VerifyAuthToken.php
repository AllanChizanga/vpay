<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerifyAuthToken
{
    /**
     * Handle an incoming request.
     *
     * This middleware verifies the token with the Auth Service
     * and attaches the authenticated user info to the request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized: no token provided'], 401);
        }

        try {
            // Optional: cache the user info for 5 minutes to reduce Auth Service calls
            $cacheKey = 'auth_user_' . sha1($token);
            $user = Cache::remember($cacheKey, 300, function () use ($token) {
                $response = Http::withToken($token)
                    ->get(env('AUTH_SERVICE_URL') . '/api/user');

                if ($response->failed()) {
                    Log::warning('Auth verification failed', [
                        'token' => $token,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return null;
                }

                return $response->json();
            });

            if (!$user) {
                return response()->json(['error' => 'Unauthorized: invalid token'], 401);
            }

            // Attach user info to the request
            $request->merge(['user' => $user]);

        } catch (\Exception $e) {
            Log::error('Error verifying auth token', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Unauthorized: token verification failed',
                'details' => $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
