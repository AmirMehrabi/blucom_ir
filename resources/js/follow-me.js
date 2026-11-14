document.querySelectorAll('[data-follow-me]').forEach((form) => {
    const rows = [...form.querySelectorAll('[data-follow-me-row]')];
    const toggle = form.querySelector('input[type="checkbox"]');
    const summary = form.querySelector('[data-follow-me-summary]');
    const submit = form.querySelector('button[type="submit"]');
    const formatter = new Intl.NumberFormat('fa-IR');

    function updatePreview() {
        const selected = rows.filter((row) => row.querySelector('select').value);
        const seconds = selected.reduce((total, row) => total + (row.querySelector('input').valueAsNumber || 0), 0);
        summary.textContent = !toggle.checked
            ? 'مسیر پشتیبان غیرفعال است.'
            : selected.length === 0
                ? 'برای فعال‌سازی، یک مقصد انتخاب کنید.'
                : `${formatter.format(selected.length)} مقصد پشتیبان · حداکثر ${formatter.format(seconds)} ثانیه زنگ`;
        rows.forEach((row) => {
            const destination = row.querySelector('select');
            const duration = row.querySelector('input');
            duration.readOnly = !destination.value;
            duration.required = Boolean(destination.value);
            row.classList.toggle('opacity-60', !destination.value);
        });
    }

    form.addEventListener('input', updatePreview);
    form.addEventListener('submit', () => {
        submit.disabled = true;
    });
    window.addEventListener('pageshow', () => {
        submit.disabled = false;
        updatePreview();
    });
    updatePreview();
    form.querySelector('[role="alert"]')?.scrollIntoView({ block: 'nearest' });
});
