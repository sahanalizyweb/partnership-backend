<?php

namespace Database\Seeders;

use App\Events\AgreementActivated;
use App\Events\AssignmentCompleted;
use App\Events\CommissionApproved as CommissionApprovedEvent;
use App\Models\Agreement;
use App\Models\Assignment;
use App\Models\Business;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Location;
use App\Models\Partner;
use App\Models\PartnerCategory;
use App\Models\PartnerType;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $lizymart = Business::where('code', 'lizymart')->firstOrFail();

        User::firstOrCreate(
            ['email' => 'admin@partnership.test'],
            ['name' => 'Super Admin', 'password' => Hash::make('password'), 'business_id' => null]
        )->assignRole('Super Administrator');

        $manager = User::firstOrCreate(
            ['email' => 'manager@lizymart.test'],
            ['name' => 'Lizymart Partnership Manager', 'password' => Hash::make('password'), 'business_id' => $lizymart->id]
        );
        $manager->assignRole('Partnership Manager');

        $finance = User::firstOrCreate(
            ['email' => 'finance@lizymart.test'],
            ['name' => 'Lizymart Finance Manager', 'password' => Hash::make('password'), 'business_id' => $lizymart->id]
        );
        $finance->assignRole('Finance Manager');

        $salesType = PartnerType::where('code', 'sales')->firstOrFail();
        $category = PartnerCategory::firstOrCreate(
            ['business_id' => $lizymart->id, 'partner_type_id' => $salesType->id, 'name' => 'Gold Sales Partner'],
            ['status' => 'active']
        );

        $area = Location::where('type', 'area')->first();

        $partner = Partner::firstOrCreate(
            ['business_id' => $lizymart->id, 'partner_code' => 'LM-SP-0001'],
            [
                'partner_type_id' => $salesType->id,
                'partner_category_id' => $category->id,
                'entity_type' => 'individual',
                'name' => 'Ravi Kumar',
                'email' => 'ravi.kumar@example.test',
                'phone' => '9876543210',
                'primary_location_id' => $area?->id,
                'status' => 'verified',
                'created_by' => $manager->id,
            ]
        );

        $partnerUser = User::firstOrCreate(
            ['email' => 'ravi.kumar@example.test'],
            ['name' => 'Ravi Kumar', 'password' => Hash::make('password'), 'business_id' => $lizymart->id, 'partner_id' => $partner->id]
        );
        $partnerUser->assignRole('Partner');
        if (! $partner->user_id) {
            $partner->update(['user_id' => $partnerUser->id]);
        }

        CommissionRule::firstOrCreate(
            ['business_id' => $lizymart->id, 'partner_category_id' => $category->id, 'rule_type' => 'percentage'],
            ['name' => 'Gold Sales Partner - 10%', 'value' => 10, 'effective_from' => now()->subMonths(6)->toDateString(), 'status' => 'active']
        );

        $agreement = Agreement::firstOrCreate(
            ['business_id' => $lizymart->id, 'partner_id' => $partner->id, 'agreement_number' => 'AGR-LM-0001'],
            [
                'agreement_type' => 'Standard Sales Partner Agreement',
                'start_date' => now()->subMonths(3)->toDateString(),
                'payment_terms' => 'Monthly settlement, net 7 days',
                'responsibilities' => 'Lead generation and conversion within assigned service area.',
                'status' => 'active',
                'created_by' => $manager->id,
            ]
        );

        Event::dispatch(new AgreementActivated($agreement));

        $assignment = Assignment::firstOrCreate(
            ['business_id' => $lizymart->id, 'partner_id' => $partner->id, 'title' => 'Lead - Bulk grocery order'],
            [
                'assignment_type' => 'lead',
                'location_id' => $area?->id,
                'partner_category_id' => $category->id,
                'assigned_date' => now()->subDays(10)->toDateString(),
                'due_date' => now()->subDays(3)->toDateString(),
                'status' => 'completed',
                'accepted_at' => now()->subDays(9),
                'completed_at' => now()->subDays(2),
                'transaction_amount' => 15000,
                'created_by' => $manager->id,
            ]
        );

        if (! $assignment->commissions()->exists()) {
            Event::dispatch(new AssignmentCompleted($assignment));
        }

        $commission = Commission::where('assignment_id', $assignment->id)->first();
        if ($commission && $commission->status === 'pending') {
            $commission->update(['status' => 'approved', 'approved_by' => $finance->id, 'approved_at' => now()]);
            Event::dispatch(new CommissionApprovedEvent($commission));
        }

        if ($commission) {
            $settlementService = app(SettlementService::class);
            $existing = $partner->settlements()->first();
            if (! $existing) {
                $settlement = $settlementService->run($partner, now()->subMonth()->toDateString(), now()->toDateString());
                $settlementService->approve($settlement, $finance->id);
            }
        }
    }
}
