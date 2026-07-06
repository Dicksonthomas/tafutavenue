<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * logo_path inabadilika kutoka VARCHAR(255) kwenda LONGTEXT ili iweze
     * kuhifadhi logo kama base64 data URI moja kwa moja ndani ya database
     * (siyo kwenye disk), kwa sababu Railway free plan haina persistent
     * volume - faili za disk hupotea kila deploy.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE app_settings MODIFY logo_path LONGTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_settings MODIFY logo_path VARCHAR(255) NULL');
    }
};
