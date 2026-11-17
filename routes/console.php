<?php

use App\Console\Commands\SyncGenerationsCommand;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncGenerationsCommand::class)->everyMinute()->withoutOverlapping(expiresAt: 10);

Artisan::command('users:grant-admin {email}', function (string $email) {
    $user = User::query()->where('email', $email)->firstOrFail();
    $user->is_admin = true;
    $user->save();

    $this->info("{$email} is now an admin.");
})->purpose('Give a user access to the admin spend report');
