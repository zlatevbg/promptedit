# PromptEdit × Pixelforge

A Laravel 13 + Vue 3 + Inertia app where users generate images through the [Pixelforge](https://pixelforge.promptedit.dev/docs) API and pay Pixelforge's cost plus a 25% markup, in credits (US dollars).

- One page to write a prompt, pick size, quality and up to 4 reference image URLs, and see the **estimated and maximum price before submitting**.
- Live history: each generation goes _Waiting → Queued → Generating → Succeeded/Failed_ without a refresh, showing the estimated and the actual charge and the image (or why it failed).
- Credit balance with a fake "Buy credits" button. Generating is blocked when the user can't afford the maximum price.
- Admin **Spend report**: credit spend, provider cost and profit per UTC day and per user, with each day's provider cost checked against Pixelforge's `GET /v1/usage`.

## How a generation flows

```
Generate ─► CreateGenerationAction ─► SubmitGenerationJob ─► POST /v1/generations
            (hold max price,          (Idempotency-Key = local id,
             status: pending)          waits out 429/503 via Retry-After)
                                                │
              ┌─────────────────────────────────┴───────────────┐
              ▼                                                 ▼
   POST /webhooks/pixelforge                        generations:sync (every minute)
   (verify signature → dedupe event id)             (polls quiet generations,
              │                                      re-queues lost submissions)
              └──────────────► ApplyProviderGenerationAction ◄──┘
                               (status only moves forward; on success/failure
                                settle the ledger exactly once)
```

Submit responses, webhooks and polling all go through `ApplyProviderGenerationAction`. That is the only code that changes a generation's Pixelforge state, and the only code that bills for it.

## Money

- **Integer nanodollars everywhere** (`$0.000000001`, Pixelforge's own precision), stored as `BIGINT`. Decimal maths uses PHP 8.4's `BcMath\Number`, never floats. Display rounding happens only in the UI, and hovering any amount shows the exact value.
- **Provider cost** = Σ tokens × rate ÷ 1M, using the `rates` stored **on the generation**. Pixelforge fixes those when the generation is created, so later price changes don't affect it. A generation's cost is booked on its **completion** day (UTC), which is the day Pixelforge bills on, so the admin report reconciles with `/v1/usage` to the nanodollar.
- **User price** = provider cost × 1.25, **rounded up** to the next nanodollar, so we never undercharge.
- **Estimate vs maximum.** Text tokens are only known within ±15%, and reasoning tokens within a range set by quality. The UI shows the expected price, using mid-range reasoning, and the maximum, using +15% text and max reasoning.
- **Hold → settle.** Submitting places a **hold** for the maximum price. When the generation finishes, the hold is released and the actual price is charged. Failed generations are free: the hold is released and nothing is charged. The charge is **capped at the hold**, so a user never pays more than the "at most" price they saw. If Pixelforge raises its rates between our estimate and the generation, we absorb the difference.
- **Ledger.** Every balance change is a `credit_transactions` row written in the same transaction as `users.balance_nanos`, so the balance always equals the ledger sum. A unique index on (`generation_id`, `type`) makes a second hold, release or charge for the same generation impossible at the database level.

## Robustness

| Pixelforge does…                                                       | The app…                                                                                                                                                                                                                                           |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 429 rate / concurrency limit, 503 overloaded, 408, 5xx, network errors | Releases the job back to the queue after `Retry-After` plus jitter, and keeps retrying for 10 minutes. The `Idempotency-Key` means a retry never creates or bills a second generation. The UI shows "Pixelforge is busy, retrying automatically…". |
| 400 content policy, 422, daily limit                                   | Fails the generation straight away with a readable reason. Free.                                                                                                                                                                                   |
| Fails a generation after accepting it                                  | `generation.failed` → Failed with Pixelforge's message. Free.                                                                                                                                                                                      |
| Delivers a webhook twice                                               | The event id goes into `webhook_events` (unique) in the same transaction as applying it, so a redelivery is a no-op.                                                                                                                               |
| Delivers webhooks out of order                                         | Status has a rank (pending < queued < processing < succeeded = failed) and only moves forward. Finished generations never change again.                                                                                                            |
| Sends a webhook before `POST /generations` returns                     | Generations are matched by `metadata.local_id` (our id), so the webhook doesn't need the provider id yet.                                                                                                                                          |
| Never delivers a webhook                                               | `generations:sync` polls generations that have been quiet for 2 minutes.                                                                                                                                                                           |
| Our submit job gets lost                                               | `generations:sync` re-queues pending generations older than the job's retry window.                                                                                                                                                                |
| Forged webhook                                                         | HMAC-SHA256 of `t.body` with the key's secret, compared in constant time, with a 5-minute timestamp tolerance. All webhooks are refused if no secret is configured.                                                                                |

## Decisions and trade-offs

- **Webhook processing runs inline**, not on a queued job. It is a few indexed row updates, well inside Pixelforge's 10 s timeout, and it lets "record the event id" and "apply it" share one transaction. If webhook volume grows, or applying an event gets slower (for example, downloading and storing images), processing can move to a queued job. The controller would verify the signature, store the raw event and respond 2xx straight away, and the job would deduplicate and call the same `ApplyProviderGenerationAction`. That gives a faster response and lets processing scale independently. In return, the event row needs a "processed" flag, so that a job that fails is retried rather than skipped as a duplicate.
- **The price estimate is computed in the browser** (`resources/js/lib/estimate.ts`), so it updates as the user types. It mirrors `App\Services\CostCalculator`. The server always recomputes the estimate and the hold itself, so the browser value is display-only.
- **Images aren't copied.** The UI shows Pixelforge's signed URL and says when it has expired (24 h), which the brief allows.
- **Database queue and scheduler**, no Redis. That is enough at this scale, and it is the same locally and on Laravel Cloud.
- **Known edge case.** If Pixelforge accepted a submit whose response we never received, and every retry for 10 minutes failed on the network, we mark the generation failed and free. Pixelforge still bills us for it, and it shows up as a ✗ on that day in the spend report.

## Running locally (Docker)

Requirements: Docker with Compose. No local PHP or Node is needed.

```bash
cp .env.example .env               # then set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
docker compose build
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d               # app, worker, scheduler, vite, mysql, webhook-proxy, tunnel
docker compose exec app php artisan migrate
```

- App: http://localhost:8000. Register, then make yourself an admin with `docker compose exec app php artisan users:grant-admin you@example.com`.
- Webhooks: Pixelforge needs a public URL. Find the tunnel's URL with `docker compose logs tunnel | grep trycloudflare`, then set `<url>/webhooks/pixelforge` as your **local** API key's webhook URL in the Pixelforge dashboard. The tunnel only exposes the webhook path. The quick-tunnel URL changes every time the `tunnel` container restarts.
- Use one Pixelforge API key per environment, so each one receives its own webhooks and reconciles against its own `/v1/usage`.
- Pixelforge test keywords for prompts: `[fail]`, `[duplicate]`, `[slow]`, `[succeed]`, `[blocked]`, `[overloaded]`.

| Service         | Purpose                                                |
| --------------- | ------------------------------------------------------ |
| `app`           | FrankenPHP serving the app on port 8000                |
| `worker`        | `queue:listen` (submits generations)                   |
| `scheduler`     | `schedule:work` (runs `generations:sync` every minute) |
| `vite`          | Vite dev server with HMR on port 5173                  |
| `mysql`         | MySQL 8.4                                              |
| `webhook-proxy` | Caddy, forwarding only `/webhooks/pixelforge`          |
| `tunnel`        | Cloudflare quick tunnel to `webhook-proxy`             |

## Tests and checks

```bash
docker compose exec app php artisan test        # Pest
docker compose exec app composer test           # Pint + PHPStan + tests (as CI does)
docker compose exec app npm run check           # frontend lint + format
docker compose exec app npm run types:check     # vue-tsc
```

The feature tests cover what the money depends on:

- exact pricing against the figures in Pixelforge's docs
- markup rounding
- hold and settle, and the cap at the hold
- billing at the generation's own rates
- duplicate, out-of-order and early webhooks
- signature, replay and missing-secret rejection
- retry, reject and give-up paths of the submit job
- the polling fallback
- the spend report against a faked `/v1/usage`

GitHub Actions runs the same checks on every push.

## Deploying (Laravel Cloud)

- PHP 8.4+, a Laravel MySQL database attached, with `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database`.
- App cluster: **Scheduler** on, background process `php artisan queue:work database --tries=3 --timeout=60`, scale to zero **off**. Queued jobs don't wake a sleeping environment.
- Build: `composer install --no-dev` and `npm ci && npm run build`. Deploy: `php artisan migrate --force`.
- Environment variables: `APP_DEBUG=false`, `PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1`, and `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a production-only key. That key's webhook URL is `https://<app>.laravel.cloud/webhooks/pixelforge`.
- After the first deploy, register and run `php artisan users:grant-admin <email>` from the Commands console.

## Where things live

| Path                                                          | What                                                                                                    |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `app/Actions/Generations/`                                    | Create (hold + queue), apply a Pixelforge snapshot (the single state machine), reject before acceptance |
| `app/Jobs/SubmitGenerationJob.php`                            | Submission with idempotency and Retry-After handling                                                    |
| `app/Services/CostCalculator.php`                             | Estimate, provider cost, user price                                                                     |
| `app/Services/CreditLedger.php`                               | The only writer of balances                                                                             |
| `app/Services/Pixelforge/`                                    | Thin HTTP client and error type                                                                         |
| `app/Http/Middleware/VerifyPixelforgeSignature.php`           | Webhook signature check                                                                                 |
| `app/Http/Controllers/PixelforgeWebhookController.php`        | Dedupe + apply in one transaction                                                                       |
| `app/Console/Commands/SyncGenerationsCommand.php`             | Polling and lost-job safety net                                                                         |
| `app/ViewModels/SpendReportViewModel.php`                     | Admin report and `/v1/usage` reconciliation                                                             |
| `resources/js/pages/Generations.vue`, `admin/SpendReport.vue` | The two app pages                                                                                       |
