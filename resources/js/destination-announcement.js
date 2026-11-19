document.querySelectorAll('[data-announcement-settings]').forEach(form => {
    const mode = form.elements.mode;
    const asset = form.elements.asset;
    const playback = form.querySelector('[data-announcement-playback]');
    const message = form.querySelector('[data-announcement-message]');
    const preview = form.querySelector('[data-announcement-preview]');

    function updatePreview() {
        const url = mode.value !== 'normal' && asset.selectedOptions[0]?.dataset.preview;
        if (preview.getAttribute('src') !== (url || null)) {
            preview.pause();
            if (url) {
                preview.setAttribute('src', url);
            } else {
                preview.removeAttribute('src');
                preview.load();
            }
        }
        preview.hidden = !url;
    }

    function updateMode() {
        playback.hidden = mode.value === 'normal';
        asset.required = !playback.hidden;
        if (message) {
            message.hidden = mode.value !== 'message';
            message.querySelectorAll('select').forEach(select => {
                select.required = !message.hidden;
            });
        }
        updatePreview();
    }

    mode.addEventListener('change', updateMode);
    asset.addEventListener('change', updatePreview);
    updateMode();
});
