<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('partner_code');
            $table->foreignId('partner_type_id')->constrained();
            $table->foreignId('partner_category_id')->nullable()->constrained('partner_categories')->nullOnDelete();
            $table->enum('entity_type', ['individual', 'business'])->default('individual');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('alt_phone')->nullable();
            $table->string('gstin')->nullable();
            $table->string('pan')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('primary_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_name')->nullable();
            $table->enum('status', [
                'applied', 'verification_pending', 'verified', 'agreement_pending',
                'active', 'inactive', 'suspended', 'rejected',
            ])->default('applied');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // partner portal login
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'partner_code']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
