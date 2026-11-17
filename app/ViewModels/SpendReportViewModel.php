<?php

namespace App\ViewModels;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Credit spend, provider cost and profit per UTC day and user. Days follow completed_at because that is
 * the day Pixelforge bills on, so each day's provider cost can be checked against GET /v1/usage.
 */
class SpendReportViewModel
{
    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly PixelforgeClient $pixelforge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rowsByDay = $this->rows()->groupBy('day');
        $pixelforgeCosts = $this->pixelforgeDailyCosts();

        $days = collect(CarbonPeriod::create($this->from, $this->to)->toArray())
            ->map(fn (CarbonInterface $date): string => $date->format(PixelforgeClient::DATE_FORMAT))
            ->reverse()
            ->map(function (string $day) use ($rowsByDay, $pixelforgeCosts): array {
                $users = $rowsByDay->get($day, collect());
                $providerCost = (int) $users->sum('provider_cost_nanos');

                return [
                    'date' => $day,
                    'users' => $users->values(),
                    'generations' => (int) $users->sum('generations'),
                    'charged_nanos' => (int) $users->sum('charged_nanos'),
                    'provider_cost_nanos' => $providerCost,
                    'profit_nanos' => (int) $users->sum('profit_nanos'),
                    'pixelforge_cost_nanos' => $pixelforgeCosts[$day] ?? null,
                ];
            })
            ->filter(fn (array $day): bool => $day['generations'] > 0 || $day['pixelforge_cost_nanos'] > 0)
            ->values();

        return [
            'from' => $this->from->format(PixelforgeClient::DATE_FORMAT),
            'to' => $this->to->format(PixelforgeClient::DATE_FORMAT),
            'isPixelforgeAvailable' => $pixelforgeCosts !== null,
            'days' => $days,
            'totals' => [
                'generations' => $days->sum('generations'),
                'charged_nanos' => $days->sum('charged_nanos'),
                'provider_cost_nanos' => $days->sum('provider_cost_nanos'),
                'profit_nanos' => $days->sum('profit_nanos'),
                'pixelforge_cost_nanos' => $pixelforgeCosts === null ? null : array_sum($pixelforgeCosts),
            ],
        ];
    }

    /**
     * @return Collection<int, array{day: string, user_id: int, email: string, generations: int, charged_nanos: int, provider_cost_nanos: int, profit_nanos: int}>
     */
    private function rows(): Collection
    {
        return Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded)
            ->where('generations.completed_at', '>=', $this->from)
            ->where('generations.completed_at', '<', $this->to->addDay())
            ->selectRaw('DATE(`generations`.`completed_at`) as `day`, `users`.`id` as `user_id`, `users`.`email` as `email`')
            ->selectRaw('COUNT(*) as `generations`')
            ->selectRaw('SUM(`generations`.`charge_nanos`) as `charged_nanos`')
            ->selectRaw('SUM(`generations`.`provider_cost_nanos`) as `provider_cost_nanos`')
            ->groupBy('day', 'users.id', 'users.email')
            ->orderBy('users.email')
            ->toBase()
            ->get()
            // MySQL returns SUM() of integers as decimal strings.
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'generations' => (int) $row->generations,
                'charged_nanos' => (int) $row->charged_nanos,
                'provider_cost_nanos' => (int) $row->provider_cost_nanos,
                'profit_nanos' => (int) $row->charged_nanos - (int) $row->provider_cost_nanos,
            ]);
    }

    /**
     * @return array<string, int>|null null when Pixelforge can't be reached
     */
    private function pixelforgeDailyCosts(): ?array
    {
        try {
            return $this->pixelforge->dailyCosts($this->from, $this->to);
        } catch (Throwable $exception) {
            Log::warning('Could not load Pixelforge usage.', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
