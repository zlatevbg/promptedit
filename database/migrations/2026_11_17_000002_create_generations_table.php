<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Also sent as the Idempotency-Key and as metadata.local_id.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // User-facing amounts (markup included), in nanodollars.
            $table->bigInteger('estimated_charge_nanos');
            $table->bigInteger('max_charge_nanos');
            $table->bigInteger('charge_nanos')->nullable();
            // What Pixelforge bills us, in nanodollars.
            $table->bigInteger('provider_cost_nanos')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            // Pixelforge's completion time: it bills on this UTC day.
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // Spend report: succeeded generations by completion day.
            $table->index(['status', 'completed_at']);
        });
    }
};
