<?php

namespace App\Http\Controllers\Api;

use App\Events\AgreementActivated;
use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgreementController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = Agreement::with('partner');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'agreement_type' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_terms' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'document_path' => 'nullable|string',
        ]);

        $partner = \App\Models\Partner::findOrFail($data['partner_id']);

        $agreement = Agreement::create($data + [
            'business_id' => $partner->business_id,
            'agreement_number' => 'AGR-'.$partner->business_id.'-'.Str::upper(Str::random(6)),
            'status' => 'draft',
            'version' => 1,
            'created_by' => $user->id,
        ]);

        return response()->json($agreement->load('partner'), 201);
    }

    public function show(Agreement $agreement)
    {
        return $agreement->load('partner', 'parentAgreement', 'versions');
    }

    public function update(Request $request, Agreement $agreement)
    {
        $data = $request->validate([
            'agreement_type' => 'nullable|string|max:255',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_terms' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'document_path' => 'nullable|string',
        ]);

        $agreement->update($data);

        return $agreement;
    }

    public function activate(Agreement $agreement)
    {
        abort_if($agreement->status === 'active', 422, 'Agreement is already active.');

        $agreement->update(['status' => 'active']);

        event(new AgreementActivated($agreement));

        return $agreement->fresh('partner');
    }

    public function terminate(Agreement $agreement)
    {
        $agreement->update(['status' => 'terminated']);

        return $agreement;
    }

    /** Create a new version of an agreement (renewal), superseding the current one. */
    public function renew(Request $request, Agreement $agreement)
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_terms' => 'nullable|string',
            'responsibilities' => 'nullable|string',
        ]);

        $agreement->update(['status' => 'renewed']);

        $newAgreement = Agreement::create($data + [
            'business_id' => $agreement->business_id,
            'partner_id' => $agreement->partner_id,
            'agreement_number' => $agreement->agreement_number,
            'agreement_type' => $agreement->agreement_type,
            'status' => 'active',
            'version' => $agreement->version + 1,
            'parent_agreement_id' => $agreement->id,
            'created_by' => $request->user()->id,
        ]);

        event(new AgreementActivated($newAgreement));

        return response()->json($newAgreement->load('partner'), 201);
    }

    public function destroy(Agreement $agreement)
    {
        $agreement->delete();

        return response()->json(null, 204);
    }
}
