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
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->index('campus');
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('campus');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->dropIndex(['campus']);
            $table->dropIndex(['status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['campus']);
            $table->dropIndex(['role']);
        });
    }
};
