<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Commerce\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('admin.commerce.plans', ['plans' => Plan::query()->with('versions')->latest()->get()]);
    }

    public function store(Request $request, PlanService $plans)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $plans->create($request->user(), $data['name']);

        return back()->with('status', 'پلن ساخته شد؛ نسخه و سقف‌ها را مشخص کنید.');
    }

    public function version(Request $request, int $plan, PlanService $plans)
    {
        $limits = $request->validate([
            'extensions' => ['required', 'integer', 'min:1', 'max:10000'],
            'queues' => ['required', 'integer', 'min:1', 'max:10000'],
            'ivr_menus' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $plans->version($request->user(), $plan, $limits);

        return back()->with('status', 'نسخه پیش‌نویس ساخته شد.');
    }

    public function editDraft(Request $request, int $version, PlanService $plans)
    {
        $data = $request->validate([
            'extensions' => ['required', 'integer', 'min:1', 'max:10000'],
            'queues' => ['required', 'integer', 'min:1', 'max:10000'],
            'ivr_menus' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $plans->editDraft($request->user(), $version, $data);

        return back()->with('status', 'سقف‌های نسخه پیش‌نویس ذخیره شد.');
    }

    public function publish(Request $request, int $version, PlanService $plans)
    {
        $plans->publish($request->user(), $version);

        return back()->with('status', 'نسخه پلن منتشر شد و دیگر قابل تغییر نیست.');
    }

    public function archive(Request $request, int $plan, PlanService $plans)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $plans->archive($request->user(), $plan, $data['reason']);

        return back()->with('status', 'پلن آرشیو شد.');
    }
}
