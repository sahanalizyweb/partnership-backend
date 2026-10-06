<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('partner_type_enrollment_id')->nullable()->after('partner_id')
                ->constrained('partner_type_enrollments')->nullOnDelete();
            $table->foreignId('business_source_id')->nullable()->after('partner_type_enrollment_id')
                ->constrained('business_sources')->nullOnDelete();
        });

        // No DB-level unique constraint existed on commissions.sale_id — Sale::commission()
        // was only a hasOne at the app level. Add the real backstop so two Commission rows
        // can never be generated for the same Sale even under concurrent requests.
        Schema::table('commissions', function (Blueprint $table) {
            $table->unique('sale_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('partner_type_enrollment_id')->nullable()->after('partner_id')
                ->constrained('partner_type_enrollments')->nullOnDelete();
            $table->foreignId('business_source_id')->nullable()->after('partner_type_enrollment_id')
                ->constrained('business_sources')->nullOnDelete();
            $table->enum('attribution_method', ['manual', 'affiliate_link'])->default('manual')->after('business_source_id');
            $table->foreignId('affiliate_link_id')->nullable()->after('attribution_method')
                ->constrained('affiliate_links')->nullOnDelete();
        });

        Schema::table('reseller_purchases', function (Blueprint $table) {
            $table->foreignId('partner_type_enrollment_id')->nullable()->after('partner_id')
                ->constrained('partner_type_enrollments')->nullOnDelete();
            $table->foreignId('business_source_id')->nullable()->after('partner_type_enrollment_id')
                ->constrained('business_sources')->nullOnDelete();
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('partner_type_enrollment_id')->nullable()->after('partner_id')
                ->constrained('partner_type_enrollments')->nullOnDelete();
            $table->foreignId('business_source_id')->nullable()->after('partner_type_enrollment_id')
                ->constrained('business_sources')->nullOnDelete();
        });

        Schema::table('supplies', function (Blueprint $table) {
            // Supplier flow is a payable, not an earning — no business_source_id needed here,
            // but it still gets the enrollment link for full transaction traceability.
            $table->foreignId('partner_type_enrollment_id')->nullable()->after('partner_id')
                ->constrained('partner_type_enrollments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_type_enrollment_id');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_source_id');
            $table->dropConstrainedForeignId('partner_type_enrollment_id');
        });

        Schema::table('reseller_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_source_id');
            $table->dropConstrainedForeignId('partner_type_enrollment_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_link_id');
            $table->dropColumn('attribution_method');
            $table->dropConstrainedForeignId('business_source_id');
            $table->dropConstrainedForeignId('partner_type_enrollment_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique(['sale_id']);
            $table->dropConstrainedForeignId('business_source_id');
            $table->dropConstrainedForeignId('partner_type_enrollment_id');
        });
    }
};
