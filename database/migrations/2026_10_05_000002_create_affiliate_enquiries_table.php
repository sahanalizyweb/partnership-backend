<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer enquiry for a product/service, credited to an affiliate partner — sent by the
     * website (source 'link', via the partner's ?ref= code) or entered by hand (source 'manual').
     * Admin converts it into a Sale, which carries the commission.
     */
    public function up(): void
    {
        Schema::create('affiliate_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_slug')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->text('message')->nullable();
            $table->string('source_page_url', 2048)->nullable();
            $table->string('website_enquiry_id', 100)->nullable()->unique();
            $table->enum('source', ['link', 'manual'])->default('link');
            $table->enum('status', ['new', 'contacted', 'converted', 'lost'])->default('new');
            $table->date('enquiry_date');
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['partner_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_enquiries');
    }
};
