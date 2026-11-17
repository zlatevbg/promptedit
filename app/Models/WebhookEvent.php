<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Pixelforge webhook event we have already processed, keyed by its unique event id.
 *
 * @property int $id
 * @property string $event_id
 * @property WebhookEventType $type
 */
#[Fillable(['event_id', 'type'])]
class WebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WebhookEventType::class,
        ];
    }
}
