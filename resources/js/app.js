import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.store('sidebar', {
        isExpanded: window.innerWidth >= 1280,
        isMobileOpen: false,

        toggleExpanded() {
            this.isExpanded = ! this.isExpanded;
            this.isMobileOpen = false;
        },

        toggleMobileOpen() {
            this.isMobileOpen = ! this.isMobileOpen;
        },

        setMobileOpen(value) {
            this.isMobileOpen = value;
        },
    });
});

Alpine.data('odontogramEditor', (config = {}) => ({
    activeStatus: 'caries',
    canEdit: config.canEdit ?? false,
    teeth: config.teeth ?? {},
    statusLabels: config.statusLabels ?? {},
    selectedTooth: null,
    toothNote: '',

    clickTooth(num) {
        const key = String(num);
        if (this.selectedTooth !== num) {
            this.selectedTooth = num;
            this.toothNote = (this.teeth[key] && this.teeth[key].note) ? this.teeth[key].note : '';
        }
        if (! this.canEdit) {
            return;
        }
        const current = this.teeth[key] ? this.teeth[key].status : null;
        const existingNote = this.teeth[key] ? (this.teeth[key].note || '') : '';
        const existingConditions = (this.teeth[key] && Array.isArray(this.teeth[key].conditions)) ? this.teeth[key].conditions : [];
        // Preserve per-row additional fields when status is re-applied (Sprint 23 Phase 23.10.4).
        const existingAddCondition = this.teeth[key] ? (this.teeth[key].additional_condition || '') : '';
        const existingAddNote = this.teeth[key] ? (this.teeth[key].additional_note || '') : '';
        if (current === this.activeStatus) {
            const copy = Object.assign({}, this.teeth);
            delete copy[key];
            this.teeth = copy;
            this.toothNote = '';
        } else {
            this.teeth = Object.assign({}, this.teeth, {
                [key]: {
                    status: this.activeStatus,
                    note: existingNote,
                    conditions: existingConditions,
                    additional_condition: existingAddCondition,
                    additional_note: existingAddNote,
                },
            });
        }
    },

    /**
     * Selected odontogram results = teeth that have a status set. Each entry
     * carries its per-row additional condition/note so the results table can
     * render and edit them (Sprint 23 Phase 23.10.4).
     */
    get selectedRows() {
        return Object.keys(this.teeth)
            .filter((k) => this.teeth[k] && this.teeth[k].status)
            .sort((a, b) => Number(a) - Number(b))
            .map((k) => ({
                tooth: k,
                status: this.teeth[k].status,
                additional_condition: this.teeth[k].additional_condition || '',
                additional_note: this.teeth[k].additional_note || '',
            }));
    },

    statusLabel(status) {
        return this.statusLabels[status] || status || '—';
    },

    setAdditional(toothKey, field, value) {
        const key = String(toothKey);
        if (! this.canEdit || ! this.teeth[key]) {
            return;
        }
        this.teeth = Object.assign({}, this.teeth, {
            [key]: Object.assign({}, this.teeth[key], { [field]: value }),
        });
    },

    syncNote() {
        if (! this.canEdit || this.selectedTooth === null) {
            return;
        }
        const key = String(this.selectedTooth);
        if (! this.teeth[key]) {
            return;
        }
        this.teeth[key].note = this.toothNote;
    },

    hasCondition(condition) {
        const key = String(this.selectedTooth);
        if (! this.teeth[key]) {
            return false;
        }
        const conds = this.teeth[key].conditions;
        return Array.isArray(conds) && conds.includes(condition);
    },

    toggleCondition(condition) {
        if (! this.canEdit || this.selectedTooth === null) {
            return;
        }
        const key = String(this.selectedTooth);
        if (! this.teeth[key]) {
            return;
        }
        const current = Array.isArray(this.teeth[key].conditions) ? this.teeth[key].conditions : [];
        const idx = current.indexOf(condition);
        const updated = idx === -1 ? [...current, condition] : current.filter((_, i) => i !== idx);
        this.teeth[key] = Object.assign({}, this.teeth[key], { conditions: updated });
    },

    cellClass(num) {
        const s = this.teeth[String(num)] ? this.teeth[String(num)].status : null;
        const base = this.canEdit
            ? 'cursor-pointer hover:opacity-75 active:scale-95 '
            : 'cursor-pointer hover:opacity-60 ';
        if (s === 'caries') {
            return base + 'bg-red-200 text-red-900 ring-red-400';
        }
        if (s === 'missing') {
            return base + 'bg-gray-800 text-white ring-gray-600';
        }
        if (s === 'crown') {
            return base + 'bg-amber-200 text-amber-900 ring-amber-400';
        }
        if (s === 'root_treated') {
            return base + 'bg-sky-200 text-sky-900 ring-sky-400';
        }
        if (s === 'normal') {
            return base + 'bg-green-100 text-green-900 ring-green-400';
        }

        return base + 'bg-white text-gray-600 ring-gray-200';
    },

    getPayload() {
        return JSON.stringify({ teeth: this.teeth });
    },
}));

Alpine.data('adlmsSidebar', (routeOpen = {}) => ({
    open: {},

    init() {
        const storageKey = 'adlms-sidebar-groups';
        const defaults = {
            rme: false,
            lab: false,
            production: false,
            qc: false,
            settings: false,
            'master-data': false,
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
