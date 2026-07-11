<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->boolean('marquee_enabled')->default(true)->after('cr_registration_closed_campuses');
            $table->timestamp('marquee_until')->nullable()->after('marquee_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['marquee_enabled', 'marquee_until']);
        });
    }
};
