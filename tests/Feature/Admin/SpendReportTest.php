<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

function admin(): User
{
    $admin = User::factory()->create();
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

function usageDay(string $date, string $costUsd): array
{
    return ['date' => $date, 'generations' => 1, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $costUsd];
}

it('is only available to admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.spend-report'))->assertForbidden();
});

it('reports spend, provider cost and profit per completion day and user, next to Pixelforge usage', function () {
    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $succeeded = ['status' => GenerationStatus::Succeeded, 'provider_cost_nanos' => 100_000_000, 'charge_nanos' => 125_000_000];

    // Created on the 16th but completed on the 17th: billed on the 17th, like Pixelforge does.
    Generation::factory()->for($alice)->create($succeeded + ['completed_at' => '2026-11-17 00:00:05', 'created_at' => '2026-11-16 23:59:50']);
    Generation::factory()->for($alice)->create($succeeded + ['completed_at' => '2026-11-17 12:00:00']);
    Generation::factory()->for($alice)->create(['status' => GenerationStatus::Failed, 'provider_cost_nanos' => 0, 'charge_nanos' => 0, 'completed_at' => '2026-11-17 13:00:00']);

    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['data' => [
        usageDay('2026-11-16', '0.000000000'),
        usageDay('2026-11-17', '0.200000000'),
    ]])]);

    $this->actingAs(admin())
        ->get(route('admin.spend-report', ['from' => '2026-11-16', 'to' => '2026-11-17']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/SpendReport')
            ->where('isPixelforgeAvailable', true)
            ->has('days', 1)
            ->where('days.0.date', '2026-11-17')
            ->where('days.0.generations', 2)
            ->where('days.0.charged_nanos', 250_000_000)
            ->where('days.0.provider_cost_nanos', 200_000_000)
            ->where('days.0.profit_nanos', 50_000_000)
            ->where('days.0.pixelforge_cost_nanos', 200_000_000)
            ->where('days.0.users.0.email', 'alice@example.com')
            ->where('totals.profit_nanos', 50_000_000));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-11-16&end_date=2026-11-17'));
});

it('still renders when Pixelforge usage is unavailable', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([], 500)]);

    $this->actingAs(admin())
        ->get(route('admin.spend-report'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('isPixelforgeAvailable', false));
});

it('limits the range to what Pixelforge can report', function () {
    $this->actingAs(admin())
        ->get(route('admin.spend-report', ['from' => '2026-01-01', 'to' => '2026-11-17']))
        ->assertSessionHasErrors('from');
});
