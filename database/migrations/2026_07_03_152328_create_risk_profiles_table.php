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
        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->string('risk_tolerance');
            $table->string('time_horizon');
            $table->decimal('target_return', 6, 4);
            $table->decimal('target_volatility', 6, 4);
            $table->string('liquidity_needs')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_profiles');
    }
};
