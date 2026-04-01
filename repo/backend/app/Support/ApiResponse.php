<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiResponse
{
    /**
     * @param  array<int, array<string, string>>  $details
     * @return array<string, mixed>
     */
    public static function error(string $code, string $message, string $requestId, array $details = []): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'request_id' => $requestId,
        ];
    }

    public static function requestId(Request $request): string
    {
        return $request->header('X-Request-Id') ?: (string) Str::uuid();
    }
}
