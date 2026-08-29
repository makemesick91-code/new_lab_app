import './bootstrap';

import Alpine from 'alpinejs';
import { createPatientCombobox } from './patient-combobox';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.store('sidebar', {
        isExpanded: true,
        isMobileOpen: false,

        // Alpine auto-calls store init(). Restore the desktop hide/show preference
        // from localStorage (manual convention; no $persist plugin). Default expanded.
        init() {
            let stored = null;
            try {
                stored = localStorage.getItem('adlms-sidebar-expanded');
            } catch {
                stored = null;
            }
            this.isExpanded = stored === null ? true : stored === 'true';
        },

        toggleExpanded() {
            this.isExpanded = ! this.isExpanded;
            this.isMobileOpen = false;
            this.persistExpanded();
        },

        toggleMobileOpen() {
            this.isMobileOpen = ! this.isMobileOpen;
        },

        setMobileOpen(value) {
            this.isMobileOpen = value;
        },

        persistExpanded() {
            try {
                localStorage.setItem('adlms-sidebar-expanded', this.isExpanded ? 'true' : 'false');
            } catch {
                // Storage unavailable (private mode); ignore — toggle still works in-session.
            }
        },
    });
});

Alpine.data('odontogramEditor', (config = {}) => ({
    activeStatus: 'caries',
    canEdit: config.canEdit ?? false,
    teeth: config.teeth ?? {},
    statusLabels: config.statusLabels ?? {},
    // Full FDI tooth set — drives the table-first tooth picker (Sprint 59).
    allTeeth: config.allTeeth ?? [],
    newTooth: '',
    newStatus: 'caries',
    selectedTooth: null,
    toothNote: '',

    /**
     * Teeth available to add as a new table row = the full FDI set minus those
     * already carrying a status. Keeps the picker from creating duplicate rows.
     */
    get availableTeeth() {
        return this.allTeeth.filter((t) => ! (this.teeth[String(t)] && this.teeth[String(t)].status));
    },

    /**
     * Table-first add: create a new selected row from the tooth/status picker.
     * The FDI grid visual re-colours automatically because it reads `teeth`.
     */
    addRow() {
        if (! this.canEdit) {
            return;
        }
        const key = String(this.newTooth || '').trim();
        if (key === '' || ! this.allTeeth.map(String).includes(key)) {
            return;
        }
        if (this.teeth[key] && this.teeth[key].status) {
            return;
        }
        this.teeth = Object.assign({}, this.teeth, {
            [key]: {
                status: this.newStatus || 'caries',
                note: (this.teeth[key] && this.teeth[key].note) || '',
                conditions: (this.teeth[key] && Array.isArray(this.teeth[key].conditions)) ? this.teeth[key].conditions : [],
                additional_condition: (this.teeth[key] && this.teeth[key].additional_condition) || '',
                additional_note: (this.teeth[key] && this.teeth[key].additional_note) || '',
                dokter: (this.teeth[key] && this.teeth[key].dokter) || '',
            },
        });
        this.newTooth = '';
    },

    /** Change a row's odontogram status from the per-row table dropdown. */
    setStatus(toothKey, status) {
        const key = String(toothKey);
        if (! this.canEdit || ! this.teeth[key] || ! status) {
            return;
        }
        this.teeth = Object.assign({}, this.teeth, {
            [key]: Object.assign({}, this.teeth[key], { status }),
        });
    },

    /** Remove a selected row entirely (clears the tooth from the visual too). */
    removeRow(toothKey) {
        if (! this.canEdit) {
            return;
        }
        const key = String(toothKey);
        const copy = Object.assign({}, this.teeth);
        delete copy[key];
        this.teeth = copy;
        if (this.selectedTooth !== null && String(this.selectedTooth) === key) {
            this.selectedTooth = null;
            this.toothNote = '';
        }
    },

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
                dokter: this.teeth[k].dokter || '',
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

    // ── Table-only input (Sprint 60.2) ─────────────────────────────────────
    // Each FDI tooth renders as a fixed table row. `pickStatus` creates or
    // clears a tooth entry from the dropdown; `rowStatus`/`rowField` read the
    // current value so the inputs stay in sync. The FDI image is generated
    // server-side from saved data and is never edited here.

    /** Current saved status for a tooth ('' = unselected). */
    rowStatus(toothKey) {
        const e = this.teeth[String(toothKey)];
        return (e && e.status) ? e.status : '';
    },

    /** Read an arbitrary per-tooth field ('' when the tooth has no entry). */
    rowField(toothKey, field) {
        const e = this.teeth[String(toothKey)];
        return (e && e[field]) ? e[field] : '';
    },

    /** Set/clear a tooth's odontogram status from its table-row dropdown. */
    pickStatus(toothKey, value) {
        if (! this.canEdit) {
            return;
        }
        const key = String(toothKey);
        if (! value) {
            const copy = Object.assign({}, this.teeth);
            delete copy[key];
            this.teeth = copy;
            return;
        }
        const existing = this.teeth[key] || {};
        this.teeth = Object.assign({}, this.teeth, {
            [key]: {
                status: value,
                note: existing.note || '',
                conditions: Array.isArray(existing.conditions) ? existing.conditions : [],
                additional_condition: existing.additional_condition || '',
                additional_note: existing.additional_note || '',
                dokter: existing.dokter || '',
            },
        });
    },

    getPayload() {
        // Only persist teeth that carry a status; empty rows are excluded so the
        // payload never contains invalid empty-status entries.
        const out = {};
        Object.keys(this.teeth).forEach((k) => {
            if (this.teeth[k] && this.teeth[k].status) {
                out[k] = this.teeth[k];
            }
        });
        return JSON.stringify({ teeth: out });
    },
}));

