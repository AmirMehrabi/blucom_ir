# Pages and dependencies

Blade views use `@extends('layouts.portal')`; Tailwind utilities come from `resources/css/app.css`. There are no recursive local UI component imports.

## dashboard
Entry: `resources/views/dashboard.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

## admin/sip-numbers/index
Entry: `resources/views/admin/sip-numbers/index.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

## sip-gateways/index
Entry: `resources/views/sip-gateways/index.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

## sip-extensions/index
Entry: `resources/views/sip-extensions/index.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

## inbound-routes/index
Entry: `resources/views/inbound-routes/index.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

## outbound-routes/index
Entry: `resources/views/outbound-routes/index.blade.php`
Dependencies:
- `resources/views/layouts/portal.blade.php`
  - `resources/css/app.css`

