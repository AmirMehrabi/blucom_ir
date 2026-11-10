import { UserAgent, Registerer, RegistererState, SessionState, Web } from 'sip.js';

export function initializeMonitoring(root) {
    const dialog = root.querySelector('[data-monitor-dialog]');
    if (!dialog) return;
    const find = (name) => root.querySelector(`[data-monitor-${name}]`);
    let monitor = null, agent = null, registerer = null, invitation = null, timer = null, target = null, busy = false;
    const message = (text) => { find('status').textContent = text; };
    const base = '/live/monitor';
    async function request(path, body = {}) {
        const response = await fetch(base + path, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(body), signal: AbortSignal.timeout(10000) });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) throw new Error(data.message || 'ارتباط با سامانه شنود برقرار نشد.');
        return data;
    }
    function controls(active) {
        find('start').hidden = active;
        find('stop').hidden = !active;
        find('resume').hidden = !active;
        find('mode').disabled = active;
        find('supervisor').disabled = active;
    }
    async function cleanup() {
        clearInterval(timer); timer = null;
        const previous = agent;
        monitor = null;
        agent = registerer = invitation = null;
        if (previous) await previous.stop().catch(() => {});
        find('audio').srcObject = null;
        find('audio').hidden = true;
        monitor = null;
        controls(false);
    }
    async function stop() {
        if (busy) return;
        busy = true;
        try {
            if (monitor) await request(`/${monitor.id}/stop`);
            await cleanup();
            message('شنود پایان یافت.');
        } catch { message('درخواست پایان ارسال نشد؛ ارتباط صوتی قطع می‌شود و شنود حداکثر تا ۴۵ ثانیه پایان می‌یابد.'); await cleanup(); }
        finally { busy = false; }
    }
    async function heartbeat() {
        if (!monitor || busy) return;
        try {
            monitor = await request(`/${monitor.id}/heartbeat`);
            if (monitor.ended_at) { await cleanup(); message('تماس یا شنود پایان یافته است.'); return; }
            message(monitor.status === 'listening' ? 'در حال شنیدن · فقط دریافت صدا' : 'در انتظار پاسخ داخلی ناظر…');
        } catch { message('ارتباط با سامانه قطع شده؛ در صورت ادامه قطعی، شنود خودکار پایان می‌یابد.'); }
    }
    async function browser(connection) {
        agent = new UserAgent({ uri: UserAgent.makeURI(connection.uri), authorizationUsername: connection.username,
            authorizationPassword: connection.password, logLevel: 'error', logConfiguration: false,
            transportOptions: { server: connection.websocket },
            sessionDescriptionHandlerFactory: Web.defaultSessionDescriptionHandlerFactory(() => Promise.resolve(new MediaStream())),
            sessionDescriptionHandlerFactoryOptions: { peerConnectionConfiguration: { iceServers: connection.iceServers } },
            delegate: { onInvite: (incoming) => {
                if (!monitor || invitation || incoming.remoteIdentity.uri.user !== monitor.id) { incoming.reject(); return; }
                invitation = incoming;
                incoming.stateChange.addListener(async (state) => {
                    if (state === SessionState.Established) {
                        const stream = new MediaStream();
                        incoming.sessionDescriptionHandler.peerConnection.getReceivers().forEach(({ track }) => { if (track?.kind === 'audio') stream.addTrack(track); });
                        find('audio').srcObject = stream; find('audio').hidden = false;
                        find('audio').play().catch(() => message('برای پخش صدا، دکمه پخش را بزنید.'));
                    }
                    if (state === SessionState.Terminated) { await stop(); }
                });
                // SIP.js negotiates receive-only audio without capturing a microphone.
                incoming.accept({ sessionDescriptionHandlerOptions: { constraints: { audio: false, video: false },
                    offerOptions: { offerToReceiveAudio: true } },
                    sessionDescriptionHandlerModifiers: [async (description) => description.type === 'answer'
                        ? { ...description, sdp: description.sdp.replace(/a=sendrecv/g, 'a=recvonly') } : description],
                }).catch(() => stop());
            } },
        });
        await agent.start();
        registerer = new Registerer(agent, { expires: 120 });
        await new Promise((resolve, reject) => {
            const deadline = setTimeout(() => reject(new Error('ثبت ارتباط مرورگر انجام نشد.')), 15000);
            registerer.stateChange.addListener((state) => {
                if (state === RegistererState.Registered) { clearTimeout(deadline); resolve(); }
                if (state === RegistererState.Terminated) { clearTimeout(deadline); reject(new Error('ارتباط مرورگر رد شد.')); }
            });
            registerer.register().catch((error) => { clearTimeout(deadline); reject(error); });
        });
    }
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-monitor-call]');
        if (!button) return;
        if (!monitor) {
            target = { call_id: button.dataset.monitorCall, extension_id: Number(button.dataset.monitorExtension) };
            [...find('supervisor').options].forEach((option) => { option.disabled = Number(option.value) === target.extension_id; });
            const option = [...find('supervisor').options].find((item) => !item.disabled);
            find('supervisor').value = option?.value || '';
            message('روش شنیدن را انتخاب کنید.');
        }
        dialog.showModal();
    });
    find('mode').addEventListener('change', () => { find('supervisor-label').hidden = find('mode').value !== 'extension'; });
    find('form').addEventListener('submit', async (event) => {
        event.preventDefault(); if (busy || monitor || !target) return;
        busy = true; find('start').disabled = true;
        try {
            // Prime audio playback during a user gesture for browsers with autoplay limits.
            find('audio').play().catch(() => {});
            message('در حال اتصال…');
            monitor = await request('', { ...target, organization: JSON.parse(root.querySelector('[data-live-initial]').textContent).tenant.id,
                mode: find('mode').value, supervisor_extension_id: Number(find('supervisor').value) || null });
            controls(true);
            if (monitor.connection) { const connection = monitor.connection; delete monitor.connection; await browser(connection); connection.password = ''; }
            monitor = await request(`/${monitor.id}/connect`);
            timer = setInterval(heartbeat, 5000);
            message(monitor.mode === 'web' ? 'در حال اتصال صدا…' : 'داخلی ناظر زنگ می‌خورد؛ تماس را پاسخ دهید.');
        } catch (error) {
            const id = monitor?.id;
            await cleanup();
            if (id) await request(`/${id}/stop`).catch(() => {});
            message(error.message || 'شروع شنود انجام نشد.');
        } finally { busy = false; find('start').disabled = false; }
    });
    find('stop').addEventListener('click', stop);
    find('close').addEventListener('click', () => dialog.close());
    find('resume').addEventListener('click', () => dialog.showModal());
    window.addEventListener('pagehide', () => {
        clearInterval(timer);
        if (monitor) fetch(`${base}/${monitor.id}/stop`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' } }).catch(() => {});
        agent?.stop().catch(() => {});
    });
    // A reload can recover the stop control without returning browser credentials again.
    fetch(base + '/current', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
        .then((response) => response.ok ? response.json() : null).then((data) => {
            if (data?.session && !monitor) { monitor = data.session; controls(true); message('یک شنود قبلی باز است. برای شروع دوباره، آن را پایان دهید.'); dialog.showModal(); }
        }).catch(() => {});
}
