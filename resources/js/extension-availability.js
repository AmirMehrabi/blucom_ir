function initializeExtensionPause() {
    document.querySelectorAll('[data-pause-poll]').forEach((panel) => {
        let attempts = 0;
        const check = async () => {
            try {
                const response = await fetch(panel.dataset.pausePoll, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok || response.redirected) return;
                const state = await response.json();
                const message = panel.querySelector('[data-queue-pause-status]');
                if (String(state.version) !== panel.dataset.version) {
                    if (message) message.textContent = 'وضعیت تغییر کرده است؛ برای مشاهده وضعیت جدید صفحه را تازه کنید.';
                    return;
                }
                if (!state.pending) {
                    if (message) {
                        message.textContent = 'وضعیت تیم‌ها تأیید شد.';
                        message.classList.remove('text-amber-700');
                        message.classList.add('text-emerald-700');
                    }
                    return;
                }
            } catch { /* The visible pending state and manual refresh remain available. */ }
            if (++attempts < 12) window.setTimeout(check, 5000);
        };
        window.setTimeout(check, 3000);
    });
    document.querySelectorAll('form[data-extension-pause]').forEach((form) => {
        const button = form.querySelector('button');
        if (!button) return;
        button.dataset.label = button.textContent;
        form.addEventListener('submit', () => {
            button.disabled = true;
            button.textContent = 'در حال ثبت…';
        });
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeExtensionPause, { once: true });
} else initializeExtensionPause();
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-extension-pause] button').forEach((button) => {
        button.disabled = false;
        button.textContent = button.dataset.label || button.textContent;
    });
});
