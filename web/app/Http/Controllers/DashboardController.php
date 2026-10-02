<?php

namespace App\Http\Controllers;

use App\Services\Reporting\DashboardBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first page after signing in: how the shop is doing and what needs
 * attention. What each person sees depends on what they may see elsewhere.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardBuilder $dashboard): Response
    {
        return Inertia::render('dashboard', $dashboard->build($request->user()));
    }
}
