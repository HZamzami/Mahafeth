<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The curated demo providers are gone: url-less news rows are the old
     * synthetic headlines, and every existing filing row came from the
     * curated fixtures. Purge both so only live-API data renders; the
     * refresh commands repopulate filings from SEC EDGAR.
     */
    public function up(): void
    {
        DB::table('news_items')->whereNull('url')->delete();
        DB::table('company_filings')->delete();
    }

    /**
     * Data purge — nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
