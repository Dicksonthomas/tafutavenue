<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['staff_registration_open_from', 'staff_registration_open_until']);
            $table->json('staff_registration_windows')->nullable()->after('cr_registration_closed_campuses');
            $table->boolean('maintenance_mode')->default(false)->after('marquee_until');
            $table->timestamp('maintenance_until')->nullable()->after('maintenance_mode');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['staff_registration_windows', 'maintenance_mode', 'maintenance_until']);
            $table->date('staff_registration_open_from')->nullable();
            $table->date('staff_registration_open_until')->nullable();
        });
    }
};
