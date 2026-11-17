<?php

namespace App\Enums;

/**
 * Pixelforge's token types, as used for keys in both `usage` and `rates`.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';
}
