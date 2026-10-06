<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->enum('type', ['country', 'state', 'district', 'city', 'area']);
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['type', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
