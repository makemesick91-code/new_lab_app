/**
 * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — the single searchable patient
 * control used by "Kunjungan Baru".
 *
 * Deliberately a plain, dependency-free factory rather than an inline
 * `Alpine.data()` body: the state machine below owns the two behaviours that are
 * easy to get wrong and impossible to eyeball (stale-response ordering and
 * "typed text is not a selection"), so it has to be unit-testable outside a
 * browser. `resources/js/app.js` registers it with Alpine; `tests/js/` drives
 * the very same function with injected fetch/AbortController doubles.
 *
 * Invariants this file is responsible for:
 *  - `patient_id` is set ONLY by an explicit selection of a returned result.
 *    Typing clears it, so the hidden field can never disagree with what the
 *    operator can see.
 *  - A slower earlier request can never overwrite a newer query's results.
 *  - Nothing is searched before `minLength`, so an empty box never asks the
 *    server for a patient list.
 */

export const PATIENT_COMBOBOX_DEFAULTS = {
    minLength: 2,
    debounceMs: 300,
};

/**
 * @param {object} config
 * @param {string} config.endpoint            Authorized server search endpoint.
 * @param {number} [config.minLength]         Characters required before searching.
 * @param {number} [config.debounceMs]        Keystroke settle time.
 * @param {?object} [config.selected]         `{ id, label }` prefill, already authorized server-side.
 * @param {function} [config.fetchImpl]       Injectable fetch (tests).
 * @param {function} [config.abortControllerImpl] Injectable AbortController (tests).
 * @param {function} [config.setTimeoutImpl]  Injectable timer (tests).
 * @param {function} [config.clearTimeoutImpl]
 */
