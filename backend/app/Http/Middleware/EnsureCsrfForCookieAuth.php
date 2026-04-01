<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCsrfForCookieAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->bearerToken() !== null) {
            return $next($request);
        }

        $cookieAuthMode = mb_strtolower((string) $request->header('X-Auth-Mode', '')) === 'cookie';
        if (! $cookieAuthMode && ! $request->cookies->has(config('session.cookie'))) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return response()->json(
                ApiResponse::error('CSRF_TOKEN_MISMATCH', 'Cookie-auth requests require a CSRF token.', ApiResponse::requestId($request)),
                419,
            );
        }

        $sentToken = (string) $request->header('X-CSRF-TOKEN', '');
        $sessionToken = (string) $request->session()->token();

        if ($sentToken === '' || $sessionToken === '' || ! hash_equals($sessionToken, $sentToken)) {
            return response()->json(
                ApiResponse::error('CSRF_TOKEN_MISMATCH', 'Cookie-auth requests require a valid CSRF token.', ApiResponse::requestId($request)),
                419,
            );
        }

        return $next($request);
    }
}
