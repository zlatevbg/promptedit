<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanos');
            $table->timestamps();

            // A generation is held, released and charged at most once, enforced by the database.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }
};
