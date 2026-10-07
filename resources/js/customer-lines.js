for (const form of document.querySelectorAll('[data-answer-form]')) {
    const sync = () => {
        const selected = form.querySelector('input[name="answerer"]:checked')?.value;
        for (const panel of form.querySelectorAll('[data-answer-fields]')) {
            const active = panel.dataset.answerFields === selected;
            panel.hidden = !active;
            for (const control of panel.querySelectorAll('input, select, textarea')) {
                control.disabled = !active;
            }
        }
    };
    form.addEventListener('change', sync);
    sync();
}

for (const guide of document.querySelectorAll('[data-phone-guide]')) {
    const device = guide.querySelector('[data-guide-device]');
    const extension = guide.querySelector('[data-guide-extension]');
    const labels = {
        zoiper: { host: 'Host / Domain', port: 'Port', username: 'Username', auth: 'Authentication username' },
        yealink: { host: 'SIP Server 1 → Server Host', port: 'SIP Server 1 → Port', username: 'User Name', auth: 'Register Name' },
        other: { host: 'Server / Registrar', port: 'Port', username: 'Username', auth: 'Auth ID' },
    };
    try {
        const saved = localStorage.getItem('blucom.phone-guide.device');
        if (Object.hasOwn(labels, saved)) device.value = saved;
    } catch { /* The guide also works when browser storage is disabled. */ }

    const sync = () => {
        const selected = extension.selectedOptions[0];
        for (const panel of guide.querySelectorAll('[data-guide-panel]')) {
            panel.hidden = panel.dataset.guidePanel !== device.value;
        }
        for (const [key, label] of Object.entries(labels[device.value])) {
            guide.querySelector(`[data-guide-label="${key}"]`).textContent = label;
            guide.querySelector(`[data-guide-copy="${key}"]`).setAttribute('aria-label', `کپی ${label}`);
        }
        for (const key of ['username', 'auth']) {
            const value = guide.querySelector(`[data-guide-value="${key}"]`);
            value.textContent = extension.value || 'ابتدا یک داخلی بسازید';
            value.dir = extension.value ? 'ltr' : 'rtl';
            guide.querySelector(`[data-guide-copy="${key}"]`).disabled = !extension.value;
        }
        const link = guide.querySelector('[data-guide-credentials]');
        link.hidden = !extension.value;
        link.href = selected?.dataset.phoneId ? `#phone-${selected.dataset.phoneId}` : '#phones';
    };
    device.addEventListener('change', () => {
        sync();
        try { localStorage.setItem('blucom.phone-guide.device', device.value); } catch { /* Optional preference. */ }
    });
    extension.addEventListener('change', sync);
    guide.querySelector('[data-guide-credentials]').addEventListener('click', () => {
        const details = document.getElementById(`phone-${extension.selectedOptions[0]?.dataset.phoneId}`);
        if (details) details.open = true;
    });
    document.querySelectorAll('[data-connect-phone]').forEach(link => link.addEventListener('click', () => {
        if ([...extension.options].some(option => option.value === link.dataset.connectPhone)) {
            extension.value = link.dataset.connectPhone;
            sync();
        }
    }));
    sync();
}

document.querySelectorAll('a[href^="#phone-"]').forEach(link => link.addEventListener('click', () => {
    const details = document.getElementById(link.getAttribute('href').slice(1));
    if (details) details.open = true;
}));

for (const button of document.querySelectorAll('[data-toggle-password]')) {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword);
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'پنهان‌کردن رمز' : 'نمایش رمز';
        button.setAttribute('aria-pressed', String(visible));
    });
}

for (const button of document.querySelectorAll('[data-copy-value], [data-copy-input], [data-guide-copy]')) {
    button.addEventListener('click', async () => {
        const status = document.querySelector('[data-copy-status]');
        const originalLabel = button.textContent;
        const value = button.dataset.copyValue
            ?? (button.dataset.copyInput ? document.getElementById(button.dataset.copyInput)?.value : null)
            ?? button.closest('[data-phone-guide]')?.querySelector(`[data-guide-value="${button.dataset.guideCopy}"]`)?.textContent;
        if (!value) return;
        try {
            await navigator.clipboard.writeText(value);
            button.textContent = 'کپی شد';
            if (status) status.textContent = 'کپی شد.';
        } catch {
            button.textContent = 'کپی نشد';
            if (status) status.textContent = 'کپی خودکار در این مرورگر در دسترس نیست؛ مقدار را انتخاب و دستی کپی کنید.';
        }
        setTimeout(() => { button.textContent = originalLabel; }, 2000);
    });
}
