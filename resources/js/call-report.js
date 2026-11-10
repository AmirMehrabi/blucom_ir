function initializeCallReportFilters() {
    document.querySelectorAll('[data-call-report-filter]').forEach((form) => {
        const range = form.querySelector('[data-report-range]');
        const dates = form.querySelector('[data-custom-dates]');
        const hour = form.querySelector('[data-report-hour]');
        if (!range || !dates) return;

        function updateRange() {
            const custom = range.value === 'custom';
            dates.hidden = !custom;
            dates.querySelectorAll('input').forEach((input) => {
                input.disabled = !custom;
                input.required = custom;
            });
            if (hour) hour.disabled = !custom;
        }
        range.addEventListener('change', updateRange);
        updateRange();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeCallReportFilters, { once: true });
} else {
    initializeCallReportFilters();
}

function initializeReportExports() {
    const panel = document.querySelector('[data-report-exports][data-pending="1"]');
    if (!panel) return;
    const check = async () => {
        try {
            const response = await fetch(window.location.href, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) return;
            const result = await response.json();
            if (JSON.stringify(result.state) !== JSON.stringify(JSON.parse(panel.dataset.state))) {
                window.location.reload();
                return;
            }
        } catch { /* Manual refresh remains available when the network is offline. */ }
        window.setTimeout(check, 10000);
    };
    window.setTimeout(check, 5000);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeReportExports, { once: true });
} else {
    initializeReportExports();
}

// A native form submission still works without JavaScript; prevent accidental duplicate requests.
document.querySelectorAll('form[data-export-request]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button');
        if (button) {
            button.dataset.originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'در حال ثبت درخواست…';
        }
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-export-request] button').forEach((button) => {
        if (button.dataset.originalLabel) {
            button.textContent = button.dataset.originalLabel;
            button.disabled = false;
        }
    });
});
