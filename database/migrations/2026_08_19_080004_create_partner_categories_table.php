<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('partner_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('partner_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules')->nullable(); // category-specific configurable rules
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_categories');
    }
};
