<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FacilityScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentReviewsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function listContent(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = max((int) $request->query('page', 1), 1);

        $query = DB::table('content_items as ci')
            ->leftJoin('content_targets as ct', 'ct.content_item_id', '=', 'ci.id')
            ->select('ci.*', 'ct.facility_ids as target_facility_ids')
            ->orderByDesc('ci.updated_at');

        if ($request->filled('status')) {
            $query->where('ci.status', $request->input('status'));
        }
        if ($request->filled('content_type')) {
            $query->where('ci.content_type', $request->input('content_type'));
        }

        $items = $query->get();
        if (! FacilityScope::isSystemAdmin($request->user())) {
            $allowedFacilityIds = FacilityScope::userFacilityIds($request->user());
            $items = $items
                ->filter(function (object $item) use ($allowedFacilityIds): bool {
                    $targetFacilityIds = $this->decodeFacilityIds($item->target_facility_ids ?? null);
                    if ($targetFacilityIds === []) {
                        return true;
                    }

                    return collect($targetFacilityIds)->contains(
                        fn (int $facilityId): bool => in_array($facilityId, $allowedFacilityIds, true)
                    );
                })
                ->values();
        }

        $total = $items->count();
        $data = $items
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(function (object $item): array {
                $payload = (array) $item;
                unset($payload['target_facility_ids']);

                return $payload;
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / max($perPage, 1)), 1),
                'total' => $total,
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function publicContent(Request $request): JsonResponse
    {
        $contentType = (string) $request->query('content_type', 'homepage_carousel');
        if (! in_array($contentType, ['announcement', 'homepage_carousel'], true)) {
            return response()->json(
                ApiResponse::error('VALIDATION_ERROR', 'Unsupported content_type filter.', ApiResponse::requestId($request)),
                422,
            );
        }

        $facilityId = $request->filled('facility_id') ? (int) $request->query('facility_id') : null;
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $roleCode = $request->filled('role_code') ? (string) $request->query('role_code') : null;
        $tag = $request->filled('tag') ? (string) $request->query('tag') : null;

        $items = DB::table('content_items as ci')
            ->join('content_targets as ct', 'ct.content_item_id', '=', 'ci.id')
            ->where('ci.status', 'published')
            ->where('ci.content_type', $contentType)
            ->when($facilityId !== null, fn ($q) => $q
                ->where(function ($inner) use ($facilityId) {
                    $inner->whereNull('ct.facility_ids')
                        ->orWhereRaw('JSON_LENGTH(ct.facility_ids) = 0')
                        ->orWhereRaw('JSON_CONTAINS(ct.facility_ids, ?)', [json_encode($facilityId, JSON_THROW_ON_ERROR)]);
                }))
            ->when($departmentId !== null, fn ($q) => $q
                ->where(function ($inner) use ($departmentId) {
                    $inner->whereNull('ct.department_ids')
                        ->orWhereRaw('JSON_LENGTH(ct.department_ids) = 0')
                        ->orWhereRaw('JSON_CONTAINS(ct.department_ids, ?)', [json_encode($departmentId, JSON_THROW_ON_ERROR)]);
                }))
            ->when($roleCode !== null, fn ($q) => $q
                ->where(function ($inner) use ($roleCode) {
                    $inner->whereNull('ct.role_codes')
                        ->orWhereRaw('JSON_LENGTH(ct.role_codes) = 0')
                        ->orWhereRaw('JSON_CONTAINS(ct.role_codes, ?)', [json_encode($roleCode, JSON_THROW_ON_ERROR)]);
                }))
            ->when($tag !== null, fn ($q) => $q
                ->where(function ($inner) use ($tag) {
                    $inner->whereNull('ct.tags')
                        ->orWhereRaw('JSON_LENGTH(ct.tags) = 0')
                        ->orWhereRaw('JSON_CONTAINS(ct.tags, ?)', [json_encode($tag, JSON_THROW_ON_ERROR)]);
                }))
            ->orderByDesc('ci.published_at')
            ->select('ci.id', 'ci.title', 'ci.body', 'ci.published_at')
            ->get();

        return response()->json([
            'data' => $items,
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

        if (! $this->canReadContentItem($request->user(), $id)) {
            return FacilityScope::denyResponse($request);
        }

        return response()->json([
            'data'       => $item,
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function createPublicReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_order_id' => 'required|string|max:64',
            'token' => 'required|string',
            'rating' => 'required|integer|min:1|max:5',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'text' => 'nullable|string|max:5000',
            'facility_id' => 'nullable|integer|exists:facilities,id',
            'provider_id' => 'nullable|integer|exists:providers,id',
            'owner_phone' => 'nullable|string|max:32',
            'images' => 'nullable|array|max:5',
            'images.*' => 'file|image|max:5120',
        ]);

        $hash = hash('sha256', (string) $validated['token']);
        $row  = DB::table('visit_review_tokens')
            ->where('token_hash', $hash)
            ->where('visit_order_id', (string) $validated['visit_order_id'])
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

        if (DB::table('reviews')->where('visit_order_id', (string) $validated['visit_order_id'])->exists()) {
            return response()->json(
                ApiResponse::error('DUPLICATE_REVIEW', 'A review for this visit was already submitted.', ApiResponse::requestId($request)),
                409,
            );
        }

        try {
            $id = DB::table('reviews')->insertGetId([
                'visit_order_id' => (string) $validated['visit_order_id'],
                'facility_id' => $validated['facility_id'] ?? null,
                'provider_id' => $validated['provider_id'] ?? null,
                'rating' => (int) $validated['rating'],
                'tags' => json_encode($validated['tags'] ?? [], JSON_THROW_ON_ERROR),
                'text' => $validated['text'] ?? null,
                'owner_phone_encrypted' => isset($validated['owner_phone']) && $validated['owner_phone'] !== ''
                    ? Crypt::encryptString((string) $validated['owner_phone'])
                    : null,
                'visibility_status' => 'visible',
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        } catch (QueryException $exception) {
            return response()->json(
                ApiResponse::error('DUPLICATE_REVIEW', 'A review for this visit was already submitted.', ApiResponse::requestId($request)),
                409,
            );
        }

        $uploadedImages = $request->file('images', []);
        if (is_array($uploadedImages) && $uploadedImages !== []) {
            $this->storeUploadedReviewImages($id, $uploadedImages);
        }

        DB::table('visit_review_tokens')
            ->where('id', $row->id)
            ->update(['used_at' => now()->utc(), 'updated_at' => now()->utc()]);

        $this->auditLogger->log($request, 'reviews', 'public_submit', 'success', null, [
            'review_id' => $id,
            'visit_order_id' => (string) $validated['visit_order_id'],
            'image_count' => is_array($uploadedImages) ? count($uploadedImages) : 0,
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
            'facility_ids.*' => ['integer', 'exists:facilities,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'role_codes' => ['nullable', 'array'],
            'role_codes.*' => ['string', 'max:80'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
        ]);

        if (! FacilityScope::isSystemAdmin($request->user())) {
            $allowedFacilities = FacilityScope::userFacilityIds($request->user());
            $targetFacilities = collect($validated['facility_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();
            if ($targetFacilities === []) {
                $targetFacilities = $allowedFacilities;
            }

            $hasForbiddenTarget = collect($targetFacilities)->contains(fn (int $id): bool => ! in_array($id, $allowedFacilities, true));
            if ($hasForbiddenTarget || $targetFacilities === []) {
                return FacilityScope::denyResponse($request);
            }

            $validated['facility_ids'] = $targetFacilities;
        }

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

        if (! $this->canManageContentItem($request->user(), $id)) {
            return FacilityScope::denyResponse($request);
        }

        $payload = $request->only(['title', 'body']);
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'facility_ids' => ['sometimes', 'array'],
            'facility_ids.*' => ['integer', 'exists:facilities,id'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'role_codes' => ['sometimes', 'array'],
            'role_codes.*' => ['string', 'max:80'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:80'],
        ]);
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
            if (array_key_exists('facility_ids', $targetPayload) && ! FacilityScope::isSystemAdmin($request->user())) {
                $allowedFacilities = FacilityScope::userFacilityIds($request->user());
                $decoded = json_decode((string) $targetPayload['facility_ids'], true, 512, JSON_THROW_ON_ERROR);
                $hasForbiddenTarget = collect($decoded)->contains(fn ($id): bool => ! in_array((int) $id, $allowedFacilities, true));
                if ($hasForbiddenTarget) {
                    return FacilityScope::denyResponse($request);
                }
            }

            $targetPayload['updated_at'] = now()->utc();
            DB::table('content_targets')->where('content_item_id', $id)->update($targetPayload);
        }

        $this->snapshotContentVersion($id, (string) $item->status, $request->user()?->id);

        return response()->json(['data' => DB::table('content_items')->where('id', $id)->first()]);
    }

    public function submitApproval(Request $request, int $id): JsonResponse
    {
        if (! $this->canManageContentItem($request->user(), $id)) {
            return FacilityScope::denyResponse($request);
        }

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

        if (! $this->canManageContentItem($user, $id)) {
            return FacilityScope::denyResponse($request);
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

        if (! $this->canManageContentItem($user, $id)) {
            return FacilityScope::denyResponse($request);
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
        if (! $this->canManageContentItem($request->user(), $id)) {
            return FacilityScope::denyResponse($request);
        }

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

    public function versions(Request $request, int $id): JsonResponse
    {
        if (! $this->canReadContentItem($request->user(), $id)) {
            return FacilityScope::denyResponse($request);
        }

        return response()->json(['data' => DB::table('content_versions')->where('content_item_id', $id)->orderByDesc('version_number')->get()]);
    }

    public function createReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_order_id' => ['required', 'string', 'max:64'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'text' => ['nullable', 'string'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'owner_phone' => ['nullable', 'string', 'max:32'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'max:5120'],
        ]);

        $facilityId = isset($validated['facility_id']) ? (int) $validated['facility_id'] : null;
        if ($facilityId !== null && ! FacilityScope::canAccessFacility($request->user(), $facilityId)) {
            return FacilityScope::denyResponse($request);
        }

        if (DB::table('reviews')->where('visit_order_id', (string) $validated['visit_order_id'])->exists()) {
            return response()->json(
                ApiResponse::error('DUPLICATE_REVIEW', 'A review for this visit order already exists.', ApiResponse::requestId($request)),
                409,
            );
        }

        try {
            $reviewId = DB::table('reviews')->insertGetId([
                'visit_order_id' => (string) $validated['visit_order_id'],
                'facility_id' => $facilityId,
                'provider_id' => $validated['provider_id'] ?? null,
                'rating' => $validated['rating'],
                'tags' => json_encode($validated['tags'] ?? [], JSON_THROW_ON_ERROR),
                'text' => $validated['text'] ?? null,
                'owner_phone_encrypted' => isset($validated['owner_phone']) && $validated['owner_phone'] !== ''
                    ? Crypt::encryptString((string) $validated['owner_phone'])
                    : null,
                'visibility_status' => 'visible',
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        } catch (QueryException $exception) {
            return response()->json(
                ApiResponse::error('DUPLICATE_REVIEW', 'A review for this visit order already exists.', ApiResponse::requestId($request)),
                409,
            );
        }

        $uploadedImages = $request->file('images', []);
        if (is_array($uploadedImages) && $uploadedImages !== []) {
            $this->storeUploadedReviewImages($reviewId, $uploadedImages);
        }

        $review = DB::table('reviews')->where('id', $reviewId)->first();
        $media = DB::table('review_media')->where('review_id', $reviewId)->get();

        $this->auditLogger->log($request, 'reviews', 'create', 'success', $request->user(), [
            'review_id' => $reviewId,
            'image_count' => is_array($uploadedImages) ? count($uploadedImages) : 0,
        ]);

        return response()->json([
            'data' => $this->reviewPayload($review, $request->user()),
            'media' => $media,
        ], 201);
    }

    public function reviewResponse(Request $request, int $id): JsonResponse
    {
        $review = DB::table('reviews')->where('id', $id)->first();
        if ($review === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        if ($review->facility_id !== null && ! FacilityScope::canAccessFacility($request->user(), (int) $review->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        $validated = $request->validate(['response_text' => ['required', 'string', 'max:2000']]);
        DB::table('review_responses')->insert([
            'review_id' => $id,
            'responded_by_user_id' => $request->user()?->id,
            'response_text' => $validated['response_text'],
            'created_at_utc' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'reviews', 'respond', 'success', $request->user(), ['review_id' => $id]);

        return response()->json(['data' => ['status' => 'responded']]);
    }

    public function reviewAppeal(Request $request, int $id): JsonResponse
    {
        $review = DB::table('reviews')->where('id', $id)->first();
        if ($review === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        if ($review->facility_id !== null && ! FacilityScope::canAccessFacility($request->user(), (int) $review->facility_id)) {
            return FacilityScope::denyResponse($request);
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

        $this->auditLogger->log($request, 'reviews', 'appeal', 'success', $request->user(), [
            'review_id' => $id,
            'case_id' => $caseId,
            'reason_category' => $validated['reason_category'],
        ]);

        return response()->json(['data' => DB::table('review_moderation_cases')->where('id', $caseId)->first()], 201);
    }

    public function reviewHide(Request $request, int $id): JsonResponse
    {
        $review = DB::table('reviews')->where('id', $id)->first();
        if ($review === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Review not found.', ApiResponse::requestId($request)), 404);
        }

        if ($review->facility_id !== null && ! FacilityScope::canAccessFacility($request->user(), (int) $review->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        DB::table('reviews')->where('id', $id)->update([
            'visibility_status' => 'hidden',
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'reviews', 'hide', 'success', $request->user(), ['review_id' => $id]);

        return response()->json(['data' => $this->reviewPayload(DB::table('reviews')->where('id', $id)->first(), $request->user())]);
    }

    public function listReviews(Request $request): JsonResponse
    {
        $query = DB::table('reviews');

        if ($request->filled('facility_id')) {
            $requestedFacilityId = (int) $request->query('facility_id');
            if (! FacilityScope::canAccessFacility($request->user(), $requestedFacilityId)) {
                return FacilityScope::denyResponse($request);
            }
        }

        if (! FacilityScope::isSystemAdmin($request->user())) {
            $allowedFacilityIds = FacilityScope::userFacilityIds($request->user());
            $query->where(function ($scope) use ($allowedFacilityIds) {
                $scope->whereIn('facility_id', $allowedFacilityIds)
                    ->orWhereNull('facility_id');
            });
        }

        foreach (['facility_id', 'provider_id', 'rating'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        $rows = $query->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->reviewPayload($row, $request->user()))->values()->all(),
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    private function canReadContentItem(?User $user, int $contentId): bool
    {
        if (FacilityScope::isSystemAdmin($user)) {
            return true;
        }

        $targetFacilityIds = $this->contentTargetFacilityIds($contentId);
        if ($targetFacilityIds === []) {
            return true;
        }

        $allowedFacilityIds = FacilityScope::userFacilityIds($user);

        return collect($targetFacilityIds)->contains(
            fn (int $facilityId): bool => in_array($facilityId, $allowedFacilityIds, true)
        );
    }

    private function canManageContentItem(?User $user, int $contentId): bool
    {
        if (FacilityScope::isSystemAdmin($user)) {
            return true;
        }

        $targetFacilityIds = $this->contentTargetFacilityIds($contentId);
        if ($targetFacilityIds === []) {
            return false;
        }

        $allowedFacilityIds = FacilityScope::userFacilityIds($user);

        return collect($targetFacilityIds)->every(
            fn (int $facilityId): bool => in_array($facilityId, $allowedFacilityIds, true)
        );
    }

    /**
     * @return list<int>
     */
    private function contentTargetFacilityIds(int $contentId): array
    {
        $targetRow = DB::table('content_targets')
            ->where('content_item_id', $contentId)
            ->first();

        if ($targetRow === null) {
            return [];
        }

        return $this->decodeFacilityIds($targetRow->facility_ids ?? null);
    }

    /**
     * @return list<int>
     */
    private function decodeFacilityIds(mixed $rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }

        if (is_string($rawValue)) {
            try {
                $decoded = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return [];
            }

            if (! is_array($decoded)) {
                return [];
            }

            return collect($decoded)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if (is_array($rawValue)) {
            return collect($rawValue)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeUploadedReviewImages(int $reviewId, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = mb_strtolower((string) $file->getClientOriginalExtension());
            $safeExtension = $extension !== '' ? $extension : 'bin';
            $storedFileName = sprintf('%s.%s', Str::uuid(), $safeExtension);
            $storedPath = $file->storeAs("reviews/{$reviewId}", $storedFileName, 'local');
            $absolutePath = Storage::disk('local')->path($storedPath);
            $checksum = hash_file('sha256', $absolutePath);

            DB::table('review_media')->insert([
                'review_id' => $reviewId,
                'filename' => (string) $file->getClientOriginalName(),
                'path' => $absolutePath,
                'checksum_sha256' => $checksum,
                'bytes_size' => (int) filesize($absolutePath),
                'created_at_utc' => now()->utc(),
            ]);
        }
    }

    /**
     * @param  object|null  $review
     * @return array<string, mixed>|null
     */
    private function reviewPayload(?object $review, ?User $viewer): ?array
    {
        if ($review === null) {
            return null;
        }

        $tags = [];
        try {
            $tags = is_string($review->tags ?? null)
                ? (json_decode((string) $review->tags, true, 512, JSON_THROW_ON_ERROR) ?: [])
                : [];
        } catch (\Throwable) {
            $tags = [];
        }

        $ownerPhone = null;
        if (! empty($review->owner_phone_encrypted)) {
            try {
                $decrypted = Crypt::decryptString((string) $review->owner_phone_encrypted);
                $ownerPhone = FacilityScope::isSystemAdmin($viewer)
                    ? $decrypted
                    : $this->maskPhone($decrypted);
            } catch (\Throwable) {
                $ownerPhone = null;
            }
        }

        return [
            'id' => (int) $review->id,
            'visit_order_id' => $review->visit_order_id,
            'facility_id' => $review->facility_id,
            'provider_id' => $review->provider_id,
            'rating' => (int) $review->rating,
            'tags' => $tags,
            'text' => $review->text,
            'owner_phone' => $ownerPhone,
            'visibility_status' => $review->visibility_status,
            'created_at' => $review->created_at,
            'updated_at' => $review->updated_at,
        ];
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (mb_strlen($digits) < 4) {
            return '***';
        }

        $lastFour = mb_substr($digits, -4);
        $areaCode = mb_substr(str_pad($digits, 10, '0', STR_PAD_LEFT), -10, 3);

        return sprintf('(%s) ***-%s', $areaCode, $lastFour);
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
