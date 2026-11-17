<?php

namespace App\Actions\Generations;

use App\DTO\ProviderGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\CostCalculator;
use App\Services\CreditLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for Pixelforge state: submit responses, webhooks and polling all land here.
 * Status only ever moves forward and final states never change, so duplicate, stale and
 * out-of-order updates are no-ops, and a generation is settled exactly once.
 */
class ApplyProviderGenerationAction
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly CreditLedger $ledger,
    ) {}

    public function execute(ProviderGeneration $snapshot): ?Generation
    {
        return DB::transaction(function () use ($snapshot): ?Generation {
            $generation = Generation::query()
                ->when(
                    $snapshot->localId !== null,
                    fn ($query) => $query->whereKey($snapshot->localId),
                    fn ($query) => $query->where('provider_id', $snapshot->id),
                )
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                Log::warning('Pixelforge generation does not match any local generation.', ['provider_id' => $snapshot->id]);

                return null;
            }

            // A webhook can beat the submit response, so whichever arrives first records the provider id.
            $generation->provider_id ??= $snapshot->id;

            if ($generation->status->canTransitionTo($snapshot->status)) {
                $this->transition($generation, $snapshot);
            }

            $generation->save();

            return $generation;
        });
    }

    private function transition(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->status = $snapshot->status;
        $generation->rates = $snapshot->rates;

        if ($snapshot->status === GenerationStatus::Succeeded) {
            $providerCost = $this->costCalculator->providerCost($snapshot->usage ?? [], $snapshot->rates);

            $generation->fill([
                'usage' => $snapshot->usage,
                'image_url' => $snapshot->imageUrl,
                'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
                'completed_at' => $snapshot->completedAt,
                'provider_cost_nanos' => $providerCost,
                // Never charge more than the "at most" price we showed and held. It can only be exceeded
                // if Pixelforge raised its rates after our estimate; we absorb that difference.
                'charge_nanos' => min($this->costCalculator->userPrice($providerCost), $generation->max_charge_nanos),
            ]);

            $this->ledger->settle($generation);
        }

        if ($snapshot->status === GenerationStatus::Failed) {
            $generation->fill([
                'error_code' => $snapshot->errorCode,
                'error_message' => $snapshot->errorMessage,
                'completed_at' => $snapshot->completedAt,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
            ]);

            $this->ledger->settle($generation);
        }
    }
}
