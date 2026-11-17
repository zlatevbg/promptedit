<?php

namespace App\Enums;

/**
 * Pixelforge API error codes we branch on, plus our own codes for failures that happen before Pixelforge accepts a generation.
 */
enum PixelforgeErrorCode: string
{
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case Overloaded = 'overloaded';
    case ContentPolicyViolation = 'content_policy_violation';
    case ValidationFailed = 'validation_failed';

    /** Ours: Pixelforge stayed busy for longer than we are willing to retry. */
    case SubmissionAbandoned = 'submission_abandoned';

    /**
     * Rejections that clear up by themselves after the Retry-After delay.
     */
    public function isTransient(): bool
    {
        return in_array($this, [self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::Overloaded], true);
    }

    /**
     * A friendlier explanation than Pixelforge's own message, where we have one.
     */
    public function userMessage(): ?string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Pixelforge rejected this prompt under its content policy.',
            self::DailyLimitExceeded => 'The daily generation limit is used up. It resets at midnight UTC.',
            self::SubmissionAbandoned => 'Pixelforge stayed too busy to accept this generation, so we stopped retrying.',
            default => null,
        };
    }
}
