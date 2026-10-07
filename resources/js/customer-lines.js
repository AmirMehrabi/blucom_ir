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
