import { Inviter, Registerer, SessionState, UserAgent } from 'sip.js';

let sipPassword = null;
let userAgent = null;
let registerer = null;
let activeSession = null;
let connection = null;
let connected = false;
let connecting = false;
let connectionPromise = null;
let muted = false;
let callStartedAt = null;
let callTimer = null;

const byId = (id) => document.getElementById(id);

async function jsonRequest(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', ...(options.headers || {}) },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || 'ارتباط با سرور انجام نشد.');
    return body;
}

function showLoginError(message) {
    const error = byId('phone-login-error');
    if (!error) return;
    error.textContent = message;
    error.hidden = false;
}

async function showDashboard(password) {
    const response = await fetch('/dashboard', { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) throw new Error('ورود انجام شد اما داشبورد بارگذاری نشد. صفحه را تازه کنید.');
    const html = await response.text();
    const next = new DOMParser().parseFromString(html, 'text/html');
    document.title = next.title;
    const nextCsrf = next.querySelector('meta[name="csrf-token"]')?.content;
    if (nextCsrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', nextCsrf);
    document.body.innerHTML = next.body.innerHTML;
    history.pushState({}, '', '/dashboard');
    sipPassword = password;
    mountDashboard();
}

const loginForm = byId('phone-login-form');
loginForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.dataset.label = button.textContent;
    button.textContent = 'در حال ورود…';
    const formData = new FormData(loginForm);
    const password = String(formData.get('password') || '');
    try {
        await jsonRequest(loginForm.action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': formData.get('_token') },
            body: formData,
        });
        await showDashboard(password);
    } catch (error) {
        showLoginError(error.message || 'ورود انجام نشد. دوباره تلاش کنید.');
        button.disabled = false;
        button.textContent = button.dataset.label || 'ورود به تلفن';
    }
});

function status(label, tone = 'muted') {
    const node = byId('phone-connection-status');
    if (!node) return;
    const styles = {
        muted: 'bg-slate-100 text-slate-600',
        wait: 'bg-amber-50 text-amber-800',
        good: 'bg-emerald-50 text-emerald-700',
        bad: 'bg-red-50 text-red-700',
    };
    node.className = `inline-flex items-center gap-2 rounded-full px-3 py-2 text-[10px] font-bold ${styles[tone] || styles.muted}`;
    node.innerHTML = `<span class="size-1.5 rounded-full ${tone === 'good' ? 'bg-emerald-500' : tone === 'bad' ? 'bg-red-500' : 'bg-amber-500'}"></span>`;
    node.append(document.createTextNode(label));
}

function toast(message) {
    const node = byId('phone-toast');
    if (!node) return;
    node.textContent = message;
    node.classList.add('show');
    clearTimeout(node._timer);
    node._timer = setTimeout(() => node.classList.remove('show'), 3200);
}

function setCallState(label, tone = 'muted') {
    const node = byId('active-call-status');
    if (node) node.textContent = label;
    const hangup = byId('hangup-call');
    if (hangup) hangup.disabled = !activeSession;
    const mute = byId('mute-call');
    if (mute) mute.disabled = !activeSession;
    if (tone === 'good') status('در حال مکالمه', 'good');
}

function remoteAudio(session) {
    const audio = byId('remote-audio');
    const peer = session.sessionDescriptionHandler?.peerConnection;
    if (!audio || !peer) return;
    const stream = new MediaStream();
    peer.getReceivers().forEach((receiver) => {
        if (receiver.track?.kind === 'audio') stream.addTrack(receiver.track);
    });
    audio.srcObject = stream;
    audio.play().catch(() => toast('برای شنیدن تماس، یک بار روی صفحه بزنید.'));
}

function watchSession(session) {
    activeSession = session;
    session.stateChange.addListener((state) => {
        if (state === SessionState.Establishing) {
            setCallState(session instanceof Inviter ? 'در حال تماس…' : 'در حال اتصال…', 'wait');
        }
        if (state === SessionState.Established) {
            setCallState('مکالمه برقرار است', 'good');
            callStartedAt = Date.now();
            remoteAudio(session);
            clearInterval(callTimer);
            callTimer = setInterval(() => {
                const seconds = Math.floor((Date.now() - callStartedAt) / 1000);
                const time = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
                const timer = byId('call-duration');
                if (timer) timer.textContent = time;
            }, 1000);
        }
        if (state === SessionState.Terminated) {
            activeSession = null;
            callStartedAt = null;
            clearInterval(callTimer);
            const audio = byId('remote-audio');
            if (audio) audio.srcObject = null;
            setCallState('آمادهٔ تماس');
            status('متصل به تلفن', connected ? 'good' : 'muted');
            byId('incoming-call')?.classList.add('hidden');
        }
    });
}

