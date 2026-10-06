<?php

namespace App\Services;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Collection;

/**
 * Suggests eligible active partners for a new assignment by location + category match,
 * used by the Assignments module's partner-selection step.
 */
class AssignmentMatchingService
{
    public function suggestPartners(int $businessId, ?int $locationId, ?int $partnerCategoryId, ?int $partnerTypeId = null): Collection
    {
        $query = Partner::query()
            ->where('business_id', $businessId)
            ->where('status', 'active');

        if ($partnerTypeId) {
            // Match via an active enrollment of this type (multi-enrollment aware), falling
            // back to the legacy partner_type_id for partners not yet enrollment-backfilled.
            $query->where(function ($q) use ($partnerTypeId, $partnerCategoryId) {
                $q->whereHas('enrollments', function ($eq) use ($partnerTypeId, $partnerCategoryId) {
                    $eq->where('partner_type_id', $partnerTypeId)->where('status', 'active');
                    if ($partnerCategoryId) {
                        $eq->where('partner_category_id', $partnerCategoryId);
                    }
                })->orWhere(function ($legacy) use ($partnerTypeId, $partnerCategoryId) {
                    $legacy->where('partner_type_id', $partnerTypeId);
                    if ($partnerCategoryId) {
                        $legacy->where('partner_category_id', $partnerCategoryId);
                    }
                });
            });
        } elseif ($partnerCategoryId) {
            $query->where('partner_category_id', $partnerCategoryId);
        }

        if ($locationId) {
            $query->where(function ($q) use ($locationId) {
                $q->where('primary_location_id', $locationId)
                    ->orWhereHas('serviceAreas', fn ($sq) => $sq->where('locations.id', $locationId));
            });
        }

        return $query->withCount(['assignments as active_assignments_count' => function ($q) {
            $q->whereIn('status', ['pending', 'accepted', 'in_progress']);
        }])->orderBy('active_assignments_count')->get();
    }
}
