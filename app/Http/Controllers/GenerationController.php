<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGenerationAction;
use App\Enums\CreditPack;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const int PER_PAGE = 12;

    public function index(Request $request, PixelforgeClient $pixelforge): Response
    {
        return Inertia::render('Generations', [
            // Closures, so the page's status polling only re-queries the generations.
            'pricing' => fn () => $pixelforge->pricing(),
            'markupPercent' => CostCalculator::MARKUP_PERCENT,
            'limits' => fn () => [
                'maxPromptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'creditPacks' => fn () => array_column(CreditPack::cases(), 'value'),
            'generations' => fn () => GenerationResource::collection(
                $request->user()->generations()->latest()->paginate(self::PER_PAGE),
            ),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGenerationAction $createGeneration): RedirectResponse
    {
        $createGeneration->execute($request->user(), $request->toDto());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation submitted.']);

        return to_route('generations.index');
    }
}
