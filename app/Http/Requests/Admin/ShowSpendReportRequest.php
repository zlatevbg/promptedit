<?php

namespace App\Http\Requests\Admin;

use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowSpendReportRequest extends FormRequest
{
    private const int DEFAULT_DAYS = 30;

    /** Pixelforge's /v1/usage accepts at most 92 days. */
    public const int MAX_DAYS = 92;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:'.PixelforgeClient::DATE_FORMAT],
            'to' => ['nullable', 'date_format:'.PixelforgeClient::DATE_FORMAT, 'after_or_equal:from'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->from()->diffInDays($this->to()) >= self::MAX_DAYS) {
                    $validator->errors()->add('from', 'The report covers at most '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('to') ?? 'today', 'UTC')->startOfDay();
    }

    public function from(): CarbonImmutable
    {
        $from = $this->input('from');

        return $from === null
            ? $this->to()->subDays(self::DEFAULT_DAYS - 1)
            : CarbonImmutable::parse($from, 'UTC')->startOfDay();
    }
}
