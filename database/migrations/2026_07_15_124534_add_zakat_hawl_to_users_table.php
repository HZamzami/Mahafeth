<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's recurring zakat anniversary as a Hijri month/day, plus the
     * Gregorian occurrence last reminded about (dedupes the daily command).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('zakat_hawl_month')->nullable();
            $table->unsignedTinyInteger('zakat_hawl_day')->nullable();
            $table->date('zakat_last_reminded_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['zakat_hawl_month', 'zakat_hawl_day', 'zakat_last_reminded_for']);
        });
    }
};
