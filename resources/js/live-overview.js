const statuses = {
    ready: 'آماده پاسخ', talking: 'در حال مکالمه', ringing: 'در حال زنگ',
    dialing: 'در حال شماره‌گیری', connecting: 'در حال اتصال', hold: 'در انتظار',
    offline: 'آفلاین', break: 'در استراحت', disabled: 'غیرفعال', unknown: 'نامشخص',
};
const activeStates = ['talking', 'hold', 'dialing', 'connecting'];
const directions = { inbound: 'تماس ورودی', outbound: 'تماس خروجی', internal: 'تماس داخلی' };
const fa = new Intl.NumberFormat('fa-IR');
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
const badge = (state) => `<span class="live-badge live-badge-${escape(state)}"><i></i>${escape(statuses[state] || statuses.unknown)}</span>`;

function initialize(root) {
    let state = JSON.parse(root.querySelector('[data-live-initial]').textContent);
    let filter = 'all';
    let team = new URLSearchParams(location.search).get('team') || 'all';
    let query = '';
    let selected = null;
    let pending = false;
    let failed = false;
    let clockOffset = state.server_time * 1000 - Date.now();
    let fetchedAt = Date.now();
    let websocket = false;
    let view = 'grid';
    try { view = localStorage.getItem('blucom.live.view') || 'grid'; } catch { /* Storage is optional. */ }
    let echo;
    const find = (selector) => root.querySelector(selector);
    const grid = find('[data-live-grid]');
    const drawer = find('[data-live-drawer]');
    const now = () => Math.floor((Date.now() + clockOffset) / 1000);
    const connection = (phone) => !phone.enabled ? 'تلفن غیرفعال' : phone.registered === null ? 'اتصال نامشخص' : phone.registered ? 'تلفن متصل' : 'تلفن قطع';
    const timer = (call) => `<b dir="ltr" data-live-timer="${Number(call.started_at)}"></b>`;
    const teamNames = (phone) => phone.teams.map((id) => state.teams.find((item) => item.id === id)?.name).filter(Boolean);
    const matches = (phone, key) => key === 'all' || (key === 'active' ? activeStates.includes(phone.status) : key === 'registered' ? phone.enabled && phone.registered === true : key === 'break' ? phone.enabled && phone.availability === 'break' : phone.status === key);

    function updateTimers() {
        root.querySelectorAll('[data-live-timer]').forEach((element) => {
            const elapsed = Math.max(0, now() - Number(element.dataset.liveTimer));
            const hours = Math.floor(elapsed / 3600);
            element.textContent = `${hours ? String(hours).padStart(2, '0') + ':' : ''}${String(Math.floor(elapsed / 60) % 60).padStart(2, '0')}:${String(elapsed % 60).padStart(2, '0')}`;
        });
    }

    function health() {
        const fresh = state.connected && now() - (state.updated_at || 0) <= 20 && Date.now() - fetchedAt < 22000 && !failed;
        const indicator = find('[data-live-health]');
        indicator.classList.toggle('is-live', fresh);
        indicator.classList.toggle('is-stale', !fresh);
        indicator.querySelector('span').textContent = fresh ? (websocket ? 'زنده' : 'به‌روزرسانی خودکار') : 'وضعیت زنده نامشخص';
        find('[data-live-warning]').hidden = fresh;
        find('[data-live-warning-title]').textContent = failed ? 'ارتباط با پنل قطع شده است' : 'وضعیت زنده در دسترس نیست';
        find('[data-live-warning-description]').textContent = failed ? 'در حال تلاش برای اتصال دوباره. اطلاعات قبلی وضعیت فعلی را نشان نمی‌دهد.' : 'تا برقراری اتصال، وضعیت تلفن‌ها نامشخص است؛ این به معنی آفلاین بودن آن‌ها نیست.';
        find('[data-live-updated]').textContent = state.updated_at ? `آخرین دریافت: ${new Date(state.updated_at * 1000).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : 'هنوز وضعیت زنده دریافت نشده است';
        if (!fresh && state.extensions.some((phone) => phone.enabled && phone.status !== 'unknown')) {
            state = { ...state, connected: false, extensions: state.extensions.map((phone) => phone.enabled ? { ...phone, status: 'unknown', registered: null, devices: null, calls: [] } : phone) };
            render();
        }
    }

    function card(phone) {
        const call = phone.calls[0];
        const footer = call
            ? `<span>${escape(directions[call.direction])}</span>${call.number ? `<b dir="ltr">${escape(call.number)}</b>` : ''}${timer(call)}`
            : `<span class="live-phone-team">${escape(teamNames(phone).join('، ') || 'بدون تیم')}</span>${phone.availability === 'break' ? '<span class="live-phone-extra">در استراحت</span>' : ''}`;
        return `<span class="live-phone-top"><span class="live-avatar">${escape([...phone.name][0] || '—')}</span><span class="live-phone-identity"><span class="live-phone-name">${escape(phone.name)}${phone.own ? ' <small>· من</small>' : ''}</span><span class="live-phone-number">داخلی <b dir="ltr">${escape(phone.extension)}</b></span></span><span class="live-phone-chevron" aria-hidden="true">‹</span></span><span class="live-phone-status">${badge(phone.status)}<span class="live-phone-connection ${phone.registered ? 'is-connected' : ''}"><i></i>${connection(phone)}</span></span><span class="live-phone-call">${footer}</span>${phone.calls.length > 1 ? `<span class="live-phone-extra">${fa.format(phone.calls.length)} تماس هم‌زمان</span>` : ''}`;
    }

    function render() {
        const teamSelect = find('[data-live-team]');
        const teamHtml = '<option value="all">همه تیم‌ها</option><option value="none">بدون تیم</option>' + state.teams.map((item) => `<option value="${item.id}">${escape(item.name)}${item.enabled ? '' : ' · غیرفعال'}</option>`).join('');
        if (teamSelect.innerHTML !== teamHtml) teamSelect.innerHTML = teamHtml;
        if (team !== 'all' && team !== 'none' && !state.teams.some((item) => String(item.id) === team)) team = 'all';
        teamSelect.value = team;
        const scoped = state.extensions.filter((phone) => team === 'all' || (team === 'none' ? phone.teams.length === 0 : phone.teams.some((id) => String(id) === team)));
        const visible = scoped.filter((phone) => matches(phone, filter) && (!query || normalize(`${phone.name} ${phone.extension}`).includes(normalize(query))));
        find('[data-live-total]').textContent = `(${fa.format(state.extensions.length)})`;
        find('[data-live-result-count]').textContent = `${fa.format(visible.length)} از ${fa.format(scoped.length)} تلفن`;
        root.querySelectorAll('[data-live-count]').forEach((element) => {
            const key = element.dataset.liveCount;
            element.textContent = key !== 'all' && !state.connected ? '—' : fa.format(scoped.filter((phone) => matches(phone, key)).length);
        });
        root.querySelectorAll('[data-live-filter]').forEach((button) => {
            const active = button.dataset.liveFilter === filter;
            button.classList.toggle('is-selected', active);
            button.setAttribute('aria-pressed', String(active));
        });
        find('[data-live-status]').value = filter;
        find('[data-live-clear]').hidden = filter === 'all' && team === 'all' && !query;
        // Reuse existing buttons so real-time updates preserve keyboard focus and ordering.
        const existing = new Map([...grid.children].map((button) => [Number(button.dataset.extensionId), button]));
        for (const phone of visible) {
            let button = existing.get(phone.id);
            if (!button) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = 'live-phone';
                button.dataset.extensionId = phone.id;
                button.addEventListener('click', () => open(phone.id));
            }
            existing.delete(phone.id);
            button.dataset.status = phone.status;
            button.setAttribute('aria-label', `${phone.name}، داخلی ${phone.extension}، ${statuses[phone.status]}، جزئیات`);
            const html = card(phone);
            // Timers are updated independently; do not redraw every second.
            if (button._content !== html) { button.innerHTML = html; button._content = html; }
            grid.append(button);
        }
        existing.forEach((button) => button.remove());
        find('[data-live-empty]').hidden = visible.length > 0;
        const empty = state.extensions.length === 0;
        find('[data-live-empty-title]').textContent = empty ? 'هنوز تلفنی اضافه نشده است' : 'تلفنی با این فیلتر پیدا نشد';
        find('[data-live-empty-description]').textContent = empty ? 'با افزودن داخلی‌ها، وضعیت آن‌ها اینجا نمایش داده می‌شود.' : 'نام، تیم یا وضعیت دیگری را انتخاب کنید.';
        const own = state.extensions.find((phone) => phone.own);
        find('[data-live-personal]').hidden = !own;
        if (own) {
            find('[data-live-own-label]').textContent = `داخلی ${own.extension}`;
            find('[data-live-own-state]').textContent = `${connection(own)} · ${statuses[own.status]}`;
            const action = find('[data-live-availability]');
            action.hidden = !state.can_set_availability || !own.enabled;
            action.textContent = own.availability === 'available' ? 'رفتن به استراحت' : 'آماده پاسخ‌گویی هستم';
        }
        if (selected !== null) renderDetail();
        updateTimers();
    }

    function normalize(value) {
        return value.toLowerCase().replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace(/ي/g, 'ی').replace(/ك/g, 'ک').trim();
    }

    function renderDetail() {
        const phone = state.extensions.find((item) => item.id === selected);
        if (!phone) { drawer.close(); selected = null; return; }
        find('[data-live-detail]').innerHTML = `<div class="live-detail-identity"><span class="live-avatar">${escape([...phone.name][0])}</span><div><h2 id="live-detail-title">${escape(phone.name)}</h2><p>داخلی <b dir="ltr">${escape(phone.extension)}</b></p></div></div>${badge(phone.status)}<section class="live-detail-section"><h3>اتصال تلفن</h3><dl><div class="live-detail-row"><dt>وضعیت اتصال</dt><dd>${connection(phone)}</dd></div><div class="live-detail-row"><dt>دستگاه‌های ثبت‌شده</dt><dd>${phone.devices === null ? 'نامشخص' : fa.format(phone.devices)}</dd></div><div class="live-detail-row"><dt>آمادگی برای تماس تیم</dt><dd>${phone.availability === 'available' ? 'آماده پاسخ‌گویی' : 'در استراحت'}</dd></div></dl><p class="live-detail-note">ثبت بودن تلفن به معنی آماده بودن شخص نیست. قطع ناگهانی دستگاه ممکن است پس از پایان اعتبار ثبت، نمایش داده شود.</p></section><section class="live-detail-section"><h3>تماس‌های جاری</h3>${phone.calls.length ? phone.calls.map((call) => `<div class="live-detail-call"><div class="live-detail-call-head">${badge(call.state)}${timer(call)}</div><dl><div class="live-detail-row"><dt>نوع تماس</dt><dd>${escape(directions[call.direction])}</dd></div>${call.number ? `<div class="live-detail-row"><dt>طرف تماس</dt><dd dir="ltr">${escape(call.number)}</dd></div>` : ''}</dl></div>`).join('') : `<p class="live-detail-note">${phone.status === 'unknown' ? 'اطلاعات تماس در دسترس نیست.' : 'تماس جاری ندارد.'}</p>`}${!state.can_view_calls && phone.calls.length ? '<p class="live-detail-note">شماره طرف تماس با دسترسی گزارش تماس‌ها نمایش داده می‌شود.</p>' : ''}</section><section class="live-detail-section"><h3>تیم‌ها</h3><div class="live-detail-teams">${teamNames(phone).length ? teamNames(phone).map((name) => `<span>${escape(name)}</span>`).join('') : '<p class="live-detail-note">عضو تیمی نیست.</p>'}</div></section>`;
        updateTimers();
    }

    function open(id) { selected = id; renderDetail(); drawer.showModal(); }
    find('[data-live-close]').addEventListener('click', () => drawer.close());
    drawer.addEventListener('close', () => { selected = null; });
    drawer.addEventListener('click', (event) => { if (event.target === drawer && event.clientX > drawer.getBoundingClientRect().right) drawer.close(); });
    find('[data-live-search]').addEventListener('input', (event) => { query = event.target.value; render(); });
    find('[data-live-team]').addEventListener('change', (event) => { team = event.target.value; render(); });
    find('[data-live-status]').addEventListener('change', (event) => { filter = event.target.value; render(); });
    root.querySelectorAll('[data-live-filter]').forEach((button) => button.addEventListener('click', () => { filter = button.dataset.liveFilter; render(); }));
    find('[data-live-clear]').addEventListener('click', () => { filter = team = 'all'; query = ''; find('[data-live-search]').value = ''; render(); });
    function setView(next) {
        view = next;
        grid.classList.toggle('is-list', view === 'list');
        root.querySelectorAll('[data-live-view]').forEach((button) => { const active = button.dataset.liveView === view; button.classList.toggle('is-selected', active); button.setAttribute('aria-pressed', String(active)); });
        try { localStorage.setItem('blucom.live.view', view); } catch { /* Private browsing may disable storage. */ }
    }
    root.querySelectorAll('[data-live-view]').forEach((button) => button.addEventListener('click', () => setView(button.dataset.liveView)));

    async function refresh() {
        if (pending || document.hidden) return;
        pending = true;
        try {
            const response = await fetch(root.dataset.stateUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(8000) });
            if ([401, 403, 419].includes(response.status)) { window.location.reload(); return; }
            if (!response.ok) throw new Error('unavailable');
            state = await response.json();
            clockOffset = state.server_time * 1000 - Date.now();
            fetchedAt = Date.now();
            failed = false;
            render();
        } catch { failed = true; } finally { pending = false; health(); }
    }
    find('[data-live-refresh]').addEventListener('click', refresh);
    find('[data-live-availability]').addEventListener('click', async () => {
        const own = state.extensions.find((phone) => phone.own);
        const button = find('[data-live-availability]');
        const error = find('[data-live-availability-error]');
        button.disabled = true; error.hidden = true;
        try {
            const response = await fetch(root.dataset.availabilityUrl, {
                method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(8000),
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ status: own.availability === 'available' ? 'On Break' : 'Available' }),
            });
            if (!response.ok || response.redirected) throw new Error('unavailable');
            await refresh();
        } catch { error.textContent = 'تغییر وضعیت انجام نشد. دوباره تلاش کنید.'; error.hidden = false; } finally { button.disabled = false; }
    });

    async function connect() {
        if (!root.dataset.reverbKey) return;
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            echo = new Echo({ broadcaster: 'reverb', client: new Pusher(root.dataset.reverbKey, {
                wsHost: window.location.hostname, wsPort: 80, wssPort: 443, forceTLS: location.protocol === 'https:',
                enabledTransports: ['ws', 'wss'], cluster: '',
                channelAuthorization: { endpoint: '/broadcasting/auth', transport: 'ajax', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } },
            }) });
            const client = echo.connector.pusher;
            client.connection.bind('connected', () => { websocket = true; refresh(); health(); });
            client.connection.bind('disconnected', () => { websocket = false; health(); });
            client.connection.bind('unavailable', () => { websocket = false; health(); });
            echo.private(`live-overview.${state.tenant.id}`).listen('.overview.updated', () => refresh());
        } catch { websocket = false; }
    }
    setView(view);
    render(); health(); connect(); refresh();
    const polling = setInterval(refresh, 5000);
    const clock = setInterval(() => { updateTimers(); health(); }, 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('online', refresh);
    window.addEventListener('pagehide', () => { clearInterval(polling); clearInterval(clock); echo?.disconnect(); }, { once: true });
}

function boot() { document.querySelectorAll('[data-live-overview]').forEach(initialize); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
else boot();
