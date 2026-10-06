<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCategory;
use App\Models\PartnerType;
use App\Models\User;
use App\Services\ReferenceNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerController extends Controller
{
    use ScopesToRequester;

    public function __construct(protected ReferenceNumberService $referenceNumbers) {}

    public function index(Request $request)
    {
        $query = Partner::with(['partnerType', 'partnerCategory', 'primaryLocation', 'enrollments.partnerType'])->withCount('assignments');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request, 'id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('partner_type_id')) {
            // A partner holding several roles matches a filter on any one of them.
            $typeId = $request->integer('partner_type_id');
            $query->where(fn ($q) => $q->where('partner_type_id', $typeId)
                ->orWhereHas('enrollments', fn ($e) => $e->where('partner_type_id', $typeId)));
        }
        if ($request->filled('partner_type_code')) {
            // Dropdowns on each partner type's pages list only partners holding that role
            // (primary type or any active/pending enrollment), e.g. "delivery" or "sales,affiliate,referral".
            $codes = array_filter(explode(',', (string) $request->string('partner_type_code')));
            $query->where(fn ($q) => $q->whereHas('partnerType', fn ($t) => $t->whereIn('code', $codes))
                ->orWhereHas('enrollments', fn ($e) => $e->whereNotIn('status', ['suspended', 'rejected', 'inactive'])
                    ->whereHas('partnerType', fn ($t) => $t->whereIn('code', $codes))));
        }
        if ($request->filled('partner_category_id')) {
            $query->where('partner_category_id', $request->integer('partner_category_id'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('partner_code', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $businessIdForScope = $user->business_id ?: $request->input('business_id');

        // Same person joining in another role: add the role to their existing profile (one
        // partner, one portal login) instead of rejecting the duplicate email/phone.
        if ($existing = $this->findExistingPartner($request, $businessIdForScope)) {
            return $this->addRolesToExisting($request, $existing);
        }

        $data = $request->validate([
            'business_id' => $user->business_id ? 'nullable' : 'required|exists:businesses,id',
            'partner_type_id' => 'required|exists:partner_types,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'entity_type' => 'required|in:individual,business',
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('partners', 'email')->where(fn ($q) => $q->where('business_id', $businessIdForScope))],
            'phone' => ['required', 'string', 'max:20', Rule::unique('partners', 'phone')->where(fn ($q) => $q->where('business_id', $businessIdForScope))],
            'alt_phone' => 'nullable|string|max:20',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'primary_location_id' => 'nullable|exists:locations,id',
            'location' => 'nullable|string|max:2000',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_ifsc' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'service_area_ids' => 'nullable|array',
            'service_area_ids.*' => 'exists:locations,id',
            'additional_partner_type_ids' => 'nullable|array',
            'additional_partner_type_ids.*' => 'exists:partner_types,id',
        ], [
            'email.unique' => 'A partner with this email already exists for this business.',
            'phone.unique' => 'A partner with this phone number already exists for this business.',
        ]);

        $businessId = $user->business_id ?: $data['business_id'];
        $serviceAreaIds = $data['service_area_ids'] ?? [];
        $additionalTypeIds = $data['additional_partner_type_ids'] ?? [];
        unset($data['service_area_ids'], $data['business_id'], $data['additional_partner_type_ids']);

        $partner = Partner::create($data + [
            'business_id' => $businessId,
            'partner_code' => $this->generatePartnerCode($businessId),
            'status' => 'applied',
            'created_by' => $user->id,
        ]);

        if ($serviceAreaIds) {
            $partner->serviceAreas()->sync($serviceAreaIds);
        }

        $this->createPrimaryEnrollment($partner, $user->id);
        $this->createAdditionalEnrollments($partner, $additionalTypeIds, $user->id);

        return response()->json($partner->load('serviceAreas', 'enrollments.partnerType'), 201);
    }

    /**
     * Every partner gets one is_primary=true enrollment mirroring its partner_type_id at
     * creation time — the bridge that lets legacy single-type code keep working unmodified
     * while additional types can be added later via PartnerTypeEnrollmentController.
     */
    protected function createPrimaryEnrollment(Partner $partner, int $createdBy): void
    {
        $type = PartnerType::find($partner->partner_type_id);

        $partner->enrollments()->create([
            'business_id' => $partner->business_id,
            'partner_type_id' => $partner->partner_type_id,
            'partner_category_id' => $partner->partner_category_id,
            'enrollment_code' => $partner->partner_code.'-'.strtoupper($type?->code ?? 'primary'),
            'status' => $partner->status,
            'is_primary' => true,
            'activated_at' => $partner->activated_at,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Matches by partner email, portal login email, or phone. A business admin only matches
     * within their own business; a Super Administrator matches across every group company
     * (the same person can partner with Lizymart and Lizygo and still have one login).
     */
    protected function findExistingPartner(Request $request, $businessId): ?Partner
    {
        $email = trim((string) $request->input('email'));
        $phone = trim((string) $request->input('phone'));
        $query = $request->user()->business_id ? Partner::where('business_id', $businessId) : Partner::query();

        if ($email !== '') {
            $byEmail = (clone $query)->where(fn ($q) => $q->where('email', $email)
                ->orWhereHas('user', fn ($u) => $u->where('email', $email)))->first();
            if ($byEmail) {
                return $byEmail;
            }
        }

        return $phone !== '' ? (clone $query)->where('phone', $phone)->first() : null;
    }

    protected function addRolesToExisting(Request $request, Partner $partner)
    {
        $data = $request->validate([
            'partner_type_id' => 'required|exists:partner_types,id',
            'additional_partner_type_ids' => 'nullable|array',
            'additional_partner_type_ids.*' => 'exists:partner_types,id',
        ]);

        $held = $partner->enrollments()->pluck('partner_type_id')->map(fn ($id) => (int) $id)->all();
        $requested = array_unique(array_map('intval', array_merge([$data['partner_type_id']], $data['additional_partner_type_ids'] ?? [])));
        $newTypeIds = array_values(array_diff($requested, $held));

        abort_if(! $newTypeIds, 422, "{$partner->name} ({$partner->partner_code}) is already added in the selected partner role(s).");

        $enrollmentBusinessId = $request->user()->business_id ?: ($request->input('business_id') ?: $partner->business_id);

        foreach (PartnerType::whereIn('id', $newTypeIds)->get() as $type) {
            $partner->enrollments()->create([
                'business_id' => $enrollmentBusinessId,
                'partner_type_id' => $type->id,
                'enrollment_code' => $partner->partner_code.'-'.strtoupper($type->code),
                'status' => $partner->status,
                'is_primary' => false,
                'created_by' => $request->user()->id,
            ]);
        }

        return response()->json(array_merge($partner->fresh(['enrollments.partnerType', 'user:id,email'])->toArray(), [
            'merged' => true,
            'added_partner_types' => PartnerType::whereIn('id', $newTypeIds)->pluck('name'),
        ]));
    }

    /** Same person joining as several partner roles at once (e.g. Supplier + Sales + Service). */
    protected function createAdditionalEnrollments(Partner $partner, array $typeIds, int $createdBy): void
    {
        $typeIds = array_diff(array_unique(array_map('intval', $typeIds)), [(int) $partner->partner_type_id]);

        foreach (PartnerType::whereIn('id', $typeIds)->get() as $type) {
            $partner->enrollments()->create([
                'business_id' => $partner->business_id,
                'partner_type_id' => $type->id,
                'enrollment_code' => $partner->partner_code.'-'.strtoupper($type->code),
                'status' => $partner->status,
                'is_primary' => false,
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * Keeps the primary enrollment mirroring partners.partner_type_id/partner_category_id.
     * Without this, changing a partner's type left the old type on the enrollment, and the
     * Partner Portal (which reads enrollments) kept showing the previous type's menus.
     */
    protected function syncPrimaryEnrollment(Partner $partner): void
    {
        $primary = $partner->primaryEnrollment()->first();

        if (! $primary) {
            $this->createPrimaryEnrollment($partner, auth()->id() ?? $partner->created_by);

            return;
        }

        if ((int) $primary->partner_type_id !== (int) $partner->partner_type_id) {
            $held = $partner->enrollments()
                ->where('partner_type_id', $partner->partner_type_id)
                ->where('id', '!=', $primary->id)
                ->first();

            if ($held) {
                // The new main type is a role they already hold — just make it the primary one.
                // The previous main role stays as a secondary role (never deleted).
                $primary->update(['is_primary' => false]);
                $held->update(['is_primary' => true, 'partner_category_id' => $partner->partner_category_id]);

                return;
            }

            $type = PartnerType::find($partner->partner_type_id);
            $primary->enrollment_code = $partner->partner_code.'-'.strtoupper($type?->code ?? 'primary');
        }

        $primary->partner_type_id = $partner->partner_type_id;
        $primary->partner_category_id = $partner->partner_category_id;
        $primary->save();
    }

    public function show(Request $request, Partner $partner)
    {
        $partner->load([
            'partnerType', 'partnerCategory', 'primaryLocation', 'serviceAreas',
            'documents.documentType', 'agreements', 'commissionRules', 'user:id,name,email,status,partner_id',
            'enrollments.partnerType', 'enrollments.partnerCategory',
        ]);

        return array_merge($partner->toArray(), ['current_balance' => $partner->currentBalance()]);
    }

    public function update(Request $request, Partner $partner)
    {
        $data = $request->validate([
            'partner_type_id' => 'sometimes|required|exists:partner_types,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'entity_type' => 'sometimes|required|in:individual,business',
            'name' => 'sometimes|required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('partners', 'email')->where(fn ($q) => $q->where('business_id', $partner->business_id))->ignore($partner->id)],
            'phone' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('partners', 'phone')->where(fn ($q) => $q->where('business_id', $partner->business_id))->ignore($partner->id)],
            'alt_phone' => 'nullable|string|max:20',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'primary_location_id' => 'nullable|exists:locations,id',
            'location' => 'nullable|string|max:2000',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_ifsc' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'service_area_ids' => 'nullable|array',
            'service_area_ids.*' => 'exists:locations,id',
        ], [
            'email.unique' => 'A partner with this email already exists for this business.',
            'phone.unique' => 'A partner with this phone number already exists for this business.',
        ]);

        if (array_key_exists('service_area_ids', $data)) {
            $partner->serviceAreas()->sync($data['service_area_ids'] ?? []);
            unset($data['service_area_ids']);
        }

        $partner->update($data);
        $this->syncPrimaryEnrollment($partner);

        return $partner->fresh(['serviceAreas', 'enrollments.partnerType']);
    }

    /**
     * A partner's business history. Most of these tables cascade on delete, so deleting a partner
     * that has any of them would silently wipe money records — such a partner is suspended instead.
     */
    protected const RECORD_TABLES = [
        'sales', 'supplies', 'payments', 'supplier_payments', 'reseller_payments', 'assignments',
        'commissions', 'settlements', 'ledger_entries', 'reseller_purchases', 'affiliate_links', 'affiliate_enquiries',
    ];

    /** Only a partner with no records can be deleted — together with its roles, documents and portal login. */
    public function destroy(Partner $partner)
    {
        $hasRecords = collect(self::RECORD_TABLES)
            ->contains(fn ($table) => Schema::hasTable($table) && DB::table($table)->where('partner_id', $partner->id)->exists());
        abort_if($hasRecords, 422, 'This partner has records and cannot be deleted. Suspend the partner instead.');

        DB::transaction(function () use ($partner) {
            $partner->enrollments()->delete();
            $partner->documents()->delete();

            // The portal login(s): tokens go with it; HasRoles detaches its roles on delete.
            foreach (User::where('partner_id', $partner->id)->get() as $login) {
                $login->tokens()->delete();
                $login->delete();
            }

            $partner->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Self-service profile for the Partner Portal: always resolved from the logged-in
     * user's own partner_id, never a route parameter, so a partner can only ever see or
     * edit their own record.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'Only partner-portal accounts have a profile here.');

        $partner = Partner::with(['partnerType', 'partnerCategory', 'enrollments.partnerType'])->findOrFail($user->partner_id);

        return array_merge($partner->toArray(), ['current_balance' => $partner->currentBalance()]);
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartnerUser(), 403, 'Only partner-portal accounts have a profile here.');

        $partner = Partner::findOrFail($user->partner_id);

        $data = $request->validate([
            'partner_type_id' => 'sometimes|required|exists:partner_types,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'entity_type' => 'sometimes|required|in:individual,business',
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'sometimes|required|string|max:20',
            'alt_phone' => 'nullable|string|max:20',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'location' => 'nullable|string|max:2000',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_ifsc' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
        ]);

        // A partner may only switch between roles the admin has given them — never self-assign a new type.
        if (! empty($data['partner_type_id'])) {
            abort_unless(
                $partner->enrollments()->where('partner_type_id', $data['partner_type_id'])->exists(),
                422,
                'You can only choose a partner type the admin has added for you.'
            );
        }

        if (! empty($data['partner_category_id'])) {
            $typeId = $data['partner_type_id'] ?? $partner->partner_type_id;
            $category = PartnerCategory::find($data['partner_category_id']);
            abort_if(! $category || $category->partner_type_id !== $typeId, 422, 'Category does not belong to the selected partner type.');
        }

        $partner->update($data);
        $this->syncPrimaryEnrollment($partner);

        return $partner->fresh(['partnerType', 'partnerCategory', 'enrollments.partnerType']);
    }

    /** Create the partner's portal login account (email + initial password), if one doesn't exist yet. */
    public function createPortalAccount(Request $request, Partner $partner)
    {
        abort_if($partner->user_id, 422, 'This partner already has a portal login.');

        $existingLogin = User::where('email', $request->input('email'))->first();

        if ($existingLogin?->partner_id && $existingLogin->partner_id !== $partner->id) {
            $target = Partner::find($existingLogin->partner_id);

            if (! $request->boolean('link_existing')) {
                // Same person already has a login — let the admin confirm linking instead of failing.
                return response()->json([
                    'message' => "This email is already the portal login of {$target->name} ({$target->partner_code}).",
                    'existing_partner' => $target->only(['id', 'name', 'partner_code']),
                ], 409);
            }

            abort_if(
                $partner->user_id && $partner->user_id !== $existingLogin->id,
                422,
                'This profile already has its own portal login — it cannot be linked.'
            );

            $this->mergeInto($partner, $target);

            return response()->json(array_merge(
                $target->fresh(['user', 'enrollments.partnerType'])->toArray(),
                ['merged_into' => $target->id],
            ));
        }

        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'business_id' => $partner->business_id,
            'partner_id' => $partner->id,
            'name' => $partner->name,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        $user->assignRole('Partner');
        $partner->update(['user_id' => $user->id]);

        return response()->json($partner->fresh('user'), 201);
    }

    /**
     * Folds a duplicate profile of the same person into their existing partner: every role
     * (enrollment) and every record moves across, so one portal login shows all roles. A role
     * the target already holds is combined into the target's enrollment.
     */
    protected function mergeInto(Partner $duplicate, Partner $target): void
    {
        DB::transaction(function () use ($duplicate, $target) {
            $enrollmentFkTables = collect(Schema::getTables())->pluck('name')
                ->filter(fn ($t) => $t !== 'partner_type_enrollments' && Schema::hasColumn($t, 'partner_type_enrollment_id'));

            foreach ($duplicate->enrollments()->with('partnerType')->get() as $enrollment) {
                $held = $target->enrollments()->where('partner_type_id', $enrollment->partner_type_id)->first();

                if ($held) {
                    foreach ($enrollmentFkTables as $table) {
                        DB::table($table)->where('partner_type_enrollment_id', $enrollment->id)
                            ->update(['partner_type_enrollment_id' => $held->id]);
                    }
                    $enrollment->delete();
                } else {
                    $enrollment->update([
                        'partner_id' => $target->id,
                        'is_primary' => false,
                        'enrollment_code' => $target->partner_code.'-'.strtoupper($enrollment->partnerType?->code ?? 'role'),
                    ]);
                }
            }

            // Service areas: keep the target's, add any it lacks.
            DB::table('partner_service_areas')->where('partner_id', $duplicate->id)
                ->whereIn('location_id', DB::table('partner_service_areas')->where('partner_id', $target->id)->pluck('location_id'))
                ->delete();

            $partnerFkTables = collect(Schema::getTables())->pluck('name')
                ->filter(fn ($t) => ! in_array($t, ['partner_type_enrollments', 'partners'], true) && Schema::hasColumn($t, 'partner_id'));

            foreach ($partnerFkTables as $table) {
                DB::table($table)->where('partner_id', $duplicate->id)->update(['partner_id' => $target->id]);
            }

            $duplicate->delete();
        });
    }

    /** Update the login email on the partner's existing portal account. */
    public function updatePortalEmail(Request $request, Partner $partner)
    {
        abort_unless($partner->user_id, 404, 'This partner does not have a portal login yet.');

        $data = $request->validate([
            'email' => 'required|email|unique:users,email,'.$partner->user_id,
        ]);

        $partner->user()->update(['email' => $data['email']]);

        return $partner->fresh('user');
    }

    /**
     * Reset the partner's portal login password. Requires the requesting admin to re-enter
     * their own current password as a confirmation step before this sensitive action runs.
     */
    public function resetPortalPassword(Request $request, Partner $partner)
    {
        abort_unless($partner->user_id, 404, 'This partner does not have a portal login yet.');

        $data = $request->validate([
            'admin_password' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        abort_unless(Hash::check($data['admin_password'], $request->user()->password), 422, 'Your password is incorrect.');

        $partner->user()->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated']);
    }

    public function verify(Partner $partner)
    {
        abort_unless($partner->status === 'verification_pending' || $partner->status === 'applied', 422, 'Partner is not pending verification.');

        $partner->update(['status' => 'verified']);
        $partner->primaryEnrollment?->update(['status' => 'verified']);

        return $partner;
    }

    public function suspend(Request $request, Partner $partner)
    {
        $partner->update(['status' => 'suspended']);
        $partner->primaryEnrollment?->update(['status' => 'suspended']);

        return $partner;
    }

    public function reactivate(Partner $partner)
    {
        $partner->update(['status' => 'active']);
        $partner->primaryEnrollment?->update(['status' => 'active', 'activated_at' => $partner->primaryEnrollment?->activated_at ?? now()]);

        return $partner;
    }

    /**
     * Sequence is drawn from ReferenceNumberService (scope "PARTNER:{business}") rather than
     * counting existing rows, so two concurrent partner creations can never collide on the
     * same code. Visible format (business-code prefix + random 2 letters + 4-digit sequence)
     * is unchanged, so existing partner_code values stay exactly as they were.
     */
    protected function generatePartnerCode(int $businessId): string
    {
        $business = \App\Models\Business::find($businessId);
        $prefix = strtoupper(substr($business?->code ?? 'PTN', 0, 3));
        $sequence = $this->referenceNumbers->nextNumber('PARTNER', $businessId);

        return $prefix.'-'.Str::upper(Str::random(2)).'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