function connectPhone(password) {
    if (userAgent) return Promise.resolve(connected);
    if (connecting) return connectionPromise;
    if (!password) {
        byId('connect-password-dialog')?.classList.remove('hidden');
        byId('reconnect-password')?.focus();
        return Promise.resolve(false);
    }
    connecting = true;
    connectionPromise = (async () => {
        status('در حال اتصال…', 'wait');
        try {
            connection = await jsonRequest('/connection');
            userAgent = new UserAgent({
            uri: UserAgent.makeURI(connection.uri),
            authorizationUsername: connection.authorizationUsername,
            authorizationPassword: password,
            transportOptions: { server: connection.webSocketServer },
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: { iceServers: connection.iceServers },
            },
            delegate: {
                onConnect: () => status('در حال ثبت داخلی…', 'wait'),
                onDisconnect: (error) => {
                    connected = false;
                    status(error ? 'ارتباط قطع شد' : 'قطع از تلفن', error ? 'bad' : 'muted');
                    userAgent = null;
                    registerer = null;
                },
                onInvite: (invitation) => {
                    watchSession(invitation);
                    const caller = invitation.remoteIdentity?.displayName || invitation.remoteIdentity?.uri?.user || 'تماس ورودی';
                    byId('incoming-caller').textContent = caller;
                    byId('incoming-call')?.classList.remove('hidden');
                    setCallState('تماس ورودی');
                },
            },
            });
            await userAgent.start();
            registerer = new Registerer(userAgent);
            await registerer.register();
            connected = true;
            sipPassword = password;
            byId('connect-password-dialog')?.classList.add('hidden');
            status('متصل به تلفن', 'good');
            toast('داخلی شما آمادهٔ تماس است. برای دریافت تماس، این صفحه را باز نگه دارید.');
            return true;
        } catch (error) {
            connected = false;
            const failedAgent = userAgent;
            userAgent = null;
            registerer = null;
            try { await failedAgent?.stop(); } catch { /* Allow a fresh connection attempt. */ }
            status('اتصال برقرار نشد', 'bad');
            toast(error.message || 'اتصال تلفن ناموفق بود. رمز داخلی و شبکه را بررسی کنید.');
            return false;
        } finally {
            connecting = false;
        }
    })();

    return connectionPromise;
}

async function dial() {
    const number = byId('dial-input')?.value.trim();
    if (!number) return toast('شمارهٔ مقصد را وارد کنید.');
    if (connecting) await connectionPromise;
    else if (!connected && !userAgent && sipPassword) await connectPhone(sipPassword);
    else if (!connected && !userAgent) {
        await connectPhone(null);
        return;
    }
    if (!connected || !userAgent) return toast('تلفن وصل نشد. وضعیت اتصال را بالای صفحه بررسی کنید.');
    if (activeSession) return toast('ابتدا تماس فعلی را پایان دهید.');
    const normalized = number.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
        .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace(/[\s().-]/g, '');
    if (!/^\+?[0-9*#]{1,32}$/.test(normalized)) return toast('فقط شماره یا داخلی معتبر وارد کنید.');
    const domain = connection.uri.split('@').at(-1);
    const target = UserAgent.makeURI(`sip:${normalized}@${domain}`);
    if (!target) return toast('شمارهٔ مقصد معتبر نیست.');
    try {
        const inviter = new Inviter(userAgent, target, { sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } } });
        watchSession(inviter);
        setCallState('در حال شماره‌گیری…', 'wait');
        await inviter.invite();
    } catch (error) {
        activeSession = null;
        setCallState('آمادهٔ تماس');
        toast(error.message || 'تماس برقرار نشد.');
    }
}

