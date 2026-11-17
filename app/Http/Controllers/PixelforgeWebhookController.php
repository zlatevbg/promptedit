<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyProviderGenerationAction;
use App\DTO\ProviderGeneration;
use App\Enums\WebhookEventType;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Signature is checked by VerifyPixelforgeSignature. The work is a couple of indexed row updates, so it runs
 * inline: recording the event id and applying it share one transaction, so a crash can't leave an event
 * marked as seen but never applied.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyProviderGenerationAction $apply): Response
    {
        $type = WebhookEventType::tryFrom((string) $request->json('type'));

        if ($type === null) {
            return response()->noContent();
        }

        DB::transaction(function () use ($request, $type, $apply): void {
            $isFirstDelivery = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $request->json('id'),
                'type' => $type->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if (! $isFirstDelivery || $type === WebhookEventType::Ping) {
                return;
            }

            $apply->execute(ProviderGeneration::fromArray($request->json('data.object')));
        });

        return response()->noContent();
    }
}
