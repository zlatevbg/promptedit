<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCreditPurchaseRequest;
use App\Services\CreditLedger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A fake purchase: no payment, the credits are simply added.
 */
class CreditPurchaseController extends Controller
{
    public function store(StoreCreditPurchaseRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $pack = $request->pack();

        $ledger->purchase($request->user(), $pack->nanodollars());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$pack->value} in credits."]);

        return back();
    }
}
