<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete(); // null = global source
            $table->string('name'); // LizyMart, LizyNet, Service, Referral, Affiliate, Sales, Reseller, Other
            $table->string('code');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_sources');
    }
};
