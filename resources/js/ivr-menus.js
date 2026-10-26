for (const section of document.querySelectorAll('[data-ivr-direct-dial]')) {
    const toggle = section.querySelector('[data-ivr-direct-toggle]');
    const options = section.querySelector('[data-ivr-direct-options]');
    const sync = () => {
        options.hidden = !toggle.checked;
        toggle.setAttribute('aria-expanded', String(toggle.checked));
    };
    toggle.addEventListener('change', sync);
    sync();
}
