<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // commission/advance/refund/adjustment
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
    }
};
