<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\CreditLedger;
use Illuminate\Support\Facades\DB;

/**
 * Fail a generation that Pixelforge never accepted (content policy, daily limit, gave up retrying). Free for the user.
 */
class RejectGenerationAction
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function execute(string $generationId, string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($generationId, $errorCode, $errorMessage): void {
            $generation = Generation::query()->lockForUpdate()->find($generationId);

            if ($generation === null || $generation->status !== GenerationStatus::Pending) {
                return;
            }

            $generation->fill([
                'status' => GenerationStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
            ])->save();

            $this->ledger->settle($generation);
        });
    }
}
