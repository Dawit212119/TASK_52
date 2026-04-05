<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FacilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function items(Request $request): JsonResponse
    {
        $facilityId = (int) $request->query('facility_id', 0);
        $storeroomId = (int) $request->query('storeroom_id', 0);
        $usageWindowStart = now()->utc()->subDays(30);

        if ($facilityId > 0 && ! FacilityScope::canAccessFacility($request->user(), $facilityId)) {
            return FacilityScope::denyResponse($request);
        }

        $isAdmin = FacilityScope::isSystemAdmin($request->user());
        $allowedFacilityIds = FacilityScope::userFacilityIds($request->user());

        $ledger = DB::table('stock_ledger_entries')
            ->when($facilityId > 0, fn ($q) => $q->where('facility_id', $facilityId))
            ->when(! $isAdmin && $facilityId === 0, fn ($q) => $q->whereIn('facility_id', $allowedFacilityIds))
            ->when($storeroomId > 0, fn ($q) => $q->where('storeroom_id', $storeroomId))
            ->selectRaw("item_id,
                SUM(CASE WHEN movement_type IN ('inbound','transfer_in')
                    THEN qty ELSE -qty END) as on_hand,
                SUM(CASE WHEN movement_type = 'outbound' AND created_at_utc >= ? THEN qty ELSE 0 END) as used_30d", [$usageWindowStart])
            ->groupBy('item_id')
            ->get()->keyBy('item_id');

        $reserved = DB::table('inventory_reservation_events')
            ->join('storerooms', 'storerooms.id', '=', 'inventory_reservation_events.storeroom_id')
            ->when($facilityId > 0, fn ($q) => $q->where('storerooms.facility_id', $facilityId))
            ->when(! $isAdmin && $facilityId === 0, fn ($q) => $q->whereIn('storerooms.facility_id', $allowedFacilityIds))
            ->when($storeroomId > 0, fn ($q) => $q->where('storeroom_id', $storeroomId))
            ->selectRaw("item_id,
                SUM(CASE WHEN event_type IN ('reserve','plan') THEN qty
                     WHEN event_type IN ('consume','release') THEN -qty
                     ELSE 0 END) as demand")
            ->groupBy('item_id')
            ->get()->keyBy('item_id');

        $items = DB::table('inventory_items')->get()->map(function ($item) use ($ledger, $reserved) {
            $l = $ledger->get($item->id);
            $onHand = $l ? (float) $l->on_hand : 0.0;
            $demand = $reserved->has($item->id) ? (float) $reserved->get($item->id)->demand : 0.0;
            $atp    = $onHand - $demand;
            $daily  = $l ? max((float) $l->used_30d / 30, 0) : 0.0;
            $safety = $daily * (float) $item->safety_stock_days;

            return [
                'id'           => $item->id,
                'sku'          => $item->sku,
                'name'         => $item->name,
                'on_hand'      => round($onHand, 3),
                'reserved'     => round($demand, 3),
                'atp'          => round($atp, 3),
                'safety_stock' => round($safety, 3),
                'low_stock'    => $atp < $safety,
            ];
        });

        return response()->json(['data' => $items]);
    }

    public function receipt(Request $request): JsonResponse
    {
        return $this->createLedgerEntry($request, 'inbound');
    }

    public function issue(Request $request): JsonResponse
    {
        return $this->createLedgerEntry($request, 'outbound');
    }

    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'from_storeroom_id' => ['required', 'integer', 'exists:storerooms,id'],
            'to_storeroom_id' => ['required', 'integer', 'exists:storerooms,id', 'different:from_storeroom_id'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);

        if (! FacilityScope::canAccessFacility($request->user(), (int) $validated['facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $fromFacilityId = (int) DB::table('storerooms')->where('id', $validated['from_storeroom_id'])->value('facility_id');
        $toFacilityId = (int) DB::table('storerooms')->where('id', $validated['to_storeroom_id'])->value('facility_id');
        if ($fromFacilityId !== (int) $validated['facility_id'] || $toFacilityId !== (int) $validated['facility_id']) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Transfer storerooms must belong to the selected facility.', ApiResponse::requestId($request)), 422);
        }

        DB::transaction(function () use ($validated, $request) {
            foreach ([['transfer_out', $validated['from_storeroom_id']], ['transfer_in', $validated['to_storeroom_id']]] as [$movement, $storeroom]) {
                DB::table('stock_ledger_entries')->insert([
                    'item_id' => $validated['item_id'],
                    'facility_id' => $validated['facility_id'],
                    'storeroom_id' => $storeroom,
                    'movement_type' => $movement,
                    'qty' => $validated['qty'],
                    'reference_type' => 'inventory_transfer',
                    'created_by_user_id' => $request->user()?->id,
                    'created_at_utc' => now()->utc(),
                ]);
            }
        });

        return response()->json(['data' => ['status' => 'ok']], 201);
    }

    public function createStocktake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'storeroom_id' => ['required', 'integer', 'exists:storerooms,id'],
        ]);

        if (! FacilityScope::canAccessFacility($request->user(), (int) $validated['facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $storeroomFacilityId = (int) DB::table('storerooms')->where('id', $validated['storeroom_id'])->value('facility_id');
        if ($storeroomFacilityId !== (int) $validated['facility_id']) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Storeroom does not belong to facility.', ApiResponse::requestId($request)), 422);
        }

        $id = DB::table('stocktakes')->insertGetId([
            'facility_id' => $validated['facility_id'],
            'storeroom_id' => $validated['storeroom_id'],
            'status' => 'submitted',
            'created_by_user_id' => $request->user()?->id,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('stocktakes')->where('id', $id)->first()], 201);
    }

    public function addStocktakeLines(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['required', 'numeric'],
            'lines.*.variance_reason' => ['nullable', 'string'],
        ]);

        $stocktake = DB::table('stocktakes')->where('id', $id)->first();
        if ($stocktake === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Stocktake not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($request->user(), (int) $stocktake->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        $requiresApproval = false;
        foreach ($validated['lines'] as $line) {
            $systemQty = (float) DB::table('stock_ledger_entries')
                ->where('item_id', $line['item_id'])
                ->where('storeroom_id', $stocktake->storeroom_id)
                ->sum(DB::raw("CASE WHEN movement_type IN ('inbound', 'transfer_in') THEN qty ELSE -qty END"));

            $counted = (float) $line['counted_qty'];
            $variancePct = $systemQty == 0.0 ? ($counted > 0 ? 100.0 : 0.0) : (($counted - $systemQty) / max(abs($systemQty), 0.001) * 100.0);
            $requiresLineApproval = abs($variancePct) > 5;

            if ($requiresLineApproval && empty($line['variance_reason'])) {
                return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Variance reason required when above +/-5%.', ApiResponse::requestId($request)), 422);
            }

            DB::table('stocktake_lines')->insert([
                'stocktake_id' => $id,
                'item_id' => $line['item_id'],
                'system_qty' => $systemQty,
                'counted_qty' => $counted,
                'variance_pct' => $variancePct,
                'variance_reason' => $line['variance_reason'] ?? null,
                'requires_manager_approval' => $requiresLineApproval,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            $requiresApproval = $requiresApproval || $requiresLineApproval;
        }

        DB::table('stocktakes')->where('id', $id)->update([
            'status' => $requiresApproval ? 'pending_approval' : 'approved',
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('stocktakes')->where('id', $id)->first()]);
    }

    public function approveVariance(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'inventory.stocktake.approve')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Manager approval required.', ApiResponse::requestId($request)), 403);
        }

        $reason = (string) $request->input('reason', 'Approved by manager');
        $stocktake = DB::table('stocktakes')->where('id', $id)->first();
        if ($stocktake === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Stocktake not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($user, (int) $stocktake->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        DB::table('stocktakes')->where('id', $id)->update([
            'status' => 'approved',
            'approved_by_user_id' => $user->id,
            'approved_at' => now()->utc(),
            'approval_reason' => $reason,
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('stocktakes')->where('id', $id)->first()]);
    }

    public function setReservationStrategy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'strategy' => ['required', 'in:reserve_on_order_create,deduct_on_order_close'],
        ]);

        $serviceFacilityId = (int) DB::table('services')->where('id', $validated['service_id'])->value('facility_id');
        if (! FacilityScope::canAccessFacility($request->user(), $serviceFacilityId)) {
            return FacilityScope::denyResponse($request);
        }

        DB::table('services')->where('id', $validated['service_id'])->update([
            'reservation_strategy' => $validated['strategy'],
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => ['status' => 'ok']]);
    }

    public function reserveServiceOrder(Request $request, int $serviceOrderId): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'storeroom_id' => ['required', 'integer', 'exists:storerooms,id'],
            'strategy' => ['nullable', 'string', 'in:reserve_on_order_create,deduct_on_order_close'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $serviceFacilityId = (int) DB::table('services')->where('id', $validated['service_id'])->value('facility_id');
        $facilityId = (int) DB::table('storerooms')
            ->where('id', $validated['storeroom_id'])
            ->value('facility_id');

        if ($serviceFacilityId !== $facilityId) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Service and storeroom must be in the same facility.', ApiResponse::requestId($request)), 422);
        }

        if (! FacilityScope::canAccessFacility($request->user(), $facilityId)) {
            return FacilityScope::denyResponse($request);
        }

        // Determine strategy: use order's stored strategy if exists, else request override, else service default
        $existingOrder = DB::table('service_orders')->where('id', $serviceOrderId)->first();

        if ($existingOrder !== null && ! empty($existingOrder->reservation_strategy)) {
            $strategy = (string) $existingOrder->reservation_strategy;
            // If caller provided a different strategy, reject the mismatch
            if (isset($validated['strategy']) && $validated['strategy'] !== $strategy) {
                return response()->json(ApiResponse::error('STRATEGY_MISMATCH', 'Cannot change reservation strategy on an existing service order.', ApiResponse::requestId($request)), 409);
            }
        } else {
            // New order or order without strategy: use request override or service default
            if (isset($validated['strategy']) && $validated['strategy'] !== '') {
                $strategy = $validated['strategy'];
            } else {
                $strategy = (string) DB::table('services')->where('id', $validated['service_id'])->value('reservation_strategy');
                $strategy = $strategy !== '' ? $strategy : 'reserve_on_order_create';
            }
        }

        $eventType = $strategy === 'reserve_on_order_create' ? 'reserve' : 'plan';

        DB::transaction(function () use ($validated, $serviceOrderId, $strategy, $eventType, $facilityId, $request, $existingOrder) {
            if ($existingOrder === null) {
                DB::table('service_orders')->insert([
                    'id' => $serviceOrderId,
                    'facility_id' => $facilityId,
                    'service_id' => $validated['service_id'],
                    'reservation_strategy' => $strategy,
                    'status' => 'open',
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            } elseif (empty($existingOrder->reservation_strategy)) {
                // Backfill strategy on existing order that didn't have one
                DB::table('service_orders')->where('id', $serviceOrderId)->update([
                    'reservation_strategy' => $strategy,
                    'updated_at' => now()->utc(),
                ]);
            }

            foreach ($validated['lines'] as $line) {
                DB::table('inventory_reservation_events')->insert([
                    'service_order_id' => $serviceOrderId,
                    'service_id' => $validated['service_id'],
                    'item_id' => $line['item_id'],
                    'storeroom_id' => $validated['storeroom_id'],
                    'event_type' => $eventType,
                    'qty' => $line['qty'],
                    'strategy' => $strategy,
                    'created_by_user_id' => $request->user()?->id,
                    'created_at_utc' => now()->utc(),
                ]);
            }
        });

        return response()->json(['data' => ['status' => 'ok', 'strategy' => $strategy]], 201);
    }

    public function closeServiceOrder(Request $request, int $serviceOrderId): JsonResponse
    {
        $order = DB::table('service_orders')->where('id', $serviceOrderId)->first();
        if ($order === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Service order not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::canAccessFacility($request->user(), (int) $order->facility_id)) {
            return FacilityScope::denyResponse($request);
        }

        if ($order->status === 'closed') {
            return response()->json(['data' => ['status' => 'already_closed']], 200);
        }

        // Use the order's stored strategy, not the current service default
        $orderStrategy = ! empty($order->reservation_strategy) ? (string) $order->reservation_strategy : null;

        $events = DB::table('inventory_reservation_events')->where('service_order_id', $serviceOrderId)->get();

        $facilityId = (int) $order->facility_id;
        if ($events->isNotEmpty()) {
            $eventFacilityId = (int) DB::table('storerooms')
                ->where('id', (int) $events->first()->storeroom_id)
                ->value('facility_id');
            if ($eventFacilityId > 0) {
                $facilityId = $eventFacilityId;
            }
        }

        DB::transaction(function () use ($events, $serviceOrderId, $facilityId, $request, $orderStrategy) {
            foreach ($events as $event) {
                // Use order-level strategy if available, else fall back to event's recorded strategy
                $effectiveStrategy = $orderStrategy ?? $event->strategy;
                if ($effectiveStrategy === 'deduct_on_order_close' && $event->event_type === 'plan') {
                    DB::table('inventory_reservation_events')->insert([
                        'service_order_id' => $serviceOrderId,
                        'service_id' => $event->service_id,
                        'item_id' => $event->item_id,
                        'storeroom_id' => $event->storeroom_id,
                        'event_type' => 'consume',
                        'qty' => $event->qty,
                        'strategy' => $event->strategy,
                        'created_by_user_id' => $request->user()?->id,
                        'created_at_utc' => now()->utc(),
                    ]);

                    DB::table('stock_ledger_entries')->insert([
                        'item_id' => $event->item_id,
                        'facility_id' => $facilityId,
                        'storeroom_id' => $event->storeroom_id,
                        'movement_type' => 'outbound',
                        'qty' => $event->qty,
                        'reference_type' => 'service_order',
                        'reference_id' => $serviceOrderId,
                        'created_by_user_id' => $request->user()?->id,
                        'created_at_utc' => now()->utc(),
                    ]);
                }

                if ($effectiveStrategy === 'reserve_on_order_create' && $event->event_type === 'reserve') {
                    DB::table('inventory_reservation_events')->insert([
                        'service_order_id' => $serviceOrderId,
                        'service_id' => $event->service_id,
                        'item_id' => $event->item_id,
                        'storeroom_id' => $event->storeroom_id,
                        'event_type' => 'release',
                        'qty' => $event->qty,
                        'strategy' => $event->strategy,
                        'created_by_user_id' => $request->user()?->id,
                        'created_at_utc' => now()->utc(),
                    ]);

                    DB::table('stock_ledger_entries')->insert([
                        'item_id' => $event->item_id,
                        'facility_id' => $facilityId,
                        'storeroom_id' => $event->storeroom_id,
                        'movement_type' => 'outbound',
                        'qty' => $event->qty,
                        'reference_type' => 'service_order',
                        'reference_id' => $serviceOrderId,
                        'created_by_user_id' => $request->user()?->id,
                        'created_at_utc' => now()->utc(),
                    ]);
                }
            }

            DB::table('service_orders')->where('id', $serviceOrderId)->update([
                'status' => 'closed',
                'updated_at' => now()->utc(),
            ]);
        });

        return response()->json(['data' => ['status' => 'closed']]);
    }

    private function createLedgerEntry(Request $request, string $movement): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'storeroom_id' => ['required', 'integer', 'exists:storerooms,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string'],
        ]);

        if (! FacilityScope::canAccessFacility($request->user(), (int) $validated['facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $storeroomFacilityId = (int) DB::table('storerooms')->where('id', $validated['storeroom_id'])->value('facility_id');
        if ($storeroomFacilityId !== (int) $validated['facility_id']) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Storeroom does not belong to facility.', ApiResponse::requestId($request)), 422);
        }

        $id = DB::table('stock_ledger_entries')->insertGetId([
            'item_id' => $validated['item_id'],
            'facility_id' => $validated['facility_id'],
            'storeroom_id' => $validated['storeroom_id'],
            'movement_type' => $movement,
            'qty' => $validated['qty'],
            'reason' => $validated['reason'] ?? null,
            'created_by_user_id' => $request->user()?->id,
            'created_at_utc' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'inventory', $movement, 'success', $request->user(), ['ledger_id' => $id]);

        return response()->json(['data' => DB::table('stock_ledger_entries')->where('id', $id)->first()], 201);
    }
}
