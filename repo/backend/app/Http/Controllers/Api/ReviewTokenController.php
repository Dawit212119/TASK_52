<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewTokenController extends Controller
{
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

        return response()->json([
            'token'      => $raw,
            'expires_at' => $expires->toISOString(),
            'request_id' => ApiResponse::requestId($request),
        ], 201);
    }
}
