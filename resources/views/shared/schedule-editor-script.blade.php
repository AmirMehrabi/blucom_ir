<script>
function initializeScheduleEditors() {
    document.querySelectorAll('[data-schedule-editor]').forEach(root => {
        const fields = root.querySelector('[data-scheduled-fields]');
        const rows = [...root.querySelectorAll('[data-day]')];
        const refresh = () => {
            const scheduled = root.querySelector('[name="schedule_mode"]:checked')?.value === 'scheduled';
            fields.hidden = !scheduled;
            fields.querySelectorAll('input, select, button').forEach(input => input.disabled = !scheduled);
            rows.forEach(row => {
                const active = scheduled && row.querySelector('[data-day-enabled]').checked;
                row.querySelectorAll('[data-time-pair]').forEach(pair => {
                    pair.querySelectorAll('input').forEach(input => { input.disabled = !active || pair.hidden; input.required = active && !pair.hidden; });
                });
                row.querySelector('[data-add-interval]').disabled = !active || !row.querySelector('[data-time-pair][hidden]');
            });
            const action = root.querySelector('[data-closed-action]').value;
            const announcement = root.querySelector('[data-announcement]');
            announcement.hidden = action !== 'announcement';
            announcement.querySelector('input').disabled = !scheduled || action !== 'announcement';
            root.querySelector('[data-disconnect-hint]').hidden = action !== 'disconnect';
        };
        root.addEventListener('change', refresh);
        root.addEventListener('click', event => {
            const row = event.target.closest('[data-day]');
            if (event.target.closest('[data-add-interval]') && row) {
                const pair = row.querySelector('[data-time-pair][hidden]');
                if (pair) {
                    const visible = [...row.querySelectorAll('[data-time-pair]')].filter(item => !item.hidden);
                    const lastEnd = visible.at(-1)?.querySelectorAll('input')[1].value || '09:00';
                    const minutes = Number(lastEnd.slice(0, 2)) * 60 + Number(lastEnd.slice(3, 5));
                    if (minutes >= 1439) return;
                    const end = Math.min(1439, minutes + 60);
                    pair.querySelectorAll('input')[0].value = lastEnd;
                    pair.querySelectorAll('input')[1].value = `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}`;
                    pair.hidden = false;
                }
            }
            if (event.target.closest('[data-remove-interval]') && row) {
                const pair = event.target.closest('[data-time-pair]');
                pair.hidden = true;
                if (![...row.querySelectorAll('[data-time-pair]')].some(pair => !pair.hidden)) {
                    row.querySelector('[data-day-enabled]').checked = false;
                    pair.hidden = false;
                }
            }
            if (event.target.closest('[data-copy-hours]')) {
                const source = rows[Number(root.querySelector('[data-copy-source]').value)];
                root.querySelectorAll('[data-copy-target]:checked').forEach(target => {
                    const row = rows[Number(target.dataset.copyTarget)];
                    row.querySelector('[data-day-enabled]').checked = source.querySelector('[data-day-enabled]').checked;
                    const pairs = [...source.querySelectorAll('[data-time-pair]')];
                    row.querySelectorAll('[data-time-pair]').forEach((pair, index) => {
                        pair.hidden = pairs[index].hidden;
                        pair.querySelectorAll('input').forEach((input, i) => input.value = pairs[index].querySelectorAll('input')[i].value);
                    });
                });
            }
            if (event.target.closest('[data-add-date]') && root.querySelectorAll('[data-date-entry]').length < 30) {
                const fragment = root.querySelector('[data-date-template]').content.cloneNode(true);
                root.querySelector('[data-closed-dates]').append(fragment);
            }
            if (event.target.closest('[data-remove-date]')) event.target.closest('[data-date-entry]').remove();
            if (event.target.closest('[data-calendar]')) openCalendar(event.target.closest('[data-date-entry]').querySelector('input'));
            refresh();
        });
        refresh();
    });
}

function openCalendar(input) {
    document.querySelector('[data-jalali-dialog]')?.remove();
    const dialog = document.createElement('dialog');
    dialog.dataset.jalaliDialog = '';
    dialog.className = 'm-auto w-80 rounded-xl border border-slate-200 bg-white p-4 shadow-xl';
    dialog.setAttribute('aria-label', 'انتخاب تاریخ تعطیلی جلالی');
    const formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Tehran'});
    const ascii = value => value.replace(/[۰-۹]/g, c => '۰۱۲۳۴۵۶۷۸۹'.indexOf(c));
    const parts = date => Object.fromEntries(formatter.formatToParts(date).filter(p => p.type !== 'literal').map(p => [p.type, Number(ascii(p.value))]));
    let anchor = new Date(); anchor.setUTCHours(12, 0, 0, 0);
    const first = () => { const date = new Date(anchor); const month = parts(date).month; while (parts(date).day > 1 && parts(date).month === month) date.setUTCDate(date.getUTCDate() - 1); return date; };
    const render = () => {
        dialog.replaceChildren();
        const header = document.createElement('div'); header.className = 'mb-3 flex items-center justify-between';
        const button = (label, action) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.className = 'rounded-lg p-2 hover:bg-blue-50'; b.addEventListener('click', action); return b; };
        header.append(button('ماه قبل', () => { anchor = first(); anchor.setUTCDate(anchor.getUTCDate() - 1); render(); }));
        const title = document.createElement('strong'); title.textContent = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {month: 'long', year: 'numeric', timeZone: 'Asia/Tehran'}).format(anchor); header.append(title);
        header.append(button('ماه بعد', () => { anchor = first(); anchor.setUTCDate(anchor.getUTCDate() + 32); render(); }));
        dialog.append(header);
        const grid = document.createElement('div'); grid.className = 'grid grid-cols-7 gap-1 text-center text-sm';
        ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach(label => { const s = document.createElement('span'); s.textContent = label; grid.append(s); });
        const date = first(), month = parts(date).month;
        for (let i = 0; i < (date.getUTCDay() + 1) % 7; i++) grid.append(document.createElement('span'));
        while (parts(date).month === month) {
            const selected = new Date(date), p = parts(selected);
            grid.append(button(new Intl.NumberFormat('fa-IR').format(p.day), () => {
                input.value = `${p.year}/${String(p.month).padStart(2, '0')}/${String(p.day).padStart(2, '0')}`;
                input.dispatchEvent(new Event('change', {bubbles: true})); dialog.close(); dialog.remove(); input.focus();
            }));
            date.setUTCDate(date.getUTCDate() + 1);
        }
        dialog.append(grid, button('بستن', () => { dialog.close(); dialog.remove(); input.focus(); }));
    };
    document.body.append(dialog); render(); dialog.showModal();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeScheduleEditors, {once: true});
else initializeScheduleEditors();
</script>
