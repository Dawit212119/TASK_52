<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FacilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RentalsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function listAssets(Request $request): JsonResponse
    {
        DB::table('rental_assets')
            ->whereIn('id', function ($query) {
                $query->select('asset_id')
                    ->from('rental_checkouts')
                    ->whereNull('returned_at')
                    ->where('expected_return_at', '<', now()->utc()->subHours(2));
            })
            ->whereNotIn('status', ['deactivated', 'maintenance'])
            ->update([
                'status' => 'overdue',
                'updated_at' => now()->utc(),
            ]);

        $activeCheckoutSubquery = DB::table('rental_checkouts')
            ->selectRaw('asset_id, MIN(expected_return_at) as expected_return_at')
            ->whereNull('returned_at')
            ->groupBy('asset_id');

        $query = DB::table('rental_assets as a')
            ->leftJoinSub($activeCheckoutSubquery, 'active_checkout', function ($join) {
                $join->on('active_checkout.asset_id', '=', 'a.id');
            })
            ->select('a.*', 'active_checkout.expected_return_at');

        $query = FacilityScope::applyToQuery($query, $request->user(), 'a.facility_id');

        if ($request->filled('q')) {
            $q = (string) $request->query('q');
            $query->where(fn ($builder) => $builder
                ->where('a.name', 'like', "%{$q}%")
                ->orWhere('a.asset_code', 'like', "%{$q}%"));
        }
        foreach (['status', 'facility_id', 'category'] as $filter) {
            if ($request->filled($filter)) {
                if ($filter === 'facility_id' && ! FacilityScope::canAccessFacility($request->user(), (int) $request->query($filter))) {
                    return FacilityScope::denyResponse($request);
                }

                $column = $filter === 'facility_id' ? 'a.facility_id' : "a.{$filter}";
                $query->where($column, $request->query($filter));
            }
        }
        if ($request->filled('scan_code')) {
            $scanCode = (string) $request->query('scan_code');
            $query->where(fn ($builder) => $builder->where('a.qr_code', $scanCode)->orWhere('a.barcode', $scanCode));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function createAsset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'asset_code' => ['required', 'string', 'max:120', 'unique:rental_assets,asset_code'],
            'qr_code' => ['nullable', 'string', 'max:120', 'unique:rental_assets,qr_code'],
            'barcode' => ['nullable', 'string', 'max:120', 'unique:rental_assets,barcode'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'photo_url' => ['nullable', 'string', 'max:1024'],
            'spec_json' => ['nullable', 'array'],
            'replacement_cost_cents' => ['required', 'integer', 'min:1'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'weekly_rate_cents' => ['nullable', 'integer', 'min:0'],
            'deposit_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! FacilityScope::canAccessFacility($request->user(), (int) $validated['facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $replacement = (int) $validated['replacement_cost_cents'];
        $defaultDeposit = max((int) round($replacement * 0.2), 5000);
        $deposit = (int) ($validated['deposit_cents'] ?? $defaultDeposit);

        $assetId = DB::transaction(function () use ($validated, $deposit, $request) {
            $id = DB::table('rental_assets')->insertGetId([
                'facility_id' => $validated['facility_id'],
                'asset_code' => $validated['asset_code'],
                'qr_code' => $validated['qr_code'] ?? null,
                'barcode' => $validated['barcode'] ?? null,
                'name' => $validated['name'],
                'category' => $validated['category'] ?? null,
                'photo_url' => $validated['photo_url'] ?? null,
                'spec_json' => isset($validated['spec_json']) ? json_encode($validated['spec_json'], JSON_THROW_ON_ERROR) : null,
                'replacement_cost_cents' => $validated['replacement_cost_cents'],
                'daily_rate_cents' => (int) ($validated['daily_rate_cents'] ?? 0),
                'weekly_rate_cents' => (int) ($validated['weekly_rate_cents'] ?? 0),
                'deposit_cents' => $deposit,
                'status' => 'available',
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            DB::table('rental_asset_ownership_history')->insert([
                'asset_id' => $id,
                'facility_id' => $validated['facility_id'],
                'effective_from_utc' => now()->utc(),
                'created_by_user_id' => $request->user()?->id,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            return $id;
        });

        $this->auditLogger->log($request, 'rentals', 'asset_create', 'success', $request->user(), ['asset_id' => $assetId]);

        return response()->json(['data' => DB::table('rental_assets')->where('id', $assetId)->first()], 201);
    }

    public function updateAsset(Request $request, int $id): JsonResponse
    {
        $existing = DB::table('rental_assets')->where('id', $id)->first();
        if ($existing === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Asset not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($request->user(), (int) $existing->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'photo_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'spec_json' => ['sometimes', 'nullable', 'array'],
            'current_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'daily_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'weekly_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'deposit_cents' => ['sometimes', 'integer', 'min:0'],
            'replacement_cost_cents' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['available', 'rented', 'maintenance', 'deactivated', 'overdue'])],
        ]);

        if (isset($payload['spec_json'])) {
            $payload['spec_json'] = $payload['spec_json'] !== null ? json_encode($payload['spec_json'], JSON_THROW_ON_ERROR) : null;
        }

        $payload['updated_at'] = now()->utc();

        DB::table('rental_assets')->where('id', $id)->update($payload);

        $this->auditLogger->log($request, 'rentals', 'asset_update', 'success', $request->user(), ['asset_id' => $id]);

        return response()->json(['data' => DB::table('rental_assets')->where('id', $id)->first()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:rental_assets,id'],
            'renter_type' => ['required', 'string'],
            'renter_id' => ['required', 'integer'],
            'checked_out_at' => ['required', 'date'],
            'expected_return_at' => ['required', 'date', 'after:checked_out_at'],
            'pricing_mode' => ['required', 'in:daily,weekly'],
            'deposit_cents' => ['required', 'integer', 'min:0'],
            'fee_terms' => ['nullable', 'string'],
        ]);

        $assetFacilityId = (int) DB::table('rental_assets')->where('id', $validated['asset_id'])->value('facility_id');
        if (! FacilityScope::canAccessFacility($request->user(), $assetFacilityId)) {
            return FacilityScope::denyResponse($request);
        }

        try {
            $checkoutId = DB::transaction(function () use ($validated, $request) {
            $existing = DB::table('rental_checkouts')
                ->where('asset_id', $validated['asset_id'])
                ->whereNull('returned_at')
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    response()->json(
                        ApiResponse::error('ASSET_ALREADY_CHECKED_OUT',
                            'This asset is already checked out.',
                            ApiResponse::requestId($request)),
                        409,
                    )
                );
            }

            $id = DB::table('rental_checkouts')->insertGetId([
                'asset_id' => $validated['asset_id'],
                'renter_type' => $validated['renter_type'],
                'renter_id' => $validated['renter_id'],
                'checked_out_at' => $validated['checked_out_at'],
                'expected_return_at' => $validated['expected_return_at'],
                'pricing_mode' => $validated['pricing_mode'],
                'deposit_cents' => $validated['deposit_cents'],
                'fee_terms' => $validated['fee_terms'] ?? null,
                'created_by_user_id' => $request->user()?->id,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            DB::table('rental_assets')->where('id', $validated['asset_id'])->update([
                'status' => 'rented',
                'updated_at' => now()->utc(),
            ]);

            return $id;
        });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->response;
        }

        $this->auditLogger->log($request, 'rentals', 'checkout', 'success', $request->user(), [
            'checkout_id' => $checkoutId,
            'asset_id' => (int) $validated['asset_id'],
        ]);

        return response()->json(['data' => DB::table('rental_checkouts')->where('id', $checkoutId)->first()], 201);
    }

    public function returnCheckout(Request $request, int $id): JsonResponse
    {
        $checkout = DB::table('rental_checkouts')->where('id', $id)->first();
        if ($checkout === null || $checkout->returned_at !== null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Checkout not found.', ApiResponse::requestId($request)), 404);
        }

        $assetFacilityId = (int) DB::table('rental_assets')->where('id', $checkout->asset_id)->value('facility_id');
        if (! FacilityScope::canAccessFacility($request->user(), $assetFacilityId)) {
            return FacilityScope::denyResponse($request);
        }

        DB::transaction(function () use ($checkout, $id, $request) {
            DB::table('rental_checkouts')->where('id', $id)->update([
                'returned_at' => now()->utc(),
                'closed_by_user_id' => $request->user()?->id,
                'updated_at' => now()->utc(),
            ]);
            DB::table('rental_assets')->where('id', $checkout->asset_id)->update([
                'status' => 'available',
                'updated_at' => now()->utc(),
            ]);
        });

        $this->auditLogger->log($request, 'rentals', 'return', 'success', $request->user(), ['checkout_id' => $id]);

        return response()->json([], 204);
    }

    public function getCheckout(Request $request, int $id): JsonResponse
    {
        $checkout = DB::table('rental_checkouts')->where('id', $id)->first();
        if ($checkout === null) {
            return response()->json(
                ApiResponse::error('NOT_FOUND', 'Checkout not found.',
                    ApiResponse::requestId($request)),
                404
            );
        }

        $assetFacilityId = (int) DB::table('rental_assets')->where('id', $checkout->asset_id)->value('facility_id');
        if (! FacilityScope::canAccessFacility($request->user(), $assetFacilityId)) {
            return FacilityScope::denyResponse($request);
        }

        $overdueAt = Carbon::parse($checkout->expected_return_at)->addHours(2);
        return response()->json([
            'data' => [
                ... (array) $checkout,
                'is_overdue' => $checkout->returned_at === null && now()->utc()->greaterThan($overdueAt),
                'overdue_at' => $overdueAt->toISOString(),
            ],
        ]);
    }

    public function requestTransfer(Request $request, int $assetId): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'rentals.transfer.request')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Only manager/admin can request transfer.', ApiResponse::requestId($request)), 403);
        }

        $validated = $request->validate([
            'to_facility_id' => ['required', 'integer'],
            'requested_effective_at' => ['required', 'date'],
            'reason' => ['required', 'string'],
        ]);

        $asset = DB::table('rental_assets')->where('id', $assetId)->first();
        if ($asset === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Asset not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($user, (int) $asset->facility_id) || ! FacilityScope::canAccessFacility($user, (int) $validated['to_facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $hasOpenCheckout = DB::table('rental_checkouts')->where('asset_id', $assetId)->whereNull('returned_at')->exists();
        if ($hasOpenCheckout || $asset->status === 'maintenance') {
            return response()->json(ApiResponse::error('TRANSFER_BLOCKED', 'Asset has open checkout or maintenance lock.', ApiResponse::requestId($request)), 409);
        }

        $transferId = DB::table('asset_transfers')->insertGetId([
            'asset_id' => $assetId,
            'from_facility_id' => $asset->facility_id,
            'to_facility_id' => $validated['to_facility_id'],
            'requested_effective_at' => $validated['requested_effective_at'],
            'reason' => $validated['reason'],
            'status' => 'requested',
            'requested_by_user_id' => $user->id,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'rentals', 'transfer_request', 'success', $user, ['transfer_id' => $transferId]);

        return response()->json(['data' => DB::table('asset_transfers')->where('id', $transferId)->first()], 201);
    }

    public function approveTransfer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'rentals.transfer.approve')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Only manager/admin can approve transfer.', ApiResponse::requestId($request)), 403);
        }

        $transfer = DB::table('asset_transfers')->where('id', $id)->first();
        if ($transfer === null || $transfer->status !== 'requested') {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Transfer request not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($user, (int) $transfer->from_facility_id) || ! FacilityScope::canAccessFacility($user, (int) $transfer->to_facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        DB::transaction(function () use ($transfer, $id, $user) {
            DB::table('asset_transfers')->where('id', $id)->update([
                'status' => 'completed',
                'decision_by_user_id' => $user->id,
                'decision_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            DB::table('rental_asset_ownership_history')
                ->where('asset_id', $transfer->asset_id)
                ->whereNull('effective_to_utc')
                ->update([
                    'effective_to_utc' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);

            DB::table('rental_asset_ownership_history')->insert([
                'asset_id' => $transfer->asset_id,
                'facility_id' => $transfer->to_facility_id,
                'effective_from_utc' => now()->utc(),
                'transfer_request_id' => $id,
                'created_by_user_id' => $user->id,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            DB::table('rental_assets')->where('id', $transfer->asset_id)->update([
                'facility_id' => $transfer->to_facility_id,
                'status' => 'available',
                'updated_at' => now()->utc(),
            ]);
        });

        $this->auditLogger->log($request, 'rentals', 'transfer_approve', 'success', $user, ['transfer_id' => $id]);

        return response()->json(['data' => DB::table('asset_transfers')->where('id', $id)->first()]);
    }
}
