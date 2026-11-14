document.querySelectorAll('[data-follow-me]').forEach((form) => {
    const list = form.querySelector('[data-follow-me-rows]');
    const template = form.querySelector('[data-follow-me-template]');
    const add = form.querySelector('[data-follow-me-add]');
    const toggle = form.querySelector('input[type="checkbox"]');
    const summary = form.querySelector('[data-follow-me-summary]');
    const submit = form.querySelector('button[type="submit"]');
    const formatter = new Intl.NumberFormat('fa-IR');
    const limit = Number(form.dataset.limit);
    const canAdd = !add.disabled;

    function refresh() {
        const rows = [...list.children];
        const selected = rows.filter((row) => row.querySelector('select').value);
        rows.forEach((row, index) => {
            const destination = row.querySelector('select');
            const duration = row.querySelector('input');
            const isExtension = destination.value.startsWith('extension:');
            row.querySelector('[data-follow-me-position]').textContent = formatter.format(index + 1);
            destination.name = `follow_me_targets[${index}][destination]`;
            duration.name = `follow_me_targets[${index}][seconds]`;
            duration.required = isExtension;
            duration.disabled = !isExtension;
            duration.closest('label').hidden = !isExtension;
            row.querySelector('[data-follow-me-move="-1"]').disabled = index === 0;
            row.querySelector('[data-follow-me-move="1"]').disabled = index === rows.length - 1;
        });
        add.disabled = !canAdd || rows.length >= limit;
        summary.textContent = toggle.checked
            ? `${formatter.format(selected.length)} مقصد از ${formatter.format(limit)} مقصد مجاز`
            : 'مسیر پشتیبان غیرفعال است.';
    }

    form.addEventListener('input', refresh);
    add.addEventListener('click', () => {
        list.append(template.content.cloneNode(true));
        refresh();
        list.lastElementChild.querySelector('select').focus();
    });
    list.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('[data-follow-me-row]');
        if (button.hasAttribute('data-follow-me-remove')) {
            row.remove();
            refresh();
            (list.firstElementChild?.querySelector('select') ?? add).focus();
            return;
        }
        const previous = button.dataset.followMeMove === '-1';
        const sibling = previous ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        if (previous) sibling.before(row);
        else sibling.after(row);
        refresh();
        row.querySelector('select').focus();
    });
    form.addEventListener('submit', () => { submit.disabled = true; });
    window.addEventListener('pageshow', () => { submit.disabled = false; refresh(); });
    refresh();
    form.querySelector('[role="alert"]')?.scrollIntoView({ block: 'nearest' });
});
