import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('adlmsSidebar', (routeOpen = {}) => ({
    open: {},

    init() {
        const storageKey = 'adlms-sidebar-groups';
        const defaults = {
            settings: false,
            'master-data': false,
            operational: false,
            'my-work': false,
            delivery: false,
            inventory: false,
            procurement: false,
            finance: false,
            reporting: false,
        };

        let stored = {};
        try {
            stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
        } catch {
            stored = {};
        }

        this.open = { ...defaults, ...stored };

        for (const [group, active] of Object.entries(routeOpen)) {
            if (active) {
                this.open[group] = true;
            }
        }

        this.persist();
    },

    toggle(group) {
        this.open[group] = ! this.open[group];
        this.persist();
    },

    isOpen(group) {
        return !! this.open[group];
    },

    persist() {
        localStorage.setItem('adlms-sidebar-groups', JSON.stringify(this.open));
    },
}));

Alpine.start();
