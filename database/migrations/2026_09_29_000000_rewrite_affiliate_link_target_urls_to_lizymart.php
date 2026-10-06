<?php

use App\Models\AffiliateLink;
use App\Services\AffiliateUrlBuilder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Links created before the storefront was fixed to https://lizymart.com stored a
     * localhost/admin-app URL — rewrite them to the public product page + ?ref=CODE.
     */
    public function up(): void
    {
        $builder = app(AffiliateUrlBuilder::class);

        AffiliateLink::with('product')->whereNotNull('product_id')->each(function (AffiliateLink $link) use ($builder) {
            if ($link->product) {
                $link->newQuery()->whereKey($link->id)->update(['target_url' => $builder->build($link->code, $link->product)]);
            }
        });
    }

    public function down(): void
    {
        // Old localhost URLs aren't worth restoring.
    }
};
