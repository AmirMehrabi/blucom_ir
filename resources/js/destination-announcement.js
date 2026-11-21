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
            const usesKey = mode.value === 'message';
            const automatic = mode.value === 'automatic_message';
            message.hidden = !usesKey && !automatic;
            message.querySelector('[data-announcement-key]').hidden = !usesKey;
            message.querySelector('[data-announcement-key-help]').hidden = !usesKey;
            message.querySelector('[data-announcement-automatic-help]').hidden = !automatic;
            form.elements.digit.required = usesKey;
            if (form.elements.mailbox) {
                form.elements.mailbox.required = !message.hidden;
            }
        }
        updatePreview();
    }

    mode.addEventListener('change', updateMode);
    asset.addEventListener('change', updatePreview);
    updateMode();
});
