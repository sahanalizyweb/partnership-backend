<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\PartnerTypeEnrollment;
use Illuminate\Http\Request;

/**
 * One Partner Profile -> many Partner Type Enrollments. Each is independently manageable
 * (status/dates/commission rule/settings). Row-level security mirrors the rest of this app's
 * hand-written ownership checks (no global policy layer exists here) — a partner-portal user
 * may only ever act on their own partner's enrollments.
 */
class PartnerTypeEnrollmentController extends Controller
{
    public function index(Request $request, Partner $partner)
    {
        $this->authorizeOwnPartner($request, $partner);

        return $partner->enrollments()->with('partnerType', 'partnerCategory', 'commissionRule')->get();
    }

    public function store(Request $request, Partner $partner)
    {
        $this->authorizeOwnPartner($request, $partner);

        $data = $request->validate([
            'partner_type_id' => 'required|exists:partner_types,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'commission_rule_id' => 'nullable|exists:commission_rules,id',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'settings' => 'nullable|array',
        ]);

        abort_if(
            $partner->enrollments()->where('partner_type_id', $data['partner_type_id'])->exists(),
            422,
            'Partner is already enrolled in this type.'
        );

        $type = PartnerType::findOrFail($data['partner_type_id']);

        $enrollment = $partner->enrollments()->create($data + [
            'business_id' => $partner->business_id,
            'enrollment_code' => $partner->partner_code.'-'.strtoupper($type->code),
            'status' => 'applied',
            'is_primary' => false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($enrollment->load('partnerType', 'partnerCategory'), 201);
    }

    public function update(Request $request, Partner $partner, PartnerTypeEnrollment $enrollment)
    {
        $this->authorizeOwnPartner($request, $partner);
        abort_if($enrollment->partner_id !== $partner->id, 404);

        $data = $request->validate([
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'commission_rule_id' => 'nullable|exists:commission_rules,id',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'settings' => 'nullable|array',
        ]);

        $enrollment->update($data);

        return $enrollment->fresh(['partnerType', 'partnerCategory']);
    }

    public function destroy(Request $request, Partner $partner, PartnerTypeEnrollment $enrollment)
    {
        $this->authorizeOwnPartner($request, $partner);
        abort_if($enrollment->partner_id !== $partner->id, 404);
        abort_if($enrollment->is_primary, 422, "Cannot remove the partner's primary enrollment — change the partner's primary type instead.");

        $enrollment->delete();

        return response()->json(null, 204);
    }

    public function activate(Request $request, Partner $partner, PartnerTypeEnrollment $enrollment)
    {
        abort_if($enrollment->partner_id !== $partner->id, 404);
        $enrollment->update(['status' => 'active', 'activated_at' => now()]);

        return $enrollment;
    }

    public function suspend(Request $request, Partner $partner, PartnerTypeEnrollment $enrollment)
    {
        abort_if($enrollment->partner_id !== $partner->id, 404);
        $enrollment->update(['status' => 'suspended']);

        return $enrollment;
    }

    /** Admin-only approval for a reseller enrollment's own-brand/white-label mode (requirement #18). */
    public function approveBrandMode(Request $request, Partner $partner, PartnerTypeEnrollment $enrollment)
    {
        abort_if($enrollment->partner_id !== $partner->id, 404);

        $settings = $enrollment->settings ?? [];
        $settings['brand_mode_status'] = 'approved';
        $enrollment->update(['settings' => $settings]);

        return $enrollment;
    }

    /** GET me/enrollments — every partner role the logged-in partner-portal user holds (drives the Partner Portal nav/dashboard). */
    public function mine(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'This endpoint is for partner-portal users only.');

        return Partner::findOrFail($user->partner_id)->portalEnrollments()->with('partnerType', 'partnerCategory')->get();
    }

    protected function authorizeOwnPartner(Request $request, Partner $partner): void
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $partner->id !== $user->partner_id, 403, 'You can only manage your own enrollments.');
    }
}
