<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->date('staff_registration_open_from')->nullable()->after('marquee_until');
            $table->date('staff_registration_open_until')->nullable()->after('staff_registration_open_from');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['staff_registration_open_from', 'staff_registration_open_until']);
        });
    }
};
