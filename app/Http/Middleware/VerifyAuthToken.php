<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerifyAuthToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Unauthorized: no token provided'], 401);
        }

        // Get user_id from header or request body
        $userId = $request->header('X-User-Id') ?? $request->input('user_id');

        if (!$userId) {
            return response()->json(['error' => 'Unauthorized: user_id missing'], 401);
        }

        // OPTIONAL: Cache the mapping token -> userId for 1 hour
        $cacheKey = 'token_user_' . sha1($token);

        Cache::put($cacheKey, $userId, 3600);

        // Attach the user info to the request
        $request->merge([
            'auth_user_id' => $userId,
            'auth_token' => $token,
        ]);

        return $next($request);
    }
}
