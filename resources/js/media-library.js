const normalize = (value) => value.toLocaleLowerCase('fa').replace(/ي/g, 'ی').replace(/ك/g, 'ک').trim();
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };

document.addEventListener('play', (event) => {
    if (!(event.target instanceof HTMLAudioElement)) return;
    document.querySelectorAll('audio').forEach((audio) => {
        if (audio !== event.target) audio.pause();
    });
}, true);

const library = document.querySelector('[data-media-library]');
if (library) {
    const filter = library.querySelector('[data-media-filter]');
    const sort = library.querySelector('[data-media-sort]');
    const archived = library.querySelector('[data-media-archived]');
    const rows = library.querySelector('[data-media-rows]');
    const empty = library.querySelector('[data-media-empty]');
    const refresh = () => {
        const items = [...rows.children];
        items.sort((a, b) => sort.value === 'name'
            ? a.dataset.name.localeCompare(b.dataset.name, 'fa')
            : Number(b.dataset.created) - Number(a.dataset.created));
        for (const row of items) {
            row.hidden = (row.dataset.archived === 'yes') !== archived.checked
                || !normalize(row.dataset.name).includes(normalize(filter.value));
            if (row.hidden) row.querySelector('audio').pause();
            rows.append(row);
        }
        empty.hidden = items.some((row) => !row.hidden);
    };
    filter.addEventListener('input', refresh);
    sort.addEventListener('change', refresh);
    archived.addEventListener('change', refresh);
}

