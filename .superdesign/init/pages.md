# Pages and dependencies

## /dashboard
- resources/views/dashboard.blade.php
  - resources/views/dashboard/call-status.blade.php
  - resources/views/dashboard/recording-action.blade.php
  - resources/views/layouts/portal.blade.php
    - resources/views/layouts/partials/sidebar-nav.blade.php
    - resources/css/app.css
      - resources/css/landing.css
      - resources/css/live-overview.css
    - resources/js/app.js
      - resources/js/call-report.js
      - resources/js/recordings.js
      - resources/js/live-overview.js

## /calls
- resources/views/calls/index.blade.php
  - resources/views/layouts/portal.blade.php (same shared dependencies)

## /calls/{callRecord}
- resources/views/calls/show.blade.php
  - resources/views/dashboard/recording-action.blade.php
  - resources/views/layouts/portal.blade.php (same shared dependencies)

## /recordings
- resources/views/recordings/index.blade.php
  - resources/views/recordings/player.blade.php
  - resources/views/layouts/portal.blade.php (same shared dependencies)

## /live
- resources/views/live-overview/index.blade.php
  - resources/views/layouts/portal.blade.php (same shared dependencies)
