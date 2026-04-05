<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacilityScope
{
    /**
     * @return list<int>
     */
    public static function userFacilityIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $user->loadMissing('facilities');

        return $user->facilities->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    public static function isSystemAdmin(?User $user): bool
    {
        return $user !== null && $user->hasRole('system_admin');
    }

    public static function canAccessFacility(?User $user, int $facilityId): bool
    {
        if ($facilityId <= 0) {
            return false;
        }

        if (self::isSystemAdmin($user)) {
            return true;
        }

        return in_array($facilityId, self::userFacilityIds($user), true);
    }

    public static function denyResponse(Request $request): JsonResponse
    {
        return response()->json(
            ApiResponse::error('FORBIDDEN', 'Access to this facility is not allowed for the current user.', ApiResponse::requestId($request)),
            403,
        );
    }

    public static function applyToQuery(Builder $query, ?User $user, string $column = 'facility_id'): Builder
    {
        if (self::isSystemAdmin($user)) {
            return $query;
        }

        $facilityIds = self::userFacilityIds($user);
        if ($facilityIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $facilityIds);
    }
}
