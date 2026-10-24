function initializeInventory() {
    document.querySelectorAll('[data-inventory-page]').forEach((page) => {
        const bulk = page.querySelector('[data-inventory-bulk]');
        if (bulk) {
            const rows = [...bulk.querySelectorAll('[data-select-row]')];
            const all = bulk.querySelector('[data-select-all]');
            const action = bulk.querySelector('[data-bulk-action]');
            const update = () => {
                const selected = rows.filter((row) => row.checked).length;
                bulk.querySelector('[data-selection-count]').textContent = selected;
                bulk.querySelector('[data-bulk-controls]').hidden = selected === 0;
                bulk.querySelector('[data-selection-hint]').hidden = selected > 0;
                bulk.querySelector('[data-bulk-submit]').disabled = selected === 0;
                all.checked = rows.length > 0 && selected === rows.length;
                all.indeterminate = selected > 0 && selected < rows.length;
                bulk.querySelectorAll('[data-action-fields]').forEach((fields) => {
                    const visible = selected > 0 && fields.dataset.actionFields.split(' ').includes(action.value);
                    if (fields.matches('details') && (action.value === 'price' || fields.querySelector('[name="amount"]')?.value)) fields.open = true;
                    fields.hidden = !visible;
                    fields.querySelectorAll('input, select').forEach((input) => {
                        input.disabled = !visible;
                        input.required = visible && (input.name === 'reason' || (action.value === 'price' && ['amount', 'plan_version_id'].includes(input.name)));
                    });
                });
                const help = bulk.querySelector('[data-publish-help]');
                if (help) help.hidden = action.value !== 'publish';
            };
            all.addEventListener('change', () => { rows.forEach((row) => { row.checked = all.checked; }); update(); });
            rows.forEach((row) => row.addEventListener('change', update));
            action.addEventListener('change', update);
            update();
        }
        if (page.matches('[data-import-form]')) {
            const update = () => {
                const publish = page.querySelector('[name="intent"]:checked').value === 'publish';
                page.querySelector('[data-import-review]').hidden = !publish;
                page.querySelector('[name="evidence"]').required = publish;
                page.querySelector('[data-import-submit]').textContent = publish ? 'ذخیره و عرضه شماره‌های انتخاب‌شده' : 'ذخیره شماره‌های انتخاب‌شده';
                page.querySelector('[data-import-submit]').disabled = !page.querySelector('[name="rows[]"]:checked');
            };
            page.addEventListener('change', update);
            update();
        }
    });
    const openAnchor = () => {
        const target = document.getElementById(location.hash.slice(1));
        if (target?.matches('details')) target.open = true;
    };
    document.querySelectorAll('a[href^="#"]').forEach((link) => link.addEventListener('click', () => {
        const target = document.getElementById(link.hash.slice(1));
        if (target?.matches('details')) target.open = true;
    }));
    window.addEventListener('hashchange', openAnchor);
    openAnchor();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeInventory);
else initializeInventory();
