# Extractable components

## PortalShell
- Source: resources/views/layouts/portal.blade.php
- Category: layout
- Description: Shared Persian RTL right sidebar, top bar, and content container.
- State props: activeItem, userName, adminMode.
- Hardcoded: Blucom identity, font, palette, icon paths.

## CallStatus
- Source: resources/views/dashboard/call-status.blade.php
- Category: basic
- State props: status.

## RecordingAction
- Source: resources/views/dashboard/recording-action.blade.php
- Category: basic
- State props: status, playHref, downloadHref, canDownload.
