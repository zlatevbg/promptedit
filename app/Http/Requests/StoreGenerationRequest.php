<?php

namespace App\Http\Requests;

use App\DTO\GenerationRequestData;
use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits, so users get instant feedback instead of a failed generation.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_images.*' => 'reference image URL',
        ];
    }

    public function toDto(): GenerationRequestData
    {
        return new GenerationRequestData(
            prompt: $this->string('prompt')->toString(),
            size: $this->enum('size', Size::class),
            quality: $this->enum('quality', Quality::class),
            referenceImages: array_values($this->array('reference_images')),
        );
    }
}
