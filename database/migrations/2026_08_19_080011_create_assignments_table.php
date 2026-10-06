<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('assignment_type', ['lead', 'order', 'service', 'task']);
            $table->string('reference_type')->nullable(); // polymorphic hook for future CRM/OMS
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('partner_category_id')->nullable()->constrained('partner_categories')->nullOnDelete();
            $table->date('assigned_date')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', [
                'pending', 'accepted', 'rejected', 'in_progress', 'completed', 'cancelled', 'reassigned',
            ])->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('parent_assignment_id')->nullable()->constrained('assignments')->nullOnDelete();
            $table->decimal('transaction_amount', 14, 2)->nullable(); // base value for commission calc
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['partner_id', 'status']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
