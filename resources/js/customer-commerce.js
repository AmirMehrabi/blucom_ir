function initializeCommerce() {
    const page = document.querySelector('[data-commerce-page]');
    if (!page) return;
    page.querySelectorAll('[data-commerce-submit]').forEach((form) => {
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (!button) return;
        const original = button.textContent;
        form.addEventListener('submit', (event) => {
            if (form.dataset.busy === 'true') { event.preventDefault(); return; }
            form.dataset.busy = 'true';
            button.disabled = true;
            button.textContent = button.dataset.busyLabel || 'لطفاً منتظر بمانید…';
            form.querySelector('[data-submit-message]')?.removeAttribute('hidden');
        });
        window.addEventListener('pageshow', () => {
            if (form.dataset.busy !== 'true') return;
            delete form.dataset.busy;
            button.disabled = false;
            button.textContent = original;
            form.querySelector('[data-submit-message]')?.setAttribute('hidden', '');
            updateExpiry?.();
        });
    });
    const timer = page.querySelector('[data-commerce-expiry]');
    let updateExpiry = null;
    if (timer) {
        const expiry = Date.parse(timer.dataset.commerceExpiry);
        const serverNow = Date.parse(timer.dataset.serverNow);
        const started = performance.now();
        const format = new Intl.NumberFormat('fa-IR', {minimumIntegerDigits: 2, useGrouping: false});
        updateExpiry = () => {
            const remaining = Math.max(0, Math.ceil((expiry - serverNow - (performance.now() - started)) / 1000));
            timer.querySelector('[data-countdown]').textContent = `${format.format(Math.floor(remaining / 60))}:${format.format(remaining % 60)}`;
            if (remaining > 0) return;
            timer.querySelector('[data-expiry-message]').hidden = false;
            page.querySelectorAll('[data-expiry-action]').forEach((action) => {
                action.setAttribute('aria-disabled', 'true');
                if (action.tagName === 'BUTTON') action.disabled = true;
                else { action.removeAttribute('href'); action.tabIndex = -1; action.classList.add('opacity-50'); }
            });
        };
        updateExpiry();
        let interval = setInterval(updateExpiry, 1000);
        window.addEventListener('pagehide', () => { clearInterval(interval); interval = null; });
        window.addEventListener('pageshow', () => {
            updateExpiry();
            if (interval === null) interval = setInterval(updateExpiry, 1000);
        });
    }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeCommerce, {once: true});
else initializeCommerce();