Alpine.data('searchableProductSelect', (config = {}) => ({
    products: Array.isArray(config.products) ? config.products : [],
    placeholder: config.placeholder ?? 'Cari kode atau nama produk…',
    emptyLabel: config.emptyLabel ?? 'Pilih produk',
    allowEmpty: config.allowEmpty ?? true,
    required: !!config.required,
    disabled: !!config.disabled,
    multiple: !!config.multiple,
    maxResults: config.maxResults ?? 20,
    query: '',
    open: false,
    activeIndex: 0,
    selectedId: config.multiple ? '' : String(config.selected ?? ''),
    selectedIds: config.multiple
        ? (Array.isArray(config.selected) ? config.selected.map(String) : [])
        : [],

    init() {
        if (!this.multiple && this.selectedId) {
            const product = this.products.find((entry) => String(entry.id) === this.selectedId);
            if (product) {
                this.query = product.label;
            }
        }
    },

    filtered() {
        const term = this.query.trim().toLowerCase();
        let list = this.products;

        if (term !== '') {
            list = list.filter((product) => {
                const code = String(product.code ?? '').toLowerCase();
                const name = String(product.name ?? '').toLowerCase();
                const label = String(product.label ?? '').toLowerCase();

                return code.includes(term) || name.includes(term) || label.includes(term);
            });
        }

        if (this.multiple) {
            list = list.filter((product) => !this.selectedIds.includes(String(product.id)));
        }

        return list.slice(0, this.maxResults);
    },

    openDropdown() {
        if (this.disabled) {
            return;
        }

        this.open = true;
        this.activeIndex = 0;

        if (!this.multiple && this.selectedId) {
            this.query = '';
        }
    },

    closeDropdown() {
        this.open = false;
        this.activeIndex = 0;

        if (!this.multiple) {
            const product = this.products.find((entry) => String(entry.id) === this.selectedId);
            this.query = product?.label ?? '';
        }
    },

    select(product) {
        if (this.multiple) {
            const id = String(product.id);

            if (!this.selectedIds.includes(id)) {
                this.selectedIds.push(id);
            }

            this.query = '';
            this.activeIndex = 0;

            return;
        }

        this.selectedId = String(product.id);
        this.query = product.label;
        this.open = false;
        this.activeIndex = 0;
        this.$dispatch('product-selected', { id: this.selectedId });
    },

    removeSelected(id) {
        this.selectedIds = this.selectedIds.filter((value) => value !== String(id));
    },

    clearSelection() {
        if (!this.allowEmpty || this.disabled) {
            return;
        }

        this.selectedId = '';
        this.query = '';
        this.open = false;
        this.$dispatch('product-selected', { id: '' });
    },

    onInput(event) {
        this.query = event.target.value;
        this.open = true;
        this.activeIndex = 0;

        if (!this.multiple && this.selectedId) {
            this.selectedId = '';
        }
    },

    onKeydown(event) {
        if (this.disabled) {
            return;
        }

        const options = this.filtered();

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.open = true;
            this.activeIndex = Math.min(this.activeIndex + 1, Math.max(options.length - 1, 0));

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.open = true;
            this.activeIndex = Math.max(this.activeIndex - 1, 0);

            return;
        }

        if (event.key === 'Enter') {
            if (!this.open || options.length === 0) {
                return;
            }

            event.preventDefault();
            this.select(options[this.activeIndex]);

            return;
        }

        if (event.key === 'Escape') {
            this.closeDropdown();
        }
    },

    selectedTags() {
        return this.selectedIds
            .map((id) => this.products.find((product) => String(product.id) === id))
            .filter(Boolean);
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
            'inventory-master-data': false,
            'inventory-reports-analytics': false,
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

// REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — the single searchable
// patient control on "Kunjungan Baru". The state machine lives in its own
// module so its stale-response and "typed text is not a selection" rules can
// be unit-tested outside a browser (tests/js/patient-combobox.test.mjs).
Alpine.data('patientCombobox', (config = {}) => createPatientCombobox(config));

Alpine.start();
