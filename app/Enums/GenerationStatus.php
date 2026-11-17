<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally, not yet accepted by Pixelforge (e.g. waiting out a rate limit). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Position in the lifecycle. A generation only ever moves to a higher rank,
     * which makes out-of-order and duplicate updates harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }
}
