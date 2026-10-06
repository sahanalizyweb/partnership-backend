<?php

namespace App\Http\Controllers\Api;

use App\Events\AssignmentCompleted;
use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Services\AssignmentMatchingService;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssignmentController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = Assignment::with(['partner', 'locationArea', 'product:id,name','partnerCategory', 'commissions', 'createdBy:id,partner_id']);
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['status', 'assignment_type', 'partner_id', 'location_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'business_id' => 'nullable|exists:businesses,id',
            'partner_id' => 'nullable|exists:partners,id',
            'partner_type_enrollment_id' => 'nullable|exists:partner_type_enrollments,id',
            'business_source_id' => 'nullable|exists:business_sources,id',
            'assignment_type' => 'required|in:lead,order,service,task,delivery',
            'reference_type' => 'nullable|string|max:255',
            'reference_id' => 'nullable|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'delivery_address' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id',
            'location' => 'nullable|string|max:500',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'product_id' => 'nullable|exists:products,id',
            'assigned_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'transaction_amount' => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'payment_direction' => 'nullable|in:customer_pays_partner,customer_pays_lizyweb',
        ]);

        $user = $request->user();

        // A partner-portal user can only ever create an assignment for themselves, regardless
        // of what (if anything) was submitted as partner_id.
        if ($user->isPartnerUser()) {
            $data['partner_id'] = $user->partner_id;
        }

        if (! empty($data['partner_id']) && empty($data['partner_type_enrollment_id'])) {
            // Link the job to the partner's matching role (service/delivery), so a partner with
            // several roles sees it under the right one; otherwise their primary role.
            $partnerForJob = \App\Models\Partner::find($data['partner_id']);
            $data['partner_type_enrollment_id'] = $partnerForJob?->enrollments()
                ->whereHas('partnerType', fn ($t) => $t->where('code', $data['assignment_type']))->value('id')
                ?? $partnerForJob?->primaryEnrollment?->id;
        }

        if (! empty($data['partner_id']) && empty($data['partner_category_id'])) {
            // Default the job's Category to the one the partner chose for this role (e.g. their
            // Service category), else the partner's own category.
            $enrollment = ! empty($data['partner_type_enrollment_id'])
                ? \App\Models\PartnerTypeEnrollment::find($data['partner_type_enrollment_id'])
                : null;
            $data['partner_category_id'] = $enrollment?->partner_category_id
                ?? \App\Models\Partner::whereKey($data['partner_id'])->value('partner_category_id');
        }

        // payment_direction only means anything for Service and Delivery work (for delivery it's
        // the Payment Method: customer pays Lizy, or pays the delivery partner on delivery / COD)
        // — drop it silently for every other type. A delivery's commission is unaffected by it.
        if (! in_array($data['assignment_type'], ['service', 'delivery'], true)) {
            unset($data['payment_direction']);
        }
        // Commission % applies to service and delivery work.
        if (! in_array($data['assignment_type'], ['service', 'delivery'], true)) {
            unset($data['commission_percent']);
        }

        // Scoped users always use their own business; a global user gets the selected partner's
        // business, falling back to the Business they picked for an unassigned job.
        $businessId = $user->business_id
            ?? (isset($data['partner_id']) ? \App\Models\Partner::findOrFail($data['partner_id'])->business_id : null)
            ?? ($data['business_id'] ?? null);
        unset($data['business_id']);

        abort_if(! $businessId, 422, 'Select a Business (or a partner) for this assignment.');

        $prefix = match ($data['assignment_type']) {
            'service' => 'SRV',
            'delivery' => 'DEL',
            default => 'ASG',
        };

        $assignment = Assignment::create($data + [
            'business_id' => $businessId,
            'reference_number' => $this->referenceNumbers->next($prefix, $businessId),
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $assignment->history()->create(['to_status' => 'pending', 'changed_by' => $user->id]);

        return response()->json($assignment->load('partner'), 201);
    }

    public function show(Request $request, Assignment $assignment)
    {
        $this->assertOwnAssignment($request, $assignment);

        return $assignment->load('partner', 'locationArea', 'product:id,name','partnerCategory', 'history.changedBy', 'commissions', 'reassignments', 'createdBy:id,partner_id');
    }

    public function update(Request $request, Assignment $assignment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can edit assignment details.');

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'delivery_address' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id',
            'location' => 'nullable|string|max:500',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'product_id' => 'nullable|exists:products,id',
            'assigned_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'transaction_amount' => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'payment_direction' => 'nullable|in:customer_pays_partner,customer_pays_lizyweb',
        ]);

        if (! in_array($assignment->assignment_type, ['service', 'delivery'], true)) {
            unset($data['payment_direction']);
        }
        if (! in_array($assignment->assignment_type, ['service', 'delivery'], true)) {
            unset($data['commission_percent']);
        }

        $assignment->update($data);

        return $assignment->load('product:id,name');
    }

    public function suggestPartners(Request $request, AssignmentMatchingService $matcher)
    {
        $data = $request->validate([
            'business_id' => 'required|exists:businesses,id',
            'location_id' => 'nullable|exists:locations,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'product_id' => 'nullable|exists:products,id',
            'partner_type_id' => 'nullable|exists:partner_types,id',
        ]);

        return $matcher->suggestPartners(
            $data['business_id'],
            $data['location_id'] ?? null,
            $data['partner_category_id'] ?? null,
            $data['partner_type_id'] ?? null
        );
    }

    public function assign(Request $request, Assignment $assignment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can assign assignments to a partner.');

        $data = $request->validate(['partner_id' => 'required|exists:partners,id']);

        $assignment->update(['partner_id' => $data['partner_id']]);

        return $assignment->fresh('partner');
    }

    public function accept(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);

        $this->transition($assignment, 'accepted', $request->user()->id);
        $assignment->update(['accepted_at' => now()]);

        return $assignment;
    }

    public function reject(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);

        $this->transition($assignment, 'rejected', $request->user()->id, $request->input('notes'));

        return $assignment;
    }

    public function start(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);

        $this->transition($assignment, 'in_progress', $request->user()->id);

        return $assignment;
    }

    public function complete(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);

        $data = $request->validate(['transaction_amount' => 'nullable|numeric|min:0']);

        if (array_key_exists('transaction_amount', $data) && $data['transaction_amount'] !== null) {
            $assignment->update(['transaction_amount' => $data['transaction_amount']]);
        }

        $this->transition($assignment, 'completed', $request->user()->id);
        $assignment->update(['completed_at' => now()]);

        event(new AssignmentCompleted($assignment->fresh()));

        return $assignment->fresh('commissions');
    }

    public function cancel(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);

        $this->transition($assignment, 'cancelled', $request->user()->id, $request->input('notes'));

        return $assignment;
    }

    /** Delivery-only lifecycle steps, same accept/reject pattern as the generic actions above. */
    public function pickUp(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);
        abort_unless($assignment->assignment_type === 'delivery', 422, 'Only delivery assignments have a pick-up step.');

        $this->transition($assignment, 'picked_up', $request->user()->id);

        return $assignment;
    }

    public function outForDelivery(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);
        abort_unless($assignment->assignment_type === 'delivery', 422, 'Only delivery assignments have an out-for-delivery step.');

        $this->transition($assignment, 'out_for_delivery', $request->user()->id);

        return $assignment;
    }

    public function deliver(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);
        abort_unless($assignment->assignment_type === 'delivery', 422, 'Only delivery assignments can be marked delivered.');

        $data = $request->validate([
            'delivery_proof' => 'nullable|string|max:255',
            'delivery_photo' => 'nullable|image|max:10240',
        ]);

        // An uploaded photo takes precedence over a typed reference; it's kept on the private
        // disk and served only through deliveryProof() below, which re-checks ownership.
        if ($request->hasFile('delivery_photo')) {
            $path = $request->file('delivery_photo')->store("delivery-proofs/{$assignment->id}", 'local');
            $assignment->update(['delivery_proof' => $path]);
        } elseif (! empty($data['delivery_proof'])) {
            $assignment->update(['delivery_proof' => $data['delivery_proof']]);
        }

        $this->transition($assignment, 'delivered', $request->user()->id);
        $assignment->update(['completed_at' => now()]);

        // Delivered = done: create the delivery partner's commission, same as a completed job.
        event(new AssignmentCompleted($assignment->fresh()));

        return $assignment->fresh('commissions');
    }

    /** Streams the uploaded delivery proof photo to anyone allowed to view the assignment. */
    public function deliveryProof(Request $request, Assignment $assignment)
    {
        $this->assertOwnAssignment($request, $assignment);

        $path = $assignment->delivery_proof;
        abort_unless($path && str_starts_with($path, 'delivery-proofs/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /** Failed delivery — requires a reason, kept on the assignment and in its history. */
    public function markFailed(Request $request, Assignment $assignment)
    {
        $this->assertCanRespond($request, $assignment);
        abort_unless($assignment->assignment_type === 'delivery', 422, 'Only delivery assignments can be marked failed.');

        $data = $request->validate(['reason' => 'required|string']);

        $assignment->update(['failure_reason' => $data['reason']]);
        $this->transition($assignment, 'failed', $request->user()->id, $data['reason']);

        return $assignment;
    }

    public function reassign(Request $request, Assignment $assignment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can reassign an assignment to a different partner.');

        $data = $request->validate(['partner_id' => 'required|exists:partners,id', 'notes' => 'nullable|string']);

        $this->transition($assignment, 'reassigned', $request->user()->id, $data['notes'] ?? null);

        $new = Assignment::create([
            'business_id' => $assignment->business_id,
            'partner_id' => $data['partner_id'],
            'assignment_type' => $assignment->assignment_type,
            'reference_type' => $assignment->reference_type,
            'reference_id' => $assignment->reference_id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'location_id' => $assignment->location_id,
            'partner_category_id' => $assignment->partner_category_id,
            'assigned_date' => now()->toDateString(),
            'due_date' => $assignment->due_date,
            'status' => 'pending',
            'parent_assignment_id' => $assignment->id,
            'transaction_amount' => $assignment->transaction_amount,
            'created_by' => $request->user()->id,
        ]);

        $new->history()->create(['to_status' => 'pending', 'changed_by' => $request->user()->id, 'notes' => 'Reassigned from #'.$assignment->id]);

        return response()->json($new->load('partner'), 201);
    }

    protected function transition(Assignment $assignment, string $to, int $userId, ?string $notes = null): void
    {
        $from = $assignment->status;
        $assignment->update(['status' => $to]);
        $assignment->history()->create(['from_status' => $from, 'to_status' => $to, 'changed_by' => $userId, 'notes' => $notes]);
    }

    /** A partner-portal user may only ever view their own assignments. */
    protected function assertOwnAssignment(Request $request, Assignment $assignment): void
    {
        $user = $request->user();
        abort_if($user->isPartnerUser() && $assignment->partner_id !== $user->partner_id, 403, 'You can only view your own assignments.');
    }

    /**
     * A partner-portal user may respond to (accept/reject/start/complete/cancel) an
     * assignment only if it's their own AND it was assigned to them by admin staff — an
     * assignment a partner submitted themselves can only be actioned by admin from here on.
     */
    protected function assertCanRespond(Request $request, Assignment $assignment): void
    {
        $this->assertOwnAssignment($request, $assignment);

        $user = $request->user();
        abort_if(
            $user->isPartnerUser() && $assignment->was_submitted_by_partner,
            403,
            'This assignment was submitted by a partner and can only be actioned by admin staff.'
        );
    }

    public function destroy(Request $request, Assignment $assignment)
    {
        abort_if($request->user()->isPartnerUser(), 403, 'Only admin staff can delete assignments.');

        $assignment->delete();

        return response()->json(null, 204);
    }
}
