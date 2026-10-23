<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\NumberOffer;
use Illuminate\View\View;

class PlansController extends Controller
{
    public function __invoke(): View
    {
        $separated = (bool) config('commerce.separated_billing_enabled');
        $plans = Plan::query()
            ->where('archived', false)
            ->whereNotNull('marketing')
            ->with(['versions' => fn ($query) => $query->whereNotNull('published_at')->orderByDesc('version')])
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan) => $separated
                ? $plan->versions->contains(fn ($version) => $version->monthly_price !== null)
                : (($plan->marketing['quote_only'] ?? false) || $plan->versions->isNotEmpty()))
            ->sortBy(fn (Plan $plan) => $plan->marketing['order'] ?? PHP_INT_MAX);
        $annualFrom = $separated ? NumberOffer::query()->where('billing_model', 'annual_number')
            ->whereNotNull('published_at')->whereNull('withdrawn_at')->min('annual_amount') : null;

        return view('marketing.plans', compact('plans', 'separated', 'annualFrom'));
    }
}