export function createPatientCombobox(config = {}) {
    const fetchImpl = config.fetchImpl ?? ((...args) => globalThis.fetch(...args));
    const AbortControllerImpl = config.abortControllerImpl ?? globalThis.AbortController;
    const setTimeoutImpl = config.setTimeoutImpl ?? ((fn, ms) => globalThis.setTimeout(fn, ms));
    const clearTimeoutImpl = config.clearTimeoutImpl ?? ((id) => globalThis.clearTimeout(id));

    return {
        endpoint: String(config.endpoint ?? ''),
        minLength: Number(config.minLength ?? PATIENT_COMBOBOX_DEFAULTS.minLength),
        debounceMs: Number(config.debounceMs ?? PATIENT_COMBOBOX_DEFAULTS.debounceMs),

        query: '',
        open: false,
        loading: false,
        errored: false,
        tooShort: false,
        searched: false,
        results: [],
        activeIndex: -1,

        selectedId: '',
        selectedLabel: '',

        // Monotonic request counter. Every state-changing entry point bumps it,
        // which is what makes an in-flight response provably stale.
        _seq: 0,
        _controller: null,
        _timer: null,

        init() {
            const selected = config.selected ?? null;

            if (selected && selected.id) {
                this.selectedId = String(selected.id);
                this.selectedLabel = String(selected.label ?? '');
                this.query = this.selectedLabel;
            }
        },

        get hasSelection() {
            return this.selectedId !== '';
        },

        /**
         * The dropdown only ever offers what the server returned for the query
         * currently in the box.
         */
        get showResults() {
            return this.open && this.results.length > 0;
        },

        get showEmptyState() {
            return this.open && this.searched && !this.loading && !this.errored && this.results.length === 0;
        },

        get showTooShort() {
            return this.open && this.tooShort && !this.loading;
        },

        resultLabel(result) {
            const rm = String(result?.medical_record_number ?? '').trim();
            const name = String(result?.name ?? '').trim();

            return rm === '' ? name : `${name} — ${rm}`;
        },

        onFocus() {
            this.open = true;

            // Re-opening on a settled selection must not silently re-run a
            // search; the operator sees the selection they already made.
            if (!this.hasSelection && this.query.trim().length >= this.minLength && !this.searched) {
                this.scheduleSearch();
            }
        },

        /**
         * Typed text is NOT a patient. Any keystroke invalidates a previous
         * selection so `patient_id` can never be submitted while the visible
         * text refers to a different search.
         */
        onInput(value) {
            this.query = String(value ?? '');

            if (this.hasSelection) {
                this.selectedId = '';
                this.selectedLabel = '';
                this._emitChange();
            }

            this.open = true;
            this.scheduleSearch();
        },

        scheduleSearch() {
            if (this._timer !== null) {
                clearTimeoutImpl(this._timer);
                this._timer = null;
            }

            const term = this.query.trim();

            // Below the threshold nothing is queued and any in-flight answer is
            // invalidated, so clearing the box never leaves a stale list behind
            // and never asks the server for "everything".
            if (term.length < this.minLength) {
                this._invalidateInFlight();
                this.results = [];
                this.searched = false;
                this.loading = false;
                this.errored = false;
                this.activeIndex = -1;
                this.tooShort = term.length > 0;

                return;
            }

            this.tooShort = false;
            this._timer = setTimeoutImpl(() => {
                this._timer = null;
                this.runSearch();
            }, this.debounceMs);
        },

        async runSearch() {
            const term = this.query.trim();

            if (term.length < this.minLength) {
                return;
            }

            const seq = this._invalidateInFlight();
            const controller = AbortControllerImpl ? new AbortControllerImpl() : null;
            this._controller = controller;

            this.loading = true;
            this.errored = false;

            try {
                const url = `${this.endpoint}?q=${encodeURIComponent(term)}`;
                const response = await fetchImpl(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller ? controller.signal : undefined,
                });

                // An answer that is no longer the newest request is dropped
                // before it can touch any rendered state.
                if (seq !== this._seq) {
                    return;
                }

                if (!response || response.ok === false) {
                    throw new Error('patient search request failed');
                }

                const data = await response.json();

                if (seq !== this._seq) {
                    return;
                }

                this.results = Array.isArray(data?.results) ? data.results : [];
                this.searched = true;
                this.activeIndex = this.results.length > 0 ? 0 : -1;
            } catch (error) {
                if (seq !== this._seq || (error && error.name === 'AbortError')) {
                    return;
                }

                this.errored = true;
                this.results = [];
                this.searched = false;
                this.activeIndex = -1;
            } finally {
                if (seq === this._seq) {
                    this.loading = false;
                    this._controller = null;
                }
            }
        },

        select(result) {
            if (!result || result.id === undefined || result.id === null) {
                return;
            }

            this._invalidateInFlight();

            this.selectedId = String(result.id);
            this.selectedLabel = this.resultLabel(result);
            this.query = this.selectedLabel;
            this.results = [];
            this.searched = false;
            this.tooShort = false;
            this.errored = false;
            this.loading = false;
            this.activeIndex = -1;
            this.open = false;

            this._emitChange();
        },

        selectActive() {
            const result = this.results[this.activeIndex];

            if (result) {
                this.select(result);
            }
        },

        clearSelection() {
            this.resetSelection();
            this.open = false;
        },

        /**
         * Full reset — also used when the operator switches to "Pasien Baru", so
         * no existing-patient selection can survive the mode change.
         */
        resetSelection() {
            const had = this.hasSelection;

            this._invalidateInFlight();

            this.selectedId = '';
            this.selectedLabel = '';
            this.query = '';
            this.results = [];
            this.searched = false;
            this.tooShort = false;
            this.errored = false;
            this.loading = false;
            this.activeIndex = -1;

            if (had) {
                this._emitChange();
            }
        },

        closeDropdown() {
            this.open = false;
        },

        onKeydown(event) {
            const key = event?.key;

            if (key === 'ArrowDown') {
                event.preventDefault?.();
                this.open = true;
                if (this.results.length > 0) {
                    this.activeIndex = (this.activeIndex + 1) % this.results.length;
                }

                return;
            }

            if (key === 'ArrowUp') {
                event.preventDefault?.();
                if (this.results.length > 0) {
                    this.activeIndex = this.activeIndex <= 0
                        ? this.results.length - 1
                        : this.activeIndex - 1;
                }

                return;
            }

            if (key === 'Enter') {
                if (this.open && this.activeIndex >= 0 && this.results[this.activeIndex]) {
                    event.preventDefault?.();
                    this.selectActive();
                }

                return;
            }

            if (key === 'Escape') {
                this.open = false;
            }
        },

        /**
         * Bump the sequence and abort any in-flight request. Returns the new
         * sequence number, which the caller compares against after every await.
         */
        _invalidateInFlight() {
            if (this._controller && typeof this._controller.abort === 'function') {
                this._controller.abort();
            }

            this._controller = null;
            this._seq += 1;

            return this._seq;
        },

        /**
         * The hidden `patient_id` input is what the rest of the form listens to
         * (the follow-up-visit loader binds a `change` handler to it), so the
         * event is dispatched on that element rather than bubbled from the root.
         */
        _emitChange() {
            const el = this.$refs?.patientId;

            if (el && typeof el.dispatchEvent === 'function') {
                el.value = this.selectedId;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },
    };
}
