# Shared components
Blade partials and CSS utility panels.

### `resources/views/dashboard/call-status.blade.php`
```blade
<span @class(['inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-bold','bg-emerald-50 text-emerald-700'=>$call->status==='answered','bg-amber-50 text-amber-700'=>$call->status==='missed','bg-red-50 text-red-700'=>$call->status==='failed'])>{{ ['answered'=>'پاسخ‌داده‌شده','missed'=>'از دست‌رفته','failed'=>'ناموفق'][$call->status] ?? $call->status }}</span>

```

### `resources/views/dashboard/recording-action.blade.php`
```blade
@if($recording->status === 'ready' && $recording->expires_at?->isFuture())
    <a href="{{ route('recordings.index', ['play'=>$recording->id]) }}" class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-50 px-3 text-xs font-bold text-blue-700 hover:bg-blue-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" aria-label="پخش صدای تماس">▶ پخش</a>
    @if(auth()->user()->hasPermission('recordings.download'))<a href="{{ route('recordings.download', $recording->id) }}" class="inline-flex min-h-10 items-center rounded-xl px-3 text-xs font-bold text-slate-600 hover:bg-slate-100">دانلود</a>@endif
@else
    <span class="text-xs text-slate-500">{{ $recording->status === 'ready' ? 'منقضی‌شده' : $recording->statusLabel() }}</span>
@endif

```

### `resources/css/app.css`
```css
@import 'tailwindcss';
@import './landing.css';
@import './live-overview.css';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@font-face { font-family: Ravi; src: url('../../public/assets/fonts/ravi/Ravi-VF.ttf') format('truetype'); font-style: normal; font-weight: 100 900; font-display: swap; }
@theme { --font-sans: Ravi, 'Tahoma', ui-sans-serif, system-ui, sans-serif; --color-blue-600: #0069ff; --color-blue-700: #0050d0; }
@layer base { html, body, button, input, select, textarea { font-family: var(--font-sans); } body { -webkit-font-smoothing: antialiased; } * { border-color: #e2e8f0; } }
@layer components { .panel { @apply rounded-3xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]; } .nav-item { @apply flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-400 transition hover:bg-white/10 hover:text-white; } .nav-item.active { @apply bg-blue-600 text-white; } .eyebrow { @apply text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400; } .menu-summary { list-style: none; } .menu-summary::-webkit-details-marker { display: none; } }

/* Quiet scrollbars on the navy navigation, with native scrolling preserved. */
.sidebar-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(185, 214, 255, 0.32) transparent;
    scrollbar-gutter: stable;
}
.sidebar-scroll:hover,
.sidebar-scroll:focus-within {
    scrollbar-color: rgba(185, 214, 255, 0.55) transparent;
}
.sidebar-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
.sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
.sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(185, 214, 255, 0.32);
    border-radius: 999px;
}
.sidebar-scroll:hover::-webkit-scrollbar-thumb,
.sidebar-scroll:focus-within::-webkit-scrollbar-thumb { background: rgba(185, 214, 255, 0.55); }
.sidebar-scroll::-webkit-scrollbar-thumb:hover { background: rgba(185, 214, 255, 0.75); }
.sidebar-scroll::-webkit-scrollbar-button { display: none; }
@media (forced-colors: active) {
    .sidebar-scroll { scrollbar-color: auto; }
}

```
