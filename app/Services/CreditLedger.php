<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of users.balance_nanos. Each balance change is a ledger row in the same transaction,
 * so the balance always equals the sum of the user's ledger.
 */
class CreditLedger
{
    public function purchase(User $user, int $amountNanos): void
    {
        $this->record($user->id, CreditTransactionType::Purchase, $amountNanos);
    }

    /**
     * Reserve the generation's maximum price. The caller must already have checked the balance under a user lock.
     */
    public function hold(Generation $generation): void
    {
        $this->record($generation->user_id, CreditTransactionType::Hold, -$generation->max_charge_nanos, $generation->id);
    }

    /**
     * Release the hold and charge the final price (zero for failed generations).
     */
    public function settle(Generation $generation): void
    {
        $this->record($generation->user_id, CreditTransactionType::Release, $generation->max_charge_nanos, $generation->id);

        if ($generation->charge_nanos > 0) {
            $this->record($generation->user_id, CreditTransactionType::Charge, -$generation->charge_nanos, $generation->id);
        }
    }

    /**
     * The unique (generation_id, type) index makes a second hold/release/charge for the same generation fail loudly.
     */
    private function record(int $userId, CreditTransactionType $type, int $amountNanos, ?string $generationId = null): void
    {
        DB::transaction(function () use ($userId, $type, $amountNanos, $generationId): void {
            // Update first: it takes the exclusive lock on the user row before the insert's foreign key check
            // takes a shared one. The other order deadlocks two concurrent writes for the same user.
            User::query()->whereKey($userId)->increment('balance_nanos', $amountNanos);

            CreditTransaction::query()->create([
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_nanos' => $amountNanos,
            ]);
        });
    }
}
