<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property int $estimated_charge_nanos
 * @property int $max_charge_nanos
 * @property int|null $charge_nanos
 * @property int|null $provider_cost_nanos
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'provider_id', 'status', 'prompt', 'size', 'quality', 'reference_images',
    'estimated_charge_nanos', 'max_charge_nanos', 'charge_nanos', 'provider_cost_nanos',
    'rates', 'usage', 'error_code', 'error_message', 'image_url', 'image_url_expires_at', 'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_charge_nanos' => 'integer',
            'max_charge_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
