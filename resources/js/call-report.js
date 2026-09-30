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
