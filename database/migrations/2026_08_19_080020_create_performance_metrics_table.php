<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('period'); // e.g. '2026-08' monthly bucket
            $table->unsignedInteger('leads_received')->default(0);
            $table->unsignedInteger('leads_contacted')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);
            $table->decimal('sales_value', 14, 2)->default(0);
            $table->unsignedInteger('assignments_completed')->default(0);
            $table->decimal('response_time_avg', 8, 2)->nullable(); // in hours
            $table->decimal('completion_rate', 5, 2)->default(0);
            $table->decimal('avg_rating', 3, 2)->nullable();
            $table->unsignedInteger('complaints_count')->default(0);
            $table->decimal('commission_generated', 14, 2)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'period']);
            $table->index(['business_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_metrics');
    }
};
