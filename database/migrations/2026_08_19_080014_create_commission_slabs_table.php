<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_value', 14, 2);
            $table->decimal('max_value', 14, 2)->nullable(); // null = unbounded upper slab
            $table->decimal('rate', 14, 4); // percentage applied within this slab
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_slabs');
    }
};
