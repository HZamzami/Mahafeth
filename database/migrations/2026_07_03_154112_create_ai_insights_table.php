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
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->text('summary');
            $table->json('recommendations');
            $table->timestamps();

            $table->unique(['portfolio_snapshot_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
