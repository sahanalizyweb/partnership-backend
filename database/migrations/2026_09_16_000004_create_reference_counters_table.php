<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs ReferenceNumberService — one row per (prefix, business) counter, incremented
     * under a row lock so concurrent requests never hand out the same reference number.
     */
    public function up(): void
    {
        Schema::create('reference_counters', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key')->unique();
            $table->unsignedBigInteger('next_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_counters');
    }
};
