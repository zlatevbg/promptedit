<?php

namespace App\Actions\Generations;

use App\DTO\GenerationRequestData;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\CostCalculator;
use App\Services\CreditLedger;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateGenerationAction
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $costCalculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Save the generation and hold its maximum price, then hand it to the queue for submission to Pixelforge.
     *
     * @throws ValidationException when the user cannot afford the maximum price
     */
    public function execute(User $user, GenerationRequestData $data): Generation
    {
        $estimate = $this->costCalculator->estimate(
            $this->pixelforge->pricing(),
            $data->prompt,
            $data->size,
            $data->quality,
            count($data->referenceImages),
        );

        $generation = DB::transaction(function () use ($user, $data, $estimate): Generation {
            // Lock the user so two concurrent submits can't both spend the same balance.
            $balance = User::query()->whereKey($user->id)->lockForUpdate()->value('balance_nanos');

            if ($balance < $estimate->maxNanos) {
                throw ValidationException::withMessages([
                    'credits' => 'Not enough credits: this generation can cost up to the amount shown. Buy credits to continue.',
                ]);
            }

            $generation = Generation::query()->create([
                'user_id' => $user->id,
                'status' => GenerationStatus::Pending,
                'prompt' => $data->prompt,
                'size' => $data->size,
                'quality' => $data->quality,
                'reference_images' => $data->referenceImages,
                'estimated_charge_nanos' => $estimate->expectedNanos,
                'max_charge_nanos' => $estimate->maxNanos,
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGenerationJob::dispatch($generation->id);

        return $generation;
    }
}
