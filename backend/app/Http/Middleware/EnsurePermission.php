<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(
                ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', ApiResponse::requestId($request)),
                401,
            );
        }

        if (! Gate::forUser($user)->allows('permission', $permission)) {
            $this->auditLogger->log($request, 'authorization', 'permission_denied', 'denied', $user, [
                'required_permission' => $permission,
                'user_roles' => $user->roleCodes(),
            ]);

            return response()->json(
                ApiResponse::error('FORBIDDEN', 'You do not have permission for this action.', ApiResponse::requestId($request)),
                403,
            );
        }

        return $next($request);
    }
}
