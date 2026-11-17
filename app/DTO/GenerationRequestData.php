<?php

namespace App\DTO;

use App\Enums\Quality;
use App\Enums\Size;

final readonly class GenerationRequestData
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public array $referenceImages,
    ) {}
}
