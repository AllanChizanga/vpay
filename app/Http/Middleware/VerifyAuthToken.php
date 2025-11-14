<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class VerifyAuthToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        // No token provided
        if (!$token) {
            return response()->json([
                'message' => 'Unauthorized: No bearer token provided'
            ], 403);
        }

        try {
            // Verify token on authentication service
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ])
            ->timeout(10)
            ->post('https://authentication.zomacdigital.co.zw/api/user/verify-token');

            $json = $response->json();

            // Ensure the response is valid
            if (!$response->successful() || !isset($json['data'])) {
                return response()->json([
                    'message' => 'Unauthorized: Invalid response from authentication service'
                ], 403);
            }

            // Correct key check: "is_authenticated"
            if (
                !isset($json['data']['is_authenticated']) ||
                !$json['data']['is_authenticated']
            ) {
                return response()->json([
                    'message' => 'Unauthorized: Invalid token or authentication failed'
                ], 403);
            }

            // Attach authenticated user to the request for controllers to use
            if (isset($json['data']['user'])) {
                $request->merge(['auth_user' => $json['data']['user']]);
            }

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Unauthorized: Token verification failed',
                'error'   => $e->getMessage(),
            ], 403);
        }

        return $next($request);
    }
}
