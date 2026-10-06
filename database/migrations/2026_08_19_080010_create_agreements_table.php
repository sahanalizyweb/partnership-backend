<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('agreement_number');
            $table->string('agreement_type')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('responsibilities')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['draft', 'active', 'expired', 'terminated', 'renewed'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_agreement_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'agreement_number']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