const dialog = document.querySelector('[data-media-picker]');
if (dialog) {
    const title = dialog.querySelector('h2');
    const search = dialog.querySelector('[data-picker-search]');
    const list = dialog.querySelector('[data-picker-list]');
    const browse = dialog.querySelector('[data-picker-browse]');
    const footer = dialog.querySelector('[data-picker-footer]');
    const upload = dialog.querySelector('[data-picker-upload]');
    const status = dialog.querySelector('[data-picker-status]');
    const preview = dialog.querySelector('[data-picker-preview]');
    const confirm = dialog.querySelector('[data-picker-confirm]');
    let items = [];
    let selected = null;
    let callback;
    let opener;
    let request;
    let uploadRequest;

    function render() {
        list.replaceChildren();
        const visible = items.filter((item) => normalize(item.name).includes(normalize(search.value)));
        if (!visible.length) {
            list.textContent = 'فایل صوتی پیدا نشد.';
            return;
        }
        for (const item of visible) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.asset = item.id;
            const active = selected?.id === item.id;
            button.setAttribute('aria-pressed', String(active));
            button.className = 'block w-full break-words rounded-xl border px-3 py-3 text-start text-sm ' + (active
                ? 'border-blue-600 bg-blue-50 font-bold' : 'border-slate-200 hover:bg-slate-50');
            button.textContent = `${item.name} · ${item.duration} ثانیه`;
            list.append(button);
        }
    }

    function choose(item) {
        selected = item;
        preview.pause();
        preview.hidden = !item;
        if (item) preview.src = item.preview;
        else preview.removeAttribute('src');
        confirm.disabled = !item;
    }

    async function open(trigger, current, onSelect) {
        opener = trigger;
        callback = onSelect;
        const selectMode = Boolean(onSelect);
        title.textContent = selectMode ? 'انتخاب فایل صوتی' : 'بارگذاری فایل صوتی';
        browse.hidden = !selectMode;
        footer.hidden = !selectMode;
        if (upload) upload.open = !selectMode;
        choose(null);
        list.replaceChildren();
        items = [];
        search.value = '';
        status.textContent = selectMode ? 'در حال دریافت فایل‌ها…' : '';
        dialog.showModal();
        if (!selectMode) {
            upload?.querySelector('input[type=file]').focus();
            return;
        }
        search.focus();
        request = new AbortController();
        try {
            const response = await fetch(dialog.dataset.list, { headers, signal: request.signal });
            if (!response.ok) throw new Error();
            items = await response.json();
            choose(items.find((item) => item.id === current) ?? null);
            status.textContent = '';
            render();
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'دریافت فایل‌ها ممکن نبود. پنجره را ببندید و دوباره تلاش کنید.';
        }
    }

    dialog.querySelector('[data-media-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        request?.abort();
        uploadRequest?.abort();
        preview.pause();
        opener?.focus();
    });
    search.addEventListener('input', render);
    list.addEventListener('click', (event) => {
        const row = event.target.closest('[data-asset]');
        if (!row) return;
        choose(items.find((item) => item.id === row.dataset.asset));
        render();
        [...list.children].find((button) => button.dataset.asset === row.dataset.asset)?.focus();
    });
    confirm.addEventListener('click', () => {
        if (!selected) return;
        callback(selected);
        dialog.close();
    });

    document.querySelector('[data-media-upload-open]')?.addEventListener('click', (event) => open(event.currentTarget));
    if (upload) {
        const form = upload.querySelector('form');
        const audio = form.elements.audio;
        const name = form.elements.name;
        const drop = form.querySelector('[data-media-drop]');
        const submit = form.querySelector('button');
        audio.addEventListener('change', () => {
            name.value ||= audio.files[0]?.name.replace(/\.[^.]+$/, '').slice(0, 100) ?? '';
        });
        drop.addEventListener('dragover', (event) => event.preventDefault());
        drop.addEventListener('drop', (event) => {
            event.preventDefault();
            const file = event.dataTransfer.files[0];
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            audio.files = transfer.files;
            audio.dispatchEvent(new Event('change'));
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (audio.files[0]?.size > 10 * 1024 * 1024) {
                status.textContent = 'حجم فایل باید حداکثر ۱۰ مگابایت باشد.';
                return;
            }
            const xhr = new XMLHttpRequest();
            uploadRequest = xhr;
            xhr.open('POST', dialog.dataset.store);
            xhr.responseType = 'json';
            xhr.setRequestHeader('Accept', 'application/json');
            submit.disabled = true;
            status.textContent = 'در حال بارگذاری…';
            xhr.upload.addEventListener('load', () => { status.textContent = 'در حال آماده‌سازی فایل صوتی…'; });
            xhr.addEventListener('loadend', () => {
                submit.disabled = false;
                uploadRequest = null;
            });
            xhr.addEventListener('error', () => { status.textContent = 'ارتباط قطع شد. دوباره تلاش کنید.'; });
            xhr.addEventListener('load', () => {
                if (xhr.status !== 201) {
                    status.textContent = Object.values(xhr.response?.errors ?? {}).flat()[0]
                        ?? 'بارگذاری انجام نشد. دوباره تلاش کنید.';
                    return;
                }
                form.reset();
                if (!callback) {
                    window.location.reload();
                    return;
                }
                request?.abort();
                const item = xhr.response;
                items.unshift(item);
                search.value = '';
                choose(item);
                render();
                upload.open = false;
                status.textContent = 'فایل آماده است. می‌توانید آن را بشنوید و انتخاب را تأیید کنید.';
                confirm.focus();
            });
            xhr.send(new FormData(form));
        });
    }

    for (const field of document.querySelectorAll('[data-media-greeting-field]')) {
        const greeting = field.querySelector('[data-media-greeting]');
        const greetingButton = field.querySelector('[data-media-choose-greeting]');
        if (!greetingButton) continue;
        const current = field.querySelector('[data-media-current]');
        const player = field.querySelector('[data-media-greeting-preview]');
        const directUpload = field.querySelector('input[type=file][name=greeting]');
        function applyGreeting(item) {
            greeting.value = item.id;
            directUpload.value = '';
            current.textContent = `${item.name} · ${item.duration} ثانیه`;
            player.src = item.preview;
            player.hidden = false;
            greetingButton.textContent = 'تغییر فایل صوتی';
        }
        greetingButton.addEventListener('click', () => open(greetingButton, greeting.value, applyGreeting));
        directUpload.addEventListener('change', () => {
            if (!directUpload.files.length) return;
            greeting.value = '';
            current.textContent = directUpload.files[0].name;
            player.pause();
            player.hidden = true;
        });

        const importButton = field.querySelector('[data-media-import]');
        importButton?.addEventListener('click', async () => {
            importButton.disabled = true;
            const result = field.querySelector('[data-media-import-status]');
            try {
                const response = await fetch(importButton.dataset.mediaImport, { method: 'POST', headers });
                if (!response.ok) throw new Error();
                applyGreeting(await response.json());
                result.textContent = 'در کتابخانه ذخیره شد. برای اعمال در منو، پیش‌نویس را ذخیره کنید.';
                importButton.hidden = true;
            } catch {
                result.textContent = 'ذخیره انجام نشد. دوباره تلاش کنید.';
            } finally {
                importButton.disabled = false;
            }
        });
    }

    const connect = (select, pickerOption, prefix = '') => {
        let previous = select.value;
        pickerOption.hidden = false;
        pickerOption.disabled = false;
        select.addEventListener('change', () => {
            if (select.value !== pickerOption.value) {
                previous = select.value;
                return;
            }
            select.value = previous;
            open(select, previous.startsWith(prefix) ? previous.slice(prefix.length) : '', (item) => {
                const value = prefix + item.id;
                if (![...select.options].some((option) => option.value === value)) {
                    select.add(new Option(item.name, value));
                }
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    };
    for (const option of document.querySelectorAll('option[value="__media_picker"]')) {
        connect(option.closest('select'), option, 'announcement:');
    }
    for (const select of document.querySelectorAll('[data-media-select]')) {
        const option = new Option('انتخاب از کتابخانه صوتی…', '__media_picker');
        select.add(option, 1);
        connect(select, option);
    }
}
