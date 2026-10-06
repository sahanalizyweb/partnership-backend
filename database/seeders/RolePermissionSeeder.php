<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public const MODULES = [
        'dashboard', 'partners', 'partner-types', 'partner-categories', 'locations',
        'documents', 'agreements', 'assignments', 'products', 'commissions', 'supplies',
        'resellers', 'payments', 'ledger', 'settlements', 'performance', 'users', 'reports',
    ];

    public const ACTIONS = ['view', 'create', 'edit', 'approve', 'delete'];

    public function run(): void
    {
        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$module}.{$action}", 'guard_name' => 'web']);
            }
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        $partnershipManager = Role::firstOrCreate(['name' => 'Partnership Manager', 'guard_name' => 'web']);
        $partnershipManager->syncPermissions($this->permissionsFor([
            'dashboard' => ['view'],
            'partners' => ['view', 'create', 'edit', 'approve'],
            'partner-types' => ['view', 'create', 'edit'],
            'partner-categories' => ['view', 'create', 'edit'],
            'locations' => ['view', 'create', 'edit'],
            'documents' => ['view', 'create', 'edit', 'approve'],
            'agreements' => ['view', 'create', 'edit', 'approve'],
            'assignments' => ['view', 'create', 'edit'],
            // The Product/Service catalog (amount + fixed standard commission %) that
            // Sales/Referrals are recorded against.
            'products' => ['view', 'create', 'edit', 'delete'],
            // 'create' here also covers recording Sales (the primary commission-earning
            // activity) — Sales reuse the commissions.* permission namespace.
            'commissions' => ['view', 'create'],
            // The Supplier -> Our Company flow (Supplier Products catalog + Supplies +
            // Supplier Payments) — kept as its own module, separate from commissions/payments.
            'supplies' => ['view', 'create', 'edit', 'delete'],
            // The Lizyweb -> Reseller flow (mirror of supplies above — the reseller owes us).
            'resellers' => ['view', 'create', 'edit', 'delete'],
            'performance' => ['view'],
            'reports' => ['view'],
        ]));

        $operationsManager = Role::firstOrCreate(['name' => 'Operations Manager', 'guard_name' => 'web']);
        $operationsManager->syncPermissions($this->permissionsFor([
            'dashboard' => ['view'],
            'partners' => ['view'],
            'locations' => ['view', 'create', 'edit'],
            'assignments' => ['view', 'create', 'edit', 'approve', 'delete'],
            'performance' => ['view', 'edit'],
            'reports' => ['view'],
        ]));

        $financeManager = Role::firstOrCreate(['name' => 'Finance Manager', 'guard_name' => 'web']);
        $financeManager->syncPermissions($this->permissionsFor([
            'dashboard' => ['view'],
            'partners' => ['view'],
            'partner-types' => ['view'],
            'partner-categories' => ['view'],
            // Commission approval now lives on the Assignments page (per assignment), so Finance
            // Manager needs read access there to reach it.
            'assignments' => ['view'],
            'commissions' => ['view', 'create', 'edit', 'approve'],
            // Supplier payments/balances are Accounts Payable — squarely Finance's domain.
            'supplies' => ['view', 'create', 'edit', 'delete'],
            // Reseller payments/balances are Accounts Receivable — same domain, mirrored flow.
            'resellers' => ['view', 'create', 'edit', 'delete'],
            'payments' => ['view', 'create', 'edit', 'approve'],
            'ledger' => ['view'],
            'settlements' => ['view', 'create', 'edit', 'approve'],
            'reports' => ['view'],
        ]));

        $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $staff->syncPermissions($this->permissionsFor([
            'dashboard' => ['view'],
            'partners' => ['view', 'create'],
            'partner-types' => ['view'],
            'partner-categories' => ['view'],
            'locations' => ['view'],
            'documents' => ['view', 'create'],
            'assignments' => ['view', 'create', 'edit'],
            'products' => ['view'],
            'commissions' => ['view', 'create'],
            'supplies' => ['view', 'create'],
            'resellers' => ['view', 'create'],
        ]));

        $viewer = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions(
            Permission::whereIn('name', array_map(fn ($m) => "{$m}.view", self::MODULES))->get()
        );

        $partner = Role::firstOrCreate(['name' => 'Partner', 'guard_name' => 'web']);
        $partner->syncPermissions($this->permissionsFor([
            'dashboard' => ['view'],
            // 'create' lets a partner submit their own assignment/lead; 'edit' is also what
            // gates the accept/reject/start/complete workflow actions on their own assignments
            // (enforced in the controller) — editing an assignment's actual fields is blocked
            // there regardless of this permission, so only admin staff can edit assignments.
            'assignments' => ['view', 'create', 'edit'],
            // Needed so the create-assignment form and My Profile page have somewhere to pull
            // Location / Partner Type / Category options from.
            'locations' => ['view'],
            'partner-types' => ['view'],
            'partner-categories' => ['view'],
            // Lets a partner browse the admin-defined Product/Service catalog when recording
            // their own Sale/Referral, so its standard amount + fixed commission % apply.
            'products' => ['view'],
            // 'create' lets a partner self-report a Sale of one of our products — the primary
            // commission-earning activity — same reasoning as assignments.create above.
            'commissions' => ['view', 'create'],
            // A Supplier Partner can add/edit/delete their own Products/Supplies catalog
            // entries and their own Supply History (self-reported deliveries — mirrors
            // commissions.create for Sales above), each scoped to their own partner_id/
            // ownership in the controller, and a Supply is only self-editable while unpaid.
            // SupplierPayment stays admin-only regardless of this permission — that
            // controller hard-blocks isPartnerUser() on create/delete, so this grant can't let
            // a supplier mark their own payment as made.
            'supplies' => ['view', 'create', 'edit', 'delete'],
            // A Reseller Partner gets the same self-service shape over reseller_purchases —
            // mirrors the Supplier grant immediately above; ResellerPaymentController is
            // admin-only the same way SupplierPaymentController is.
            'resellers' => ['view', 'create', 'edit', 'delete'],
            'payments' => ['view'],
            'ledger' => ['view'],
            'documents' => ['view', 'create'],
            'performance' => ['view'],
        ]));
    }

    protected function permissionsFor(array $moduleActions): array
    {
        $names = [];
        foreach ($moduleActions as $module => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return Permission::whereIn('name', $names)->get()->all();
    }
}
