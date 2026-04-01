<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RentalsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function listAssets(Request $request): JsonResponse
    {
        $query = DB::table('rental_assets');
        if ($request->filled('q')) {
            $q = (string) $request->query('q');
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$q}%")
                ->orWhere('asset_code', 'like', "%{$q}%"));
        }
        foreach (['status', 'facility_id', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('scan_code')) {
            $scanCode = (string) $request->query('scan_code');
            $query->where(fn ($builder) => $builder->where('qr_code', $scanCode)->orWhere('barcode', $scanCode));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function createAsset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['required', 'integer'],
            'asset_code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'replacement_cost_cents' => ['required', 'integer', 'min:1'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'weekly_rate_cents' => ['nullable', 'integer', 'min:0'],
            'deposit_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        $replacement = (int) $validated['replacement_cost_cents'];
        $defaultDeposit = max((int) round($replacement * 0.2), 5000);
        $deposit = (int) ($validated['deposit_cents'] ?? $defaultDeposit);

        $assetId = DB::transaction(function () use ($validated, $deposit, $request) {
            $id = DB::table('rental_assets')->insertGetId([
                'facility_id' => $validated['facility_id'],
                'asset_code' => $validated['asset_code'],
                'name' => $validated['name'],
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
        $payload = $request->only([
            'name', 'category', 'photo_url', 'spec_json',
            'current_location', 'daily_rate_cents', 'weekly_rate_cents',
            'deposit_cents', 'replacement_cost_cents',
        ]);
        $payload['updated_at'] = now()->utc();

        DB::table('rental_assets')->where('id', $id)->update($payload);

        return response()->json(['data' => DB::table('rental_assets')->where('id', $id)->first()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'integer'],
            'renter_type' => ['required', 'string'],
            'renter_id' => ['required', 'integer'],
            'checked_out_at' => ['required', 'date'],
            'expected_return_at' => ['required', 'date', 'after:checked_out_at'],
            'pricing_mode' => ['required', 'in:daily,weekly'],
            'deposit_cents' => ['required', 'integer', 'min:0'],
            'fee_terms' => ['nullable', 'string'],
        ]);

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
                            ApiResponse::requestId(request())),
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

        return response()->json(['data' => DB::table('rental_checkouts')->where('id', $checkoutId)->first()], 201);
    }

    public function returnCheckout(Request $request, int $id): JsonResponse
    {
        $checkout = DB::table('rental_checkouts')->where('id', $id)->first();
        if ($checkout === null || $checkout->returned_at !== null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Checkout not found.', ApiResponse::requestId($request)), 404);
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

        return response()->json([], 204);
    }

    public function getCheckout(int $id): JsonResponse
    {
        $checkout = DB::table('rental_checkouts')->where('id', $id)->first();
        if ($checkout === null) {
            return response()->json(
                ApiResponse::error('NOT_FOUND', 'Checkout not found.',
                    ApiResponse::requestId(request())),
                404
            );
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
                'updated_at' => now()->utc(),
            ]);
        });

        return response()->json(['data' => DB::table('asset_transfers')->where('id', $id)->first()]);
    }
}
