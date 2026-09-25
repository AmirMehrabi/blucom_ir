# Theme

## Compact token summary
- Direction: RTL for Persian content.
- Font: Vazirmatn, IRANSans, Tahoma, then sans-serif.
- Navy shell: `#071a3b`; background: `#f7f8fb`; blue accent: Tailwind blue-600; text: Tailwind slate-900.
- Cards: white, rounded-2xl, slate-200 border, subtle shadow.
- Spacing: Tailwind default scale; desktop shell with ~250px sidebar.
- Tailwind v4 CSS-first setup; no tailwind.config file.

## Raw source

### `resources/css/app.css`
```css
@import 'tailwindcss';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@theme { --font-sans: 'Vazirmatn', 'IRANSans', 'Tahoma', ui-sans-serif, system-ui, sans-serif; }
@layer base { body { font-family: var(--font-sans); -webkit-font-smoothing: antialiased; } * { border-color: #e7eaf0; } }
@layer components { .panel { @apply rounded-2xl border border-slate-200 bg-white shadow-sm; } .nav-item { @apply flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-400 transition hover:bg-white/10 hover:text-white; } .nav-item.active { @apply bg-blue-600 text-white shadow-lg shadow-blue-900/20; } .eyebrow { @apply text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400; } }

```
