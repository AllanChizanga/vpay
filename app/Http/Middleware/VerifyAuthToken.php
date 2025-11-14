<?php

namespace App\Http\Middleware;

use Closure;
use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\GenericUser;
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
            ->timeout((int) config('services.auth.timeout', 5))
            ->post(config('services.auth.verify_url', 'https://authentication.zomacdigital.co.zw/api/user/verify-token'));

            if (! $response->ok()) {
                Log::warning('Auth service returned non-200', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json(['message' => 'Unauthorized: Token verification failed'], 403);
            }

            $json = $response->json() ?: [];

            $authenticated = data_get($json, 'data.authenticated', false);
            // dd($authenticated);
            if ($authenticated !== true) {
                Log::info('Auth token not authenticated by auth service', [
                    'response' => $json,
                ]);

                return response()->json(['message' => 'Unauthorized: Token invalid'], 403);
            }

            // If auth service returned user payload, set non-persistent GenericUser
            $userPayload = data_get($json, 'data.user');
            if (is_array($userPayload) && ! empty($userPayload)) {
                $generic = new GenericUser($userPayload);
                Auth::setUser($generic);
                $request->setUserResolver(fn () => Auth::user());

                Log::info('Auth user set from auth service', [
                    'user_id' => data_get($userPayload, 'id'),
                    'email' => data_get($userPayload, 'email'),
                ]);
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

