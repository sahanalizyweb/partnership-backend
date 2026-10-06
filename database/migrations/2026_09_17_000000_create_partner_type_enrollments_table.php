<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_type_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_type_id')->constrained();
            $table->foreignId('partner_category_id')->nullable()->constrained('partner_categories')->nullOnDelete();
            $table->string('enrollment_code')->unique();
            $table->enum('status', [
                'applied', 'verification_pending', 'verified', 'agreement_pending',
                'active', 'inactive', 'suspended', 'rejected',
            ])->default('applied');
            // The enrollment that mirrors partners.partner_type_id — the bridge kept for
            // every existing controller/service that still reads the partner's single type.
            $table->boolean('is_primary')->default(false);
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            // Per-type config bag (e.g. reseller brand_mode/brand_name) — type-specific
            // fields that don't belong as dedicated columns on this generic table.
            $table->json('settings')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'partner_type_id']);
            $table->index(['business_id', 'status']);
            $table->index(['partner_id', 'status']);
        });

        // Backfill: every existing partner gets one primary enrollment mirroring its current
        // single partner_type_id — non-destructive, partners.partner_type_id stays untouched.
        DB::table('partners')->orderBy('id')->chunkById(200, function ($partners) {
            foreach ($partners as $p) {
                $typeCode = DB::table('partner_types')->where('id', $p->partner_type_id)->value('code') ?? 'primary';

                DB::table('partner_type_enrollments')->insert([
                    'business_id' => $p->business_id,
                    'partner_id' => $p->id,
                    'partner_type_id' => $p->partner_type_id,
                    'partner_category_id' => $p->partner_category_id,
                    'enrollment_code' => $p->partner_code.'-'.strtoupper($typeCode),
                    'status' => $p->status,
                    'is_primary' => true,
                    'activated_at' => $p->activated_at,
                    'created_by' => $p->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_type_enrollments');
    }
};
