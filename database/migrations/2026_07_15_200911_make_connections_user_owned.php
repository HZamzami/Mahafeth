<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual, user-created accounts have no linked institution and carry a
     * user-given label, so institution_id becomes nullable. The
     * (user_id, institution_id) unique index still holds — the database
     * treats NULLs as distinct, so a user may keep many manual accounts.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            $table->dropForeign(['institution_id']);
        });

        Schema::table('connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('institution_id')->nullable()->change();
            $table->string('label')->nullable()->after('institution_id');
        });

        Schema::table('connections', function (Blueprint $table): void {
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            $table->dropForeign(['institution_id']);
            $table->dropColumn('label');
        });

        Schema::table('connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('institution_id')->nullable(false)->change();
        });

        Schema::table('connections', function (Blueprint $table): void {
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });
    }
};
