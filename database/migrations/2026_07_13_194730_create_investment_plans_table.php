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
        Schema::create('investment_plans', function (Blueprint $table) {
            $table->id();
            // One live plan per investor; regenerating replaces it.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('monthly_contribution', 12, 2)->nullable();
            $table->json('weights');
            $table->json('orders');
            $table->json('metrics');
            $table->json('forecast');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_plans');
    }
};
