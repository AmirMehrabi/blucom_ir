function initializeRecordingPlayer() {
    const dialog = document.querySelector('[data-recording-player]');
    if (!dialog) return;
    const audio = dialog.querySelector('[data-player-audio]');
    const error = dialog.querySelector('[data-player-error]');
    function open(data, autoplay = false) {
        audio.pause();
        error.hidden = true;
        for (const field of ['title', 'participants', 'number', 'agent', 'expiry']) {
            dialog.querySelector(`[data-player-${field}]`).textContent = data[field] || '—';
        }
        const download = dialog.querySelector('[data-player-download]');
        download.hidden = !data.download;
        download.href = data.download || '#';
        const deletion = dialog.querySelector('[data-player-delete]');
        deletion.hidden = !data.delete;
        deletion.action = data.delete || '';
        audio.src = data.audio;
        dialog.querySelector('[data-player-speed]').value = '1';
        audio.playbackRate = 1;
        dialog.showModal();
        if (autoplay) audio.play().catch(() => {}); // Native play remains available.
    }
    document.querySelectorAll('[data-recording-open]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            open(JSON.parse(link.dataset.player), true);
        });
    });
    dialog.querySelector('[data-player-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { audio.pause(); audio.removeAttribute('src'); audio.load(); });
    dialog.addEventListener('click', event => { if (event.target === dialog) { const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close(); } });
    dialog.querySelector('[data-player-speed]').addEventListener('change', event => { audio.playbackRate = Number(event.target.value); });
    dialog.querySelectorAll('[data-player-seek]').forEach(button => button.addEventListener('click', () => {
        if (Number.isFinite(audio.duration)) audio.currentTime = Math.min(audio.duration, Math.max(0, audio.currentTime + Number(button.dataset.playerSeek)));
    }));
    audio.addEventListener('error', () => { error.hidden = false; });
    if (dialog.dataset.initial) open(JSON.parse(dialog.dataset.initial));
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeRecordingPlayer, {once: true});
else initializeRecordingPlayer();
