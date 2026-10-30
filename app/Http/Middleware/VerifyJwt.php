<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.jwt.secret');
        $token = $request->bearerToken();

        if (! $secret || ! $token) {
            return $this->unauthorized();
        }

        try {
            $claims = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return $this->unauthorized();
        }

        if (empty($claims->sub)) {
            return $this->unauthorized();
        }

        $request->attributes->set('user_id', (string) $claims->sub);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
