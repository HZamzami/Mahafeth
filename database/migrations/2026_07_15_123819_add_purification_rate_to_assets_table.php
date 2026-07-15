<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fraction (0–1) of an asset's income that must be purified, as
     * published by Shariah boards. Null keeps the practical default:
     * everything for non-compliant assets, nothing for compliant ones.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('purification_rate', 6, 5)->nullable()->after('shariah_status');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('purification_rate');
        });
    }
};
