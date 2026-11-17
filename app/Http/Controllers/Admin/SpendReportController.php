<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShowSpendReportRequest;
use App\Services\Pixelforge\PixelforgeClient;
use App\ViewModels\SpendReportViewModel;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    public function __invoke(ShowSpendReportRequest $request, PixelforgeClient $pixelforge): Response
    {
        $report = new SpendReportViewModel($request->from(), $request->to(), $pixelforge);

        return Inertia::render('admin/SpendReport', $report->toArray());
    }
}
