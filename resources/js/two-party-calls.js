function initializeTwoPartyCalls() {
    document.querySelectorAll('[data-two-party-page]').forEach((page) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
        async function request(url, options = {}) {
            const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { ...headers, ...options.headers } });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'درخواست انجام نشد.');
            return data;
        }
        function watch(card) {
            let stopped = false;
            const status = card.querySelector('[data-call-status]');
            const cancel = card.querySelector('[data-call-cancel]');
            cancel?.addEventListener('click', async () => {
                cancel.disabled = true;
                try {
                    await request(card.dataset.cancelUrl, { method: 'POST' });
                    status.textContent = 'درخواست لغو ثبت شد؛ در انتظار پایان تماس';
                } catch (error) { status.textContent = error.message; cancel.disabled = false; }
            });
            async function poll() {
                try {
                    const call = await request(card.dataset.statusUrl);
                    status.textContent = [call.status_label, call.cancel_requested && !call.terminal ? 'در انتظار لغو' : '', call.failure_reason].filter(Boolean).join(' · ');
                    if (cancel) { cancel.hidden = call.terminal; cancel.disabled = call.cancel_requested; }
                    const duration = card.querySelector('[data-call-duration]');
                    if (duration) duration.textContent = String(call.conversation_seconds);
                    const events = card.querySelector('[data-call-events]');
                    if (events) {
                        events.replaceChildren(...call.events.map((event) => {
                            const item = document.createElement('li');
                            item.className = 'text-sm';
                            item.textContent = `${event.status} · ${new Date(event.at).toLocaleTimeString('fa-IR', { timeZone: 'Asia/Tehran' })}`;
                            return item;
                        }));
                    }
                    const recordingStatus = card.querySelector('[data-recording-status]');
                    if (recordingStatus) recordingStatus.textContent = call.recording_status || 'ضبط خاموش است';
                    const player = card.querySelector('[data-recording-player]');
                    if (player && call.recording_audio) { player.hidden = false; if (!player.getAttribute('src')) player.src = call.recording_audio; }
                    const download = card.querySelector('[data-recording-download]');
                    if (download && call.recording_download) { download.hidden = false; download.href = call.recording_download; }
                    const processing = ['در حال ضبط', 'در حال آماده‌سازی'].includes(call.recording_status);
                    stopped = call.terminal && !processing;
                } catch (error) {
                    status.textContent = `به‌روزرسانی وضعیت انجام نشد: ${error.message}`;
                }
                if (!stopped) setTimeout(poll, document.hidden ? 15000 : 2500);
            }
            poll();
        }
        page.querySelectorAll('[data-call-card]').forEach(watch);
        const form = page.querySelector('[data-call-form]');
        if (!form) return;
        let idempotencyKey = crypto.randomUUID();
        form.addEventListener('input', () => { idempotencyKey = crypto.randomUUID(); });
        form.addEventListener('change', () => { idempotencyKey = crypto.randomUUID(); });
        const agent = form.elements.agent_id;
        const caller = form.elements.caller_id;
        agent.addEventListener('change', () => {
            caller.value = '';
            [...caller.options].forEach((option) => {
                if (!option.dataset.agent) return;
                option.hidden = option.disabled = option.dataset.agent !== agent.value;
                if (!option.disabled) caller.value = option.value;
            });
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            const errorBox = form.querySelector('[data-call-error]');
            button.disabled = true;
            errorBox.hidden = true;
            const data = new FormData(form);
            data.set('idempotency_key', idempotencyKey);
            try {
                const call = await request(form.action, { method: 'POST', body: data });
                idempotencyKey = crypto.randomUUID();
                const card = document.createElement('div');
                card.className = 'rounded-xl border border-blue-100 bg-blue-50 p-4';
                card.dataset.callCard = '';
                card.dataset.statusUrl = call.url;
                card.dataset.cancelUrl = call.cancel_url;
                const title = document.createElement('p');
                title.className = 'text-sm font-bold';
                title.textContent = `داخلی ${call.agent} ← ${call.customer_number}`;
                const status = document.createElement('p'); status.dataset.callStatus = ''; status.setAttribute('role', 'status'); status.className = 'mt-2 text-sm';
                const cancel = document.createElement('button'); cancel.type = 'button'; cancel.dataset.callCancel = ''; cancel.textContent = 'لغو تماس'; cancel.className = 'mt-3 min-h-11 text-sm font-bold text-red-700';
                card.append(title, status, cancel);
                page.querySelector('[data-active-calls]').prepend(card);
                page.querySelector('[data-empty-active]').hidden = true;
                watch(card);
            } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; }
            finally { button.disabled = false; }
        });
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeTwoPartyCalls, { once: true });
else initializeTwoPartyCalls();
