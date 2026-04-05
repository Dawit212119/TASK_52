<?php

namespace App\Http\Middleware;

use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*')) {
            return $response;
        }

        if (str_starts_with('/'.ltrim($request->path(), '/'), '/api/v1/auth/')) {
            return $response;
        }

        $method = strtoupper($request->method());
        $isMutation = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if (! $isMutation) {
            return $response;
        }

        $user = $request->user();
        if ($user === null) {
            return $response;
        }

        $statusCode = $response->getStatusCode();
        $status = match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode === 401 || $statusCode === 403 => 'denied',
            default => 'failure',
        };

        $eventType = $this->eventTypeFromPath('/'.ltrim($request->path(), '/'));

        $this->auditLogger->log($request, $eventType, mb_strtolower($method), $status, $user, [
            'status_code' => $statusCode,
        ]);

        return $response;
    }

    private function eventTypeFromPath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        return $segments[2] ?? 'api';
    }
}
