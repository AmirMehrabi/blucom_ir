<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\View\View;

class PlansController extends Controller
{
    public function __invoke(): View
    {
        $plans = Plan::query()
            ->where('archived', false)
            ->whereNotNull('marketing')
            ->with(['versions' => fn ($query) => $query->whereNotNull('published_at')->orderByDesc('version')])
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan) => ($plan->marketing['quote_only'] ?? false) || $plan->versions->isNotEmpty())
            ->sortBy(fn (Plan $plan) => $plan->marketing['order'] ?? PHP_INT_MAX);

        return view('marketing.plans', compact('plans'));
    }
}
