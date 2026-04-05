<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewTokenController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function issue(Request $request): JsonResponse
    {
        $request->validate(['visit_order_id' => 'required|string|max:64']);

        $raw     = Str::random(40);
        $hash    = hash('sha256', $raw);
        $expires = now()->utc()->addHours(48);

        DB::table('visit_review_tokens')->insert([
            'visit_order_id' => $request->input('visit_order_id'),
            'token_hash'     => $hash,
            'expires_at'     => $expires,
            'created_at'     => now()->utc(),
            'updated_at'     => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'reviews', 'issue_public_token', 'success', $request->user(), [
            'visit_order_id' => (string) $request->input('visit_order_id'),
            'expires_at' => $expires->toISOString(),
        ]);

        return response()->json([
            'token'      => $raw,
            'expires_at' => $expires->toISOString(),
            'request_id' => ApiResponse::requestId($request),
        ], 201);
    }
}
