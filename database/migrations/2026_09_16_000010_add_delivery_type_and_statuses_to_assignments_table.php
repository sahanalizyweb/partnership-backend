<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens the assignment_type/status enums to add Delivery Partner's own type and
     * lifecycle statuses (picked_up/out_for_delivery/delivered/failed), alongside the
     * existing lead/order/service/task and pending/accepted/.../reassigned values. Raw
     * ALTER...MODIFY (MySQL/MariaDB, per this project's stack) since Schema::table has no
     * portable "widen an enum" builder call — every existing value stays valid, so every
     * existing row keeps its current type/status untouched.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE assignments MODIFY assignment_type ENUM('lead', 'order', 'service', 'task', 'delivery') NOT NULL");
        DB::statement("ALTER TABLE assignments MODIFY status ENUM('pending', 'accepted', 'rejected', 'in_progress', 'completed', 'cancelled', 'reassigned', 'picked_up', 'out_for_delivery', 'delivered', 'failed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE assignments MODIFY assignment_type ENUM('lead', 'order', 'service', 'task') NOT NULL");
        DB::statement("ALTER TABLE assignments MODIFY status ENUM('pending', 'accepted', 'rejected', 'in_progress', 'completed', 'cancelled', 'reassigned') NOT NULL DEFAULT 'pending'");
    }
};
