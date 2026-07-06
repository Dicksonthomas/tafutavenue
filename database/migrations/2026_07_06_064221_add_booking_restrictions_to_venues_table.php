<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->json('blocked_purposes')->nullable()->after('status')
                ->comment('Purposes zisizoruhusiwa hapa, mfano ["study_unit","test"] kwa Kingalu/Labs');
            $table->json('restricted_levels')->nullable()->after('blocked_purposes')
                ->comment('Kama zipo, ni level pekee zinazoruhusiwa ku-book, mfano ["Masters","PhD"]');
            $table->string('restricted_department')->nullable()->after('restricted_levels')
                ->comment('Kama ipo, ni department pekee inayoruhusiwa ku-book venue hii');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['blocked_purposes', 'restricted_levels', 'restricted_department']);
        });
    }
};
