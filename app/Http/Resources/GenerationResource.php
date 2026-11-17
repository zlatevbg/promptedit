<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isImageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_charge_nanos' => $this->estimated_charge_nanos,
            'max_charge_nanos' => $this->max_charge_nanos,
            'charge_nanos' => $this->charge_nanos,
            'error_message' => $this->error_message,
            'image_url' => $isImageExpired ? null : $this->image_url,
            'is_image_expired' => $isImageExpired,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
