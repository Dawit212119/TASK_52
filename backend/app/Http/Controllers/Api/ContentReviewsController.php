<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentReviewsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function listContent(Request $request): JsonResponse
    {
        $query = DB::table('content_items')
            ->orderByDesc('updated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('content_type')) {
            $query->where('content_type', $request->input('content_type'));
        }

        $items = $query->paginate(50);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function showContent(Request $request, int $id): JsonResponse
    {
        $item = DB::table('content_items')->where('id', $id)->first();

        if ($item === null) {
            return response()->json(
                ApiResponse::error('NOT_FOUND', 'Content item not found.',
                    ApiResponse::requestId($request)),
                404,
            );
        }

        return response()->json([
            'data'       => $item,
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function createPublicReview(Request $request): JsonResponse
    {
        $request->validate([
            'visit_order_id' => 'required|string|max:64',
            'token'          => 'required|string',
            'rating'         => 'required|integer|min:1|max:5',
            'text'           => 'nullable|string|max:5000',
        ]);

        $hash = hash('sha256', (string) $request->input('token'));
        $row  = DB::table('visit_review_tokens')
            ->where('token_hash', $hash)
            ->where('visit_order_id', $request->input('visit_order_id'))
            ->whereNull('used_at')
            ->where('expires_at', '>', now()->utc())
            ->first();

        if ($row === null) {
            return response()->json(
                ApiResponse::error('INVALID_TOKEN', 'Invalid or expired review token.',
                    ApiResponse::requestId($request)),
                401,
            );
        }

        DB::table('visit_review_tokens')
            ->where('id', $row->id)
            ->update(['used_at' => now()->utc(), 'updated_at' => now()->utc()]);

        $id = DB::table('reviews')->insertGetId([
            'visit_order_id'    => $request->input('visit_order_id'),
            'rating'            => $request->integer('rating'),
            'text'              => $request->input('text'),
            'visibility_status' => 'visible',
            'created_at'        => now()->utc(),
            'updated_at'        => now()->utc(),
        ]);

        return response()->json([
            'data'       => ['id' => $id],
            'request_id' => ApiResponse::requestId($request),
        ], 201);
    }

    public function createContent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_type' => ['required', 'in:announcement,homepage_carousel'],
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'facility_ids' => ['nullable', 'array'],
            'department_ids' => ['nullable', 'array'],
            'role_codes' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
        ]);

        $id = DB::table('content_items')->insertGetId([
            'content_type' => $validated['content_type'],
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => 'draft',
            'current_version' => 1,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        DB::table('content_targets')->insert([
            'content_item_id' => $id,
            'facility_ids' => json_encode($validated['facility_ids'] ?? [], JSON_THROW_ON_ERROR),
            'department_ids' => json_encode($validated['department_ids'] ?? [], JSON_THROW_ON_ERROR),
            'role_codes' => json_encode($validated['role_codes'] ?? [], JSON_THROW_ON_ERROR),
            'tags' => json_encode($validated['tags'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->snapshotContentVersion($id, 'draft', $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()], 201);
    }

    public function updateContent(Request $request, int $id): JsonResponse
    {
        $item = DB::table('content_items')->where('id', $id)->first();
        if ($item === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Content not found.', ApiResponse::requestId($request)), 404);
        }

        $payload = $request->only(['title', 'body']);
        $nextVersion = (int) $item->current_version + 1;
        $payload['current_version'] = $nextVersion;
        $payload['updated_by_user_id'] = $request->user()?->id;
        $payload['updated_at'] = now()->utc();

        DB::table('content_items')->where('id', $id)->update($payload);

        $targetPayload = [];
        foreach (['facility_ids', 'department_ids', 'role_codes', 'tags'] as $key) {
            if ($request->has($key)) {
                $targetPayload[$key] = json_encode($request->input($key, []), JSON_THROW_ON_ERROR);
            }
        }
        if ($targetPayload !== []) {
            $targetPayload['updated_at'] = now()->utc();
            DB::table('content_targets')->where('content_item_id', $id)->update($targetPayload);
        }

        $this->snapshotContentVersion($id, (string) $item->status, $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function submitApproval(Request $request, int $id): JsonResponse
    {
        DB::table('content_items')->where('id', $id)->update([
            'status' => 'pending_approval',
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now()->utc(),
        ]);

        $this->snapshotContentVersion($id, 'pending_approval', $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'content.approve')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Approver role required.', ApiResponse::requestId($request)), 403);
        }

        DB::table('content_items')->where('id', $id)->update([
            'status' => 'published',
            'published_at' => now()->utc(),
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now()->utc(),
        ]);

        $this->snapshotContentVersion($id, 'published', $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'content.approve')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Approver role required.', ApiResponse::requestId($request)), 403);
        }

        DB::table('content_items')->where('id', $id)->update([
            'status' => 'rejected',
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now()->utc(),
        ]);

        $this->snapshotContentVersion($id, 'rejected', $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function rollback(Request $request, int $id): JsonResponse
    {
        $version = (int) $request->integer('version');
        $snapshot = DB::table('content_versions')
            ->where('content_item_id', $id)
            ->where('version_number', $version)
            ->first();

        if ($snapshot === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Version not found.', ApiResponse::requestId($request)), 404);
        }

        $data = json_decode((string) $snapshot->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        DB::table('content_items')->where('id', $id)->update([
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'status' => 'published',
            'current_version' => ((int) DB::table('content_items')->where('id', $id)->value('current_version')) + 1,
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => now()->utc(),
        ]);

        $this->snapshotContentVersion($id, 'published', $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function versions(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('content_versions')->where('content_item_id', $id)->orderByDesc('version_number')->get()]);
    }

    public function createReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_order_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array'],
            'text' => ['nullable', 'string'],
            'facility_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*.filename' => ['required_with:images', 'string'],
            'images.*.path' => ['required_with:images', 'string'],
            'images.*.checksum_sha256' => ['required_with:images', 'string', 'size:64'],
            'images.*.bytes_size' => ['nullable', 'integer', 'min:0'],
        ]);

        $reviewId = DB::table('reviews')->insertGetId([
            'visit_order_id' => $validated['visit_order_id'],
            'facility_id' => $validated['facility_id'] ?? null,
            'provider_id' => $validated['provider_id'] ?? null,
            'rating' => $validated['rating'],
            'tags' => json_encode($validated['tags'] ?? [], JSON_THROW_ON_ERROR),
            'text' => $validated['text'] ?? null,
            'visibility_status' => 'visible',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        foreach ($validated['images'] ?? [] as $image) {
            DB::table('review_media')->insert([
                'review_id' => $reviewId,
                'filename' => $image['filename'],
                'path' => $image['path'],
                'checksum_sha256' => $image['checksum_sha256'],
                'bytes_size' => (int) ($image['bytes_size'] ?? 0),
                'created_at_utc' => now()->utc(),
            ]);
        }

        return response()->json([
            'data' => DB::table('reviews')->where('id', $reviewId)->first(),
            'media' => DB::table('review_media')->where('review_id', $reviewId)->get(),
        ], 201);
    }

    public function reviewResponse(Request $request, int $id): JsonResponse
    {
        if (! DB::table('reviews')->where('id', $id)->exists()) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        $validated = $request->validate(['response_text' => ['required', 'string']]);
        DB::table('review_responses')->insert([
            'review_id' => $id,
            'responded_by_user_id' => $request->user()?->id,
            'response_text' => $validated['response_text'],
            'created_at_utc' => now()->utc(),
        ]);

        return response()->json(['data' => ['status' => 'responded']]);
    }

    public function reviewAppeal(Request $request, int $id): JsonResponse
    {
        if (! DB::table('reviews')->where('id', $id)->exists()) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        $validated = $request->validate([
            'reason_category' => ['required', 'in:abusive_language,harassment,privacy,spam,other'],
            'assigned_to_user_id' => ['nullable', 'integer'],
        ]);

        $policyVersion = (string) DB::table('review_moderation_policies')
            ->where('category', $validated['reason_category'])
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('version');

        $caseId = DB::table('review_moderation_cases')->insertGetId([
            'review_id' => $id,
            'reason_category' => $validated['reason_category'],
            'policy_version' => $policyVersion !== '' ? $policyVersion : 'v1',
            'status' => 'open',
            'requested_by_user_id' => $request->user()?->id,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'created_at_utc' => now()->utc(),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('review_moderation_cases')->where('id', $caseId)->first()], 201);
    }

    public function reviewHide(Request $request, int $id): JsonResponse
    {
        if (! DB::table('reviews')->where('id', $id)->exists()) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        DB::table('reviews')->where('id', $id)->update([
            'visibility_status' => 'hidden',
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('reviews')->where('id', $id)->first()]);
    }

    public function listReviews(Request $request): JsonResponse
    {
        $query = DB::table('reviews');
        foreach (['facility_id', 'provider_id', 'rating'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    private function snapshotContentVersion(int $contentId, string $status, ?int $userId): void
    {
        $item = (array) DB::table('content_items')->where('id', $contentId)->first();
        DB::table('content_versions')->insert([
            'content_item_id' => $contentId,
            'version_number' => (int) $item['current_version'],
            'snapshot_json' => json_encode($item, JSON_THROW_ON_ERROR),
            'status' => $status,
            'created_by_user_id' => $userId,
            'created_at_utc' => now()->utc(),
        ]);
    }
}
