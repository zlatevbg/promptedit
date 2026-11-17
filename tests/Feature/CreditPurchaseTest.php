<?php

use App\Models\User;

it('adds the purchased credits to the balance and the ledger', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credit-purchases.store'), ['pack' => 5])->assertRedirect();
    $this->actingAs($user)->post(route('credit-purchases.store'), ['pack' => 20])->assertRedirect();

    expect($user->refresh()->balance_nanos)->toBe(25_000_000_000)
        ->and((int) $user->creditTransactions()->sum('amount_nanos'))->toBe(25_000_000_000);
});

it('only sells the offered packs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credit-purchases.store'), ['pack' => 1_000_000])->assertSessionHasErrors('pack');

    expect($user->refresh()->balance_nanos)->toBe(0);
});
