<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_main_super_admin')->default(false)->after('is_super_admin');
        });

        // Super Admin wa sasa (aliyewekwa na migration ya awali) ndiye
        // Super Admin "Mkuu" pekee - wengine watakaopandishwa baadaye kuwa
        // is_super_admin hawatakuwa na hadhi hii ya juu zaidi.
        DB::table('users')
            ->where('role', 'admin')
            ->where('is_super_admin', true)
            ->update(['is_main_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_main_super_admin');
        });
    }
};
