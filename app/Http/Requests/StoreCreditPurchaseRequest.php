<?php

namespace App\Http\Requests;

use App\Enums\CreditPack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditPurchaseRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', Rule::enum(CreditPack::class)],
        ];
    }

    public function pack(): CreditPack
    {
        return $this->enum('pack', CreditPack::class);
    }
}
