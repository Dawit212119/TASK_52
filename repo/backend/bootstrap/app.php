<?php

use App\Support\ApiResponse;
use Illuminate\Session\Middleware\StartSession;
use App\Http\Middleware\AuthenticateApiRequest;
use App\Http\Middleware\EnsureCsrfForCookieAuth;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            StartSession::class,
        ]);

        $middleware->alias([
            'auth.api' => AuthenticateApiRequest::class,
            'permission' => EnsurePermission::class,
            'cookie.csrf' => EnsureCsrfForCookieAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $details = collect($exception->errors())
                ->flatMap(fn (array $messages, string $field) => collect($messages)
                    ->map(fn (string $message) => [
                        'field' => $field,
                        'rule' => 'validation',
                        'message' => $message,
                    ]))
                ->values()
                ->all();

            return response()->json(
                ApiResponse::error(
                    code: 'VALIDATION_ERROR',
                    message: 'One or more fields are invalid.',
                    requestId: ApiResponse::requestId($request),
                    details: $details,
                ),
                422,
            );
        });

        $exceptions->render(function (AuthenticationException $_exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ApiResponse::error(
                    code: 'UNAUTHENTICATED',
                    message: 'Authentication is required.',
                    requestId: ApiResponse::requestId($request),
                ),
                401,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $statusCode = $exception->getStatusCode();
            $codeMap = [
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                429 => 'RATE_LIMITED',
            ];

            return response()->json(
                ApiResponse::error(
                    code: $codeMap[$statusCode] ?? 'HTTP_ERROR',
                    message: $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                    requestId: ApiResponse::requestId($request),
                ),
                $statusCode,
            );
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ApiResponse::error(
                    code: 'INTERNAL_SERVER_ERROR',
                    message: 'An unexpected server error occurred.',
                    requestId: ApiResponse::requestId($request),
                ),
                500,
            );
        });
    })->create();
