<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\Commerce\InventoryWorkspace;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReadinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NumberInventoryController extends Controller
{
    public function index(Request $request, InventoryWorkspace $workspace, NumberReadinessService $readiness)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'], 'gateway_id' => ['nullable', 'integer'],
            'tenant_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(array_keys(InventoryWorkspace::STATUSES))],
            'min_price' => ['nullable', 'integer', 'min:0'], 'max_price' => ['nullable', 'integer', 'min:0'],
        ]);
        $query = $workspace->query($filters);
        // Readiness depends on the configuration fingerprint. Scan in bounded chunks,
        // retaining only IDs, and paginate full records in the database.
        $attentionIds = [];
        foreach ((clone $query)->whereNull('tenant_id')->whereIn('inventory_state', ['draft', 'available'])->lazyById(100) as $number) {
            if ($workspace->matches($number, 'attention')) {
                $attentionIds[] = $number->id;
            }
        }
        $scope = function ($query, $status) use ($attentionIds) {
            return match ($status) {
                'attention' => $query->whereIn('id', $attentionIds),
                'available' => $query->whereIn('inventory_state', ['draft', 'available'])->whereNotNull('current_offer_id'),
                'unpublished' => $query->whereIn('inventory_state', ['draft', 'available'])->whereNull('current_offer_id'),
                'hold' => $query->whereIn('inventory_state', ['disabled', 'quarantined']),
                'reserved', 'assigned' => $query->where('inventory_state', $status),
                default => $query,
            };
        };
        $counts = [];
        foreach (InventoryWorkspace::STATUSES as $key => $label) {
            $counts[$key] = $scope(clone $query, $key)->count();
        }
        $numbers = $scope($query, $filters['status'] ?? 'all')->orderBy('normalized_number')->paginate(50)->withQueryString();

        return response()->view('admin.inventory.index', compact('numbers', 'counts', 'workspace', 'readiness') + $this->options())->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request)
    {
        if (! $request->session()->hasOldInput() && ($input = $request->session()->get('inventory_import.input'))) {
            $request->session()->flashInput($input);
        }

        return response()->view('admin.inventory.create', $this->options())->header('Cache-Control', 'private, no-store');
    }

    private function options(): array
    {
        return [
            'gateways' => SipGateway::query()->whereNull('tenant_id')->orderBy('name')->get(),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'versions' => PlanVersion::query()->whereNotNull('published_at')->whereHas('plan', fn ($q) => $q->where('archived', false))->with('plan')->get(),
        ];
    }

    public function importPreview(Request $request, InventoryWorkspace $workspace)
    {
        $data = $request->validate([
            'numbers_text' => ['nullable', 'string', 'max:50000', 'required_without:csv'],
            'csv' => ['nullable', 'file', 'max:1024', 'extensions:csv,txt'],
        ]);
        $settings = $this->settings($request);
        $sale = $this->saleInput($request, $workspace);
        $settings['inventory_sale_settings'] = $sale;
        $lines = preg_split('/\r\n|\n|\r/', $request->hasFile('csv') ? file_get_contents($request->file('csv')->getRealPath()) : $data['numbers_text']);
        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));
        validator(['rows' => $lines], ['rows' => ['array', 'max:501']])->validate();
        $rows = $workspace->previewImport($lines);
        validator(['rows' => $rows], ['rows' => ['required', 'array', 'min:1', 'max:500']])->validate();
        $token = Str::random(40);
        $request->session()->put('inventory_import', compact('token', 'rows', 'settings') + ['input' => $request->except('csv', '_token')]);
        $request->session()->flashInput($request->except('csv', '_token'));

        return response()->view('admin.inventory.import-preview', compact('rows', 'token', 'settings') + $this->importSummary($settings))->header('Cache-Control', 'private, no-store');
    }

    private function importSummary(array $settings): array
    {
        return ['providerName' => SipGateway::query()->find($settings['provider_gateway_id'] ?? 0)?->name,
            'planName' => PlanVersion::query()->with('plan')->find($settings['inventory_sale_settings']['plan_version_id'] ?? 0)?->plan?->name];
    }

    public function importReview(Request $request)
    {
        $preview = $request->session()->get('inventory_import');
        if (! $preview) {
            return redirect()->route('admin.inventory.create');
        }

        return response()->view('admin.inventory.import-preview', $preview + $this->importSummary($preview['settings']))->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, NumberInventoryService $inventory, InventoryWorkspace $workspace, NumberOfferService $offers)
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['required', 'integer', 'min:0', 'distinct'], 'intent' => ['required', 'in:draft,publish'],
            'evidence' => ['exclude_unless:intent,publish', 'required_if:intent,publish', 'string', 'min:10', 'max:2000'],
        ]);
        $preview = $request->session()->get('inventory_import');
        abort_unless($preview && hash_equals($preview['token'], $data['token']), 419);
        $results = [];
        foreach ($data['rows'] as $index) {
            $row = $preview['rows'][$index] ?? null;
            if (! $row || $row['error']) {
                continue;
            }
            try {
                $number = DB::transaction(function () use ($request, $inventory, $workspace, $offers, $preview, $row, $data) {
                    $settings = $preview['settings'];
                    $settings['number'] = $row['number'];
                    $settings['label'] = $row['label'] ?: ($settings['label'] ?? null);
                    $number = $inventory->create($request->user(), $settings);
                    if ($data['intent'] === 'publish') {
                        $workspace->validateSale($settings['inventory_sale_settings'] ?? []);
                        $inventory->review($request->user(), $number->id, $number->inventory_revision, $data['evidence']);
                        $number->refresh();
                        $sale = $settings['inventory_sale_settings'];
                        $offers->publish($request->user(), $number->id, $number->inventory_revision, $sale['plan_version_id'], $sale['amount']);
                    }

                    return $number;
                });
                $results[] = ['number' => $row['normalized'], 'id' => $number->id, 'error' => null];
                $preview['rows'][$index]['error'] = 'قبلاً در این مرحله ذخیره شده است';
            } catch (ValidationException $e) {
                $results[] = ['number' => $row['normalized'], 'id' => null, 'error' => collect($e->errors())->flatten()->implode(' ')];
            }
        }
        $request->session()->put('inventory_import', $preview);
        $request->session()->flash('inventory_results', $results);

        return redirect()->route('admin.inventory.index')->with('status', 'نتیجه افزودن شماره‌ها؛ شماره‌های ناموفق ذخیره نشده‌اند.');
    }

    public function bulkPreview(Request $request, InventoryWorkspace $workspace)
    {
        $data = $this->bulkInput($request, $workspace);
        $rows = [];
        $plans = PlanVersion::query()->with('plan')->get()->keyBy('id');
        foreach ($data['selection'] as $id => $revision) {
            $number = SipNumber::query()->whereNotNull('inventory_state')->with(['providerGateway', 'currentOffer', 'latestOffer'])->find($id);
            $sale = isset($data['amount']) ? $workspace->saleData($data) : ($number ? $workspace->sale($number) : []);
            $rows[] = ['id' => $id, 'revision' => $revision, 'number' => $number?->normalized_number ?? (string) $id, 'sale' => $sale, 'plan' => $plans->get($sale['plan_version_id'] ?? 0)?->plan?->name,
                'error' => $number ? $workspace->problem($number, (int) $revision, $data['action'], $data) : 'شماره در موجودی نیست.'];
        }

        $token = Str::random(40);
        $request->session()->put('inventory_bulk_preview', compact('token', 'data', 'rows'));
        $request->session()->flash('inventory_selection', $data['selection']);
        $request->session()->flashInput($data);

        return response()->view('admin.inventory.bulk-preview', compact('rows', 'data', 'token'))->header('Cache-Control', 'private, no-store');
    }

    public function bulk(Request $request, InventoryWorkspace $workspace)
    {
        $request->validate(['preview_token' => ['required', 'string']]);
        $preview = $request->session()->get('inventory_bulk_preview');
        abort_unless($preview && hash_equals($preview['token'], $request->string('preview_token')->toString()), 419);
        $request->session()->forget('inventory_bulk_preview');
        $data = $preview['data'];
        $reviewedRows = collect($preview['rows'])->keyBy('id');
        $results = [];
        foreach ($data['selection'] as $id => $revision) {
            $number = SipNumber::query()->whereNotNull('inventory_state')->find($id);
            $error = $reviewedRows->get($id)['error'];
            if ($error !== null) {
                // A blocked row is never included merely because it became eligible after preview.
            } elseif (! $number) {
                $error = 'شماره در موجودی نیست.';
            } else {
                try {
                    $workspace->apply($request->user(), (int) $id, (int) $revision, $data['action'], $data);
                } catch (ValidationException $e) {
                    $error = collect($e->errors())->flatten()->implode(' ');
                }
            }
            $results[] = ['id' => $number?->id, 'number' => $number?->normalized_number ?? (string) $id, 'error' => $error];
        }
        $request->session()->flash('inventory_results', $results);
        // Keep failed rows selected for a fresh review/retry.
        $request->session()->flash('inventory_selection', collect($results)->filter(fn ($row) => $row['error'] && $row['id'])->mapWithKeys(fn ($row) => [$row['id'] => $data['selection'][$row['id']]])->all());

        return redirect()->route('admin.inventory.index')->with('status', 'عملیات پایان یافت؛ نتیجه هر شماره را ببینید.');
    }

    private function bulkInput(Request $request, InventoryWorkspace $workspace): array
    {
        $data = $request->validate([
            'selection' => ['required', 'array', 'min:1', 'max:500'], 'selection.*' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(array_keys(InventoryWorkspace::ACTIONS))],
            'reason' => ['required_if:action,withdraw,hold,restore', 'nullable', 'string', 'max:1000'],
            'label' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'required_if:action,price', 'integer', 'min:1', 'max:1000000000000'],
            'plan_version_id' => ['nullable', 'required_with:amount', 'integer', 'exists:plan_versions,id'],
        ]);
        foreach (array_keys($data['selection']) as $id) {
            abort_unless(ctype_digit((string) $id) && (int) $id > 0, 422);
        }
        if (isset($data['amount'])) {
            $workspace->validateSale($workspace->saleData($data));
        }

        return $data;
    }

    private function saleInput(Request $request, InventoryWorkspace $workspace): ?array
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'plan_version_id' => ['nullable', 'required_with:amount', 'integer', 'exists:plan_versions,id'],
        ]);
        if (! isset($data['amount'])) {
            return null;
        }
        $sale = $workspace->saleData($data);
        $workspace->validateSale($sale);

        return $sale;
    }

    public function export(Request $request, InventoryWorkspace $workspace)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(array_keys(InventoryWorkspace::STATUSES))],
            'gateway_id' => ['nullable', 'integer'], 'tenant_id' => ['nullable', 'integer'], 'min_price' => ['nullable', 'integer', 'min:0'], 'max_price' => ['nullable', 'integer', 'min:0']]);

        return response()->streamDownload(function () use ($filters, $workspace) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['number', 'label', 'provider', 'status', 'amount_IRT', 'billing_period', 'customer'], ',', '"', '');
            foreach ($workspace->query($filters)->lazyById(100) as $number) {
                if (! $workspace->matches($number, $filters['status'] ?? 'all')) {
                    continue;
                }
                $sale = $workspace->sale($number);
                $row = [$number->normalized_number, $number->label, $number->providerGateway?->name, InventoryWorkspace::STATUSES[$workspace->status($number)], $sale['amount'] ?? '', isset($sale['billing_model']) ? ($sale['billing_model'] === 'one_time_number' ? 'one_time' : ($sale['billing_model'] === 'annual_number' ? 'year' : 'month')) : '', $number->tenant?->name];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $row), ',', '"', '');
            }
            fclose($file);
        }, 'number-inventory.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, NumberInventoryService $inventory)
    {
        $number = $inventory->create($request->user(), $this->settings($request, true));

        return redirect()->route('admin.inventory.show', $number)->with('status', 'موجودی پیش‌نویس ساخته شد؛ هنوز منتشر نشده است.');
    }

    public function show(int $number, NumberReadinessService $readiness)
    {
        $stock = SipNumber::query()->whereNotNull('inventory_state')->with(['providerGateway', 'currentOffer.planVersion.plan', 'offers.planVersion.plan'])->findOrFail($number);

        return response()->view('admin.commerce.stock', [
            'number' => $stock,
            'gateways' => SipGateway::query()->whereNull('tenant_id')->orderBy('name')->get(),
            'versions' => PlanVersion::query()->whereNotNull('published_at')->whereHas('plan', fn ($q) => $q->where('archived', false))->with('plan')->get(),
            'issues' => $readiness->issues($stock, $stock->providerGateway),
            'sale' => app(InventoryWorkspace::class)->sale($stock),
            'workspace' => app(InventoryWorkspace::class),
            'events' => DB::table('commerce_audit_events')->leftJoin('users', 'users.id', '=', 'commerce_audit_events.actor_user_id')
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('resource_type', 'sip_number')->where('resource_id', $stock->id))
                    ->orWhere(fn ($q) => $q->where('resource_type', 'number_offer')->whereIn('resource_id', $stock->offers->pluck('id'))))
                ->orderByDesc('commerce_audit_events.id')->limit(30)->get(['commerce_audit_events.event', 'commerce_audit_events.reason', 'commerce_audit_events.created_at', 'users.name as actor_name']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $number, NumberInventoryService $inventory, NumberOfferService $offers, InventoryWorkspace $workspace)
    {
        $data = $this->settings($request);
        $intent = $request->validate(['intent' => ['nullable', 'in:save,publish']])['intent'] ?? 'save';
        if ($request->exists('amount') || $request->exists('plan_version_id')) {
            $data['inventory_sale_settings'] = $this->saleInput($request, $workspace);
        }
        if ($intent === 'publish') {
            $workspace->validateSale($data['inventory_sale_settings'] ?? []);
        }
        $revision = $this->revision($request);
        DB::transaction(function () use ($request, $number, $revision, $data, $intent, $inventory, $offers) {
            $stock = $inventory->change($request->user(), $number, $revision, 'update', $data);
            if ($intent === 'publish') {
                $sale = $data['inventory_sale_settings'];
                $offers->publish($request->user(), $number, $stock->inventory_revision, $sale['plan_version_id'], $sale['amount']);
            }
        });

        return back()->with('status', $intent === 'publish' ? 'تنظیمات ذخیره و شماره برای فروش عرضه شد.' : 'تنظیمات ذخیره شد؛ در صورت تغییر تنظیمات فنی، بررسی دوباره لازم است.');
    }

    public function review(Request $request, int $number, NumberInventoryService $inventory)
    {
        $data = $request->validate(['evidence' => ['required', 'string', 'min:10', 'max:2000']]);
        $inventory->review($request->user(), $number, $this->revision($request), $data['evidence']);

        return back()->with('status', 'بررسی فنی تنظیمات فعلی ثبت شد.');
    }

    public function publish(Request $request, int $number, NumberOfferService $offers)
    {
        $data = $request->validate([
            'plan_version_id' => ['required', 'integer', 'exists:plan_versions,id'],
            (config('commerce.separated_billing_enabled') ? 'annual_amount' : 'monthly_amount') => ['required', 'integer', 'min:1', 'max:1000000000000'],
        ]);
        $offers->publish($request->user(), $number, $this->revision($request), (int) $data['plan_version_id'],
            (int) ($data['annual_amount'] ?? $data['monthly_amount']));

        return back()->with('status', 'شماره برای فروش عرضه شد؛ نمایش به مشتری به فعال بودن کاتالوگ بستگی دارد.');
    }

    public function withdraw(Request $request, int $number, NumberOfferService $offers)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $offers->withdraw($request->user(), $number, $this->revision($request), $data['reason']);

        return back()->with('status', 'انتشار برداشته شد؛ تاریخچه قیمت محفوظ است.');
    }

    public function transition(Request $request, int $number, NumberInventoryService $inventory)
    {
        $data = $request->validate(['action' => ['required', 'in:disable,draft'], 'reason' => ['required', 'string', 'max:1000']]);
        $inventory->change($request->user(), $number, $this->revision($request), $data['action'], $data);

        return back()->with('status', 'وضعیت موجودی تغییر کرد.');
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
    }

    private function settings(Request $request, bool $create = false): array
    {
        $data = $request->validate([
            'number' => $create ? ['required', 'string', 'max:32'] : ['prohibited'],
            'label' => ['nullable', 'string', 'max:255'],
            'provider_gateway_id' => ['nullable', 'integer', Rule::exists('sip_gateways', 'id')->whereNull('tenant_id')],
            'enabled' => ['required', 'boolean'], 'inbound_enabled' => ['required', 'boolean'], 'outbound_enabled' => ['required', 'boolean'],
            'destination_prefixes_text' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['destination_prefixes'] = preg_split('/[\s,]+/', trim($data['destination_prefixes_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        validator($data, ['destination_prefixes' => ['array', 'max:50'], 'destination_prefixes.*' => ['string', 'regex:/^\+[1-9][0-9]{0,14}$/D']])->validate();

        return $data;
    }
}
