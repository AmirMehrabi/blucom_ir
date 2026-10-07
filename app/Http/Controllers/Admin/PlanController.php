<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NumberOffer;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Commerce\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $filter = $request->query('filter', 'all');
        $plans = Plan::query()->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($filter === 'archived', fn ($query) => $query->where('archived', true), fn ($query) => $query->where('archived', false))
            ->when($filter === 'published', fn ($query) => $query->whereHas('versions', fn ($versions) => $versions->whereNotNull('published_at')))
            ->when($filter === 'unpublished', fn ($query) => $query->whereDoesntHave('versions', fn ($versions) => $versions->whereNotNull('published_at')))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.commerce.plans', compact('plans', 'search', 'filter'));
    }

    public function create()
    {
        return view('admin.commerce.plan-create');
    }

    public function show(Plan $plan)
    {
        $plan->load(['versions' => fn ($query) => $query->orderByDesc('version')]);
        $published = $plan->versions->first(fn ($version) => $version->published_at !== null);
        $drafts = $plan->versions->filter(fn ($version) => $version->published_at === null);
        $offers = NumberOffer::query()->with(['number', 'planVersion'])->whereIn('plan_version_id', $plan->versions->pluck('id'))->whereNull('withdrawn_at')->get();

        return view('admin.commerce.plan-show', compact('plan', 'published', 'drafts', 'offers'));
    }

    public function review(PlanVersion $version)
    {
        abort_if($version->published_at || $version->plan->archived, 404);
        $published = $version->plan->versions()->whereNotNull('published_at')->orderByDesc('version')->first();
        $fingerprint = hash('sha256', json_encode($version->limits));

        return view('admin.commerce.plan-review', compact('version', 'published', 'fingerprint'));
    }

    public function store(Request $request, PlanService $plans)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']] + $this->limitRules());
        $plan = DB::transaction(function () use ($request, $plans, $data) {
            $plan = $plans->create($request->user(), $data['name']);
            $plans->version($request->user(), $plan->id, array_intersect_key($data, $this->limitRules()));

            return $plan;
        });

        return redirect()->route('admin.plans.show', $plan)->with('status', 'پلن و اولین پیش‌نویس ساخته شدند.');
    }

    private function limitRules(): array
    {
        return array_fill_keys(['extensions', 'queues', 'ivr_menus'], ['required', 'integer', 'min:1', 'max:10000']);
    }

    public function version(Request $request, int $plan, PlanService $plans)
    {
        $limits = $request->validate([
            'extensions' => ['required', 'integer', 'min:1', 'max:10000'],
            'queues' => ['required', 'integer', 'min:1', 'max:10000'],
            'ivr_menus' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $plans->version($request->user(), $plan, $limits);

        return redirect()->route('admin.plans.show', $plan)->with('status', 'نسخه پیش‌نویس ساخته شد.');
    }

    public function editDraft(Request $request, int $version, PlanService $plans)
    {
        $data = $request->validate([
            'extensions' => ['required', 'integer', 'min:1', 'max:10000'],
            'queues' => ['required', 'integer', 'min:1', 'max:10000'],
            'ivr_menus' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $plans->editDraft($request->user(), $version, $data);

        if ($request->input('intent') === 'review') {
            return redirect()->route('admin.plans.review', $version);
        }

        return back()->with('status', 'سقف‌های نسخه پیش‌نویس ذخیره شد.');
    }

    public function publish(Request $request, int $version, PlanService $plans)
    {
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $plans->publish($request->user(), $version, $data['fingerprint']);

        return redirect()->route('admin.plans.show', PlanVersion::findOrFail($version)->plan_id)->with('status', 'نسخه پلن منتشر شد و دیگر قابل تغییر نیست.');
    }

    public function archive(Request $request, int $plan, PlanService $plans)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $plans->archive($request->user(), $plan, $data['reason']);

        return back()->with('status', 'پلن آرشیو شد.');
    }
}
