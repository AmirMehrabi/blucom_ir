# Blucom design system for customer onboarding

Persian-first, right-to-left, calm and clear. Preserve the existing Blucom navy and blue identity and use the existing Ravi font. Customer surfaces should use generous whitespace, one primary action per step, readable 16px body text, strong contrast, and a compact progress indicator. Make numbers visually easy to scan left-to-right. Use business language such as «ارائه‌دهنده خط», «شماره من», «چه کسی پاسخ دهد؟», «اتصال تلفن». Hide SIP, DID, gateway, trunk, XML, and extension terminology from the main customer flow. Show provider details only in a small advanced section.

Colors: navy #031B4E, page #f5f8fd, white cards, blue #0069ff primary, slate-900 body, emerald success, amber pending. Shared content cards use a 24px radius and onboarding controls use a 12px radius. Desktop layouts should work well at 1440px and mobile at 390px.

## Shared application shell

Reference direction: the server overview draft in the user's [Aviato Customer Server Tabs](https://superdesign.dev/teams/f360a220-aaf9-438c-8236-7ef0f22122cc/projects/4b03d8e7-f773-41e6-8453-c102bf420d85) project. Adapt its layout and spacing to Blucom's identity and navigation.

- Right sidebar: 256px, deep navy `#031B4E`, compact line icons, quiet section labels, 44px navigation rows, and a soft translucent active state.
- Header: white, 68px tall, a fine `#e2e8f0` bottom border, pill breadcrumbs with chevrons, and restrained actions.
- Page surface: `#f5f8fd`; ink `#0f172a`; muted text `#475569`; primary blue `#0069ff` and hover blue `#0050d0`.
- Controls: 12px radius; content cards: 24px radius with a subtle slate shadow. Use the existing Ravi font and Blucom wordmark treatment.
- Mobile: a compact top bar and a navy navigation drawer. Preserve RTL reading order and visible keyboard focus.
