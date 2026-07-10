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
        Schema::create('company_filings', function (Blueprint $table) {
            $table->id();
            $table->string('headline');
            $table->string('headline_ar');
            $table->string('symbol', 20);
            $table->string('type', 20);
            $table->string('source', 40);
            $table->string('url', 2048)->nullable();
            $table->text('excerpt');
            $table->text('excerpt_ar');
            $table->timestamp('published_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_filings');
    }
};
