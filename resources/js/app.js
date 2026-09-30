import './live-overview';

function initializeHomeDemo() {
    document.querySelectorAll('[data-home-demo]').forEach((demo) => {
        const tabs = [...demo.querySelectorAll('[role="tab"]')];
        const panels = [...demo.querySelectorAll('[role="tabpanel"]')];

        function selectTab(tab) {
            const target = tab.dataset.demoTarget;

            tabs.forEach((item) => {
                const selected = item === tab;
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
            });

            panels.forEach((panel) => {
                panel.hidden = panel.dataset.demoPanel !== target;
            });
        }

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectTab(tab));
            tab.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

                event.preventDefault();
                const next = tabs[(index + (event.key === 'ArrowLeft' ? 1 : tabs.length - 1)) % tabs.length];
                selectTab(next);
                next.focus();
            });
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeHomeDemo, { once: true });
} else {
    initializeHomeDemo();
}
