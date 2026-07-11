<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every CR/Staff account created before the approval-gate feature (or via an
 * Admin's direct "add CR"/"add Staff" form, which never sets approved_at)
 * is_active=true but has approved_at=null - indistinguishable from a
 * still-pending registration. That ambiguity is harmless on its own, but
 * unsuspendCampus() (UserAdminController) relies on approved_at being set to
 * tell "was genuinely active before" apart from "never approved" - so an
 * account like this, once suspended, could never be reactivated again.
 * Backfilling approved_at (to created_at, since they were clearly fine
 * before this column existed) makes that distinction reliable everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('is_active', true)
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Not reversible - we don't know which rows were backfilled vs.
        // genuinely approved through the normal flow.
    }
};
