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
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('headline');
            $table->string('headline_ar');
            $table->string('source');
            $table->unsignedTinyInteger('minutes');
            $table->json('symbols');
            $table->json('sectors')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
