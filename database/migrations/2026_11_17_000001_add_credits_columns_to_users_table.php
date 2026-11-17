<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            // Spendable balance in nanodollars, already net of holds. Always equals the ledger sum.
            $table->bigInteger('balance_nanos')->default(0)->after('is_admin');
        });
    }
};
