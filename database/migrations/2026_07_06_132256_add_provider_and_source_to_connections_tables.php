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
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('provider')->default('fake')->after('type');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->string('source')->default('api')->after('status');
            $table->text('access_token')->nullable()->after('source');
            $table->text('refresh_token')->nullable()->after('access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('provider');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['source', 'access_token', 'refresh_token']);
        });
    }
};