async function hangup() {
    if (!activeSession) return;
    try {
        if (activeSession.state === SessionState.Established) await activeSession.bye();
        else if (activeSession instanceof Inviter) await activeSession.cancel();
        else await activeSession.reject();
    } catch { /* Session termination events update the interface when possible. */ }
}

function mountDashboard() {
    if (!byId('dial-input')) return;
    const localizedDate = byId('localized-date');
    if (localizedDate) localizedDate.textContent = new Intl.DateTimeFormat('fa-IR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
    document.querySelectorAll('[data-digit]').forEach((button) => button.addEventListener('click', () => {
        const digit = button.dataset.digit;
        if (activeSession?.state === SessionState.Established) {
            const dtmf = activeSession.sessionDescriptionHandler?.peerConnection?.getSenders().find((sender) => sender.track?.kind === 'audio')?.dtmf;
            if (dtmf?.canInsertDTMF) dtmf.insertDTMF(digit);
            else toast('ارسال کلیدهای تلفن در این تماس پشتیبانی نمی‌شود.');
            return;
        }
        byId('dial-input').value += digit;
        byId('dial-input').focus();
    }));
    byId('dial-delete')?.addEventListener('click', () => { const input = byId('dial-input'); input.value = input.value.slice(0, -1); input.focus(); });
    byId('dial-clear')?.addEventListener('click', () => { byId('dial-input').value = ''; byId('dial-input').focus(); });
    byId('dial-call')?.addEventListener('click', dial);
    byId('dial-input')?.addEventListener('keydown', (event) => { if (event.key === 'Enter') dial(); });
    byId('hangup-call')?.addEventListener('click', hangup);
    byId('mute-call')?.addEventListener('click', () => {
        const peer = activeSession?.sessionDescriptionHandler?.peerConnection;
        const track = peer?.getSenders().find((sender) => sender.track?.kind === 'audio')?.track;
        if (!track) return;
        muted = !muted;
        track.enabled = !muted;
        byId('mute-call').textContent = muted ? 'فعال‌کردن میکروفون' : 'بی‌صدا';
    });
    byId('answer-call')?.addEventListener('click', async () => {
        if (!activeSession || activeSession.state !== SessionState.Initial) return;
        try {
            await activeSession.accept({ sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } } });
            byId('incoming-call')?.classList.add('hidden');
        } catch (error) { toast(error.message || 'پاسخ به تماس انجام نشد.'); }
    });
    byId('decline-call')?.addEventListener('click', async () => {
        if (activeSession?.state === SessionState.Initial) await activeSession.reject();
    });
    document.querySelectorAll('[data-call-number]').forEach((button) => button.addEventListener('click', () => {
        byId('dial-input').value = button.dataset.callNumber.replace(/[^0-9+]/g, '');
        byId('dialer').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));
    document.querySelectorAll('a[href^="#"]').forEach((link) => link.addEventListener('click', () => {
        document.querySelectorAll('.phone-nav-link').forEach((item) => item.classList.toggle('active', item.getAttribute('href') === link.getAttribute('href')));
    }));
    byId('audio-check')?.addEventListener('click', async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            stream.getTracks().forEach((track) => track.stop());
            toast('میکروفون آماده است.');
        } catch { toast('دسترسی میکروفون مسدود است؛ مجوز مرورگر را فعال کنید.'); }
    });
    byId('connect-phone')?.addEventListener('click', () => connectPhone(sipPassword));
    byId('close-connect-dialog')?.addEventListener('click', () => byId('connect-password-dialog')?.classList.add('hidden'));
    byId('reconnect-phone')?.addEventListener('click', async () => {
        const password = byId('reconnect-password')?.value || '';
        if (!password) return toast('رمز داخلی را وارد کنید.');
        await connectPhone(password);
        byId('reconnect-password').value = '';
    });
    document.querySelectorAll('form[action$="/logout"]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        sipPassword = null;
        try { if (registerer) await registerer.unregister(); } catch { /* Continue logout. */ }
        try { if (userAgent) await userAgent.stop(); } catch { /* Continue logout. */ }
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        window.location.assign(response.url || '/login');
    }));
    status('برای تماس وصل شوید');
    if (sipPassword) connectPhone(sipPassword);
}

if (byId('dial-input')) mountDashboard();
