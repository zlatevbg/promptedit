<?php

namespace App\Services\Pixelforge;

use App\DTO\Pricing;
use App\DTO\ProviderGeneration;
use App\Models\Generation;
use App\Support\Nanodollars;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private const string PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const int PRICING_CACHE_SECONDS = 3600;

    /** Pixelforge's date format for usage days (UTC). */
    public const string DATE_FORMAT = 'Y-m-d';

    /**
     * Current published pricing. Only used for estimates: a generation is billed at the rates on the generation itself.
     */
    public function pricing(): Pricing
    {
        $pricing = Cache::remember(
            self::PRICING_CACHE_KEY,
            self::PRICING_CACHE_SECONDS,
            fn (): array => $this->send(fn (PendingRequest $request) => $request->get('pricing'))->json(),
        );

        return Pricing::fromArray($pricing);
    }

    /**
     * Safe to call again for the same generation: the local id is the Idempotency-Key,
     * so a retry returns the generation Pixelforge already created instead of a new one.
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function createGeneration(Generation $generation): ProviderGeneration
    {
        $response = $this->send(fn (PendingRequest $request) => $request
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                // Lets webhooks find the generation even if they arrive before this request returns.
                'metadata' => ['local_id' => $generation->id],
            ]));

        return ProviderGeneration::fromArray($response->json());
    }

    /**
     * @throws PixelforgeException|ConnectionException
     */
    public function getGeneration(string $providerId): ProviderGeneration
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get("generations/{$providerId}"));

        return ProviderGeneration::fromArray($response->json());
    }

    /**
     * What Pixelforge billed this API key per UTC day (by completion date), in nanodollars.
     *
     * @return array<string, int> date => cost
     *
     * @throws PixelforgeException|ConnectionException
     */
    public function dailyCosts(CarbonInterface $from, CarbonInterface $to): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('usage', [
            'start_date' => $from->format(self::DATE_FORMAT),
            'end_date' => $to->format(self::DATE_FORMAT),
        ]));

        /** @var list<array{date: string, cost_usd: numeric-string}> $days */
        $days = $response->json('data');

        $costs = [];

        foreach ($days as $day) {
            $costs[$day['date']] = Nanodollars::fromDollars($day['cost_usd']);
        }

        return $costs;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws PixelforgeException|ConnectionException
     */
    private function send(callable $call): Response
    {
        $request = Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);

        $response = $call($request);

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }
}
