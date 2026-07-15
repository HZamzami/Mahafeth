<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obligation_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->decimal('amount', 20, 4);
            $table->date('settled_through');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'settled_through']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obligation_settlements');
    }
};
