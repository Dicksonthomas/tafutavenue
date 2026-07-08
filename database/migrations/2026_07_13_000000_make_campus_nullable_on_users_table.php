<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin (mkuu au aliyepandishwa) hana campus mahususi - anaona campuses
 * zote. Column ilikuwa NOT NULL na default 'morogoro_main', jambo ambalo
 * lingemfanya kila Super Admin aonekane "amo Morogoro Main" kimakosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY campus VARCHAR(255) NULL DEFAULT NULL");
        DB::table('users')->where('is_super_admin', true)->update(['campus' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('campus')->update(['campus' => 'morogoro_main']);
        DB::statement("ALTER TABLE users MODIFY campus VARCHAR(255) NOT NULL DEFAULT 'morogoro_main'");
    }
};
