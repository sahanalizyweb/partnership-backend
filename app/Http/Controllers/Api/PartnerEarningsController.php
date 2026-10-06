<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\PartnerEarningsService;
use Illuminate\Http\Request;

/**
 * LizyMart/LizyNet + partner-type earnings, combined into a backend-calculated Total Partner
 * Earnings. Self-service (me...) routes are unpermissioned and always resolve from the
 * logged-in user's own partner_id, same pattern as PartnerController::me.
 */
class PartnerEarningsController extends Controller
{
    public function __construct(protected PartnerEarningsService $earnings) {}

    public function mySummary(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'Only partner-portal accounts have earnings here.');

        return $this->earnings->summary(Partner::findOrFail($user->partner_id));
    }

    public function myTransactions(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'Only partner-portal accounts have earnings here.');

        return $this->earnings->transactions(Partner::findOrFail($user->partner_id), $request->only(['business_source_id', 'status', 'partner_type']));
    }

    public function summary(Partner $partner)
    {
        return $this->earnings->summary($partner);
    }

    public function transactions(Request $request, Partner $partner)
    {
        return $this->earnings->transactions($partner, $request->only(['business_source_id', 'status', 'partner_type']));
    }
}
