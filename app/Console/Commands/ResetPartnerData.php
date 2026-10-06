<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes every partner and everything recorded against them (roles, sales, supplies,
 * assignments, commissions, payments, balances, portal logins) so the system can be tested
 * from a clean start. Setup data stays: businesses, partner types & categories, products /
 * services catalog, business sources, document types, locations, admin users and roles.
 */
class ResetPartnerData extends Command
{
    protected $signature = 'partners:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all partners and their transactions (keeps setup data and admin users)';

    /** Emptied completely. Child tables first. */
    protected array $tables = [
        'settlement_items', 'settlements', 'ledger_entries', 'payments',
        'supplier_payments', 'reseller_payments', 'commissions', 'sales',
        'supplies', 'supplier_products', 'reseller_purchases', 'reseller_product_prices',
        'affiliate_links', 'assignment_history', 'assignments', 'performance_metrics',
        'partner_documents', 'agreements', 'partner_service_areas',
        'partner_type_enrollments', 'partners', 'reference_counters',
    ];

    protected array $partnerModels = [
        'Partner', 'PartnerTypeEnrollment', 'PartnerDocument', 'Agreement', 'Assignment', 'Commission',
        'Payment', 'LedgerEntry', 'Settlement', 'Sale', 'Supply', 'SupplierPayment', 'SupplierProduct',
        'ResellerPurchase', 'ResellerPayment', 'ResellerProductPrice', 'AffiliateLink', 'PerformanceMetric',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete ALL partners and their records? This cannot be undone.')) {
            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    $count = DB::table($table)->count();
                    DB::table($table)->truncate();
                    $this->line(sprintf('  %-26s %d removed', $table, $count));
                }
            }

            // Partner-specific commission rules (type/category-level rules are setup data).
            $ruleIds = DB::table('commission_rules')->whereNotNull('partner_id')->pluck('id');
            DB::table('commission_slabs')->whereIn('commission_rule_id', $ruleIds)->delete();
            $this->line(sprintf('  %-26s %d removed', 'commission_rules (partner)', DB::table('commission_rules')->whereIn('id', $ruleIds)->delete()));

            // Partner portal logins.
            $partnerUserIds = DB::table('users')->whereNotNull('partner_id')->pluck('id');
            DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->whereIn('model_id', $partnerUserIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $partnerUserIds)->delete();
            $this->line(sprintf('  %-26s %d removed', 'users (partner logins)', DB::table('users')->whereIn('id', $partnerUserIds)->delete()));

            // Activity history and alerts about the removed records.
            $this->line(sprintf('  %-26s %d removed', 'activity_log (partner)', DB::table('activity_log')
                ->whereIn('subject_type', array_map(fn ($m) => 'App\\Models\\'.$m, $this->partnerModels))->delete()));
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('All partner data removed. Setup data and admin users were kept.');

        return self::SUCCESS;
    }
}
