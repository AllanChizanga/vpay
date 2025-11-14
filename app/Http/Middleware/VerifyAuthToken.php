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

       


        // Attach the user info to the request
        $request->merge([
            'auth_token' => $token,
        ]);

        return $next($request);
    }
}
