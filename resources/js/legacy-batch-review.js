/**
 * BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1 — the reviewer keyboard /
 * button layer for the Legacy Batch Review workspace (RME and Odontogram share
 * this one state machine, because they share one view).
 *
 * WHY THIS IS A MODULE AND NOT AN INLINE `x-data` BODY
 * ---------------------------------------------------
 * It was inline, and that is how the defect shipped green. The behaviour that
 * broke is submit-time FORM SERIALIZATION ORDERING, which no PHP feature test
 * can reach (they POST a payload that already contains a decision) and which is
 * invisible to the eye (the button looks like it worked — the page just comes
 * back with a validation error). Same reasoning as `patient-combobox.js` and
 * `doctor-device-webauthn.js`: the hard-to-eyeball part lives in a plain,
 * dependency-free factory so `tests/js/` can drive it with injected doubles.
 * Do NOT move this back inline.
 *
 * THE DEFECT, PRECISELY
 * ---------------------
 * Alpine 3 writes an `x-model` / `:value` binding to the DOM inside a reactive
 * effect, and effects are flushed on the MICROTASK QUEUE
 * (`alpinejs/dist/module.cjs.js`: `effect(() => ... el._x_forceModelUpdate(value))`
 * and `scheduler -> queueJob -> queueMicrotask(flushJobs)`). The old code did:
 *
 *     this.decision = decision;        // queues the DOM write
 *     this.$refs.form.submit();        // runs NOW, before the queue flushes
 *
 * so the browser serialized `decision=''` and the server correctly answered
 * "keputusan tinjauan wajib diisi." The server was never wrong.
 *
 * THE RULE THIS FILE ENFORCES
 * ---------------------------
 * A declarative binding is never trusted at the instant of submission. The
 * decision is written to the field SYNCHRONOUSLY in `submitDecision()`, in the
 * same block as `form.submit()`, so the value cannot be stale. This is not a
 * `setTimeout`/`$nextTick` race workaround — there is no race left to lose.
 *
 * ONE ENCODING, ONE PATH
 * ----------------------
 * Every route to the server — mouse buttons, keyboard R/B/P, and the triage
 * confirmation — goes through `submitDecision()`. There is deliberately no
 * second place that can encode a decision differently.
 *
 * WHAT THIS LAYER MAY NOT DO
 * --------------------------
 *  - It never invents a decision. An empty decision is refused here, and the
 *    server's `required` rule stays the authority.
 *  - It never marks an untouched document reviewed. One attestation names one
 *    `import_id`, rendered server-side; there is no "all" vocabulary to abuse.
 *  - It never submits a withholding decision without a reason. The reason panel
 *    is revealed instead, and the server requires the reason as well.
 */

/** The canonical decision values. Must match LegacyBatchReviewDecision (PHP). */
export const DECISION_REVIEWED = 'REVIEWED';
export const DECISION_BLOCKED = 'BLOCKED';
export const DECISION_NEEDS_ATTENTION = 'NEEDS_ATTENTION';

/** Decisions that withhold the document and therefore require a reason. */
const TRIAGING_DECISIONS = [DECISION_BLOCKED, DECISION_NEEDS_ATTENTION];

export function createLegacyBatchReview(config = {}) {
    return {
        decision: '',
        reasonCode: '',
        needsReason: false,
        mutable: Boolean(config.mutable),

        /**
         * Record the reviewer's choice for the focused document.
         *
         * REVIEWED submits immediately. A withholding decision only reveals the
         * reason panel — it must not reach the server without a reason, and the
         * server enforces that independently.
         */
        mark(decision) {
            if (! this.mutable) {
                return false;
            }

            if (! this.isKnownDecision(decision)) {
                return false;
            }

            this.decision = decision;

            if (TRIAGING_DECISIONS.includes(decision)) {
                this.needsReason = true;

                return false;
            }

            this.needsReason = false;

            return this.submitDecision(decision);
        },

        /** Submit a withholding decision once a reason has been chosen. */
        confirmTriage() {
            if (this.reasonCode === '') {
                return false;
            }

            return this.submitDecision(this.decision);
        },

        cancelTriage() {
            this.needsReason = false;
            this.decision = '';
            this.reasonCode = '';
        },

        /**
         * THE single submission path.
         *
         * Writes the decision into the field the browser is about to serialize,
         * synchronously, then submits. Nothing here depends on Alpine having
         * flushed a binding.
         */
        submitDecision(decision) {
            if (! this.mutable) {
                return false;
            }

            // Never submit a decision the operator did not make. The server
            // would reject it anyway; refusing here keeps the operator on the
            // page with their place in the queue intact.
            if (! this.isKnownDecision(decision)) {
                return false;
            }

            const refs = this.$refs || {};
            const form = refs.form;
            const field = refs.decisionField;

            // If either is missing the form is not the one this component was
            // written for. Refuse rather than submit something unverifiable.
            if (! form || ! field) {
                return false;
            }

            field.value = decision;

            form.submit();

            return true;
        },

        isKnownDecision(decision) {
            return decision === DECISION_REVIEWED
                || TRIAGING_DECISIONS.includes(decision);
        },

        onKey(event) {
            // Never hijack typing in the reason note, a select or a filter.
            const tag = (event.target && event.target.tagName || '').toLowerCase();

            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const key = (event.key || '').toLowerCase();

            // The shortcuts call the very same mark() the buttons call, so the
            // keyboard cannot produce a different payload.
            if (key === 'r') { event.preventDefault(); this.mark(DECISION_REVIEWED); return; }
            if (key === 'b') { event.preventDefault(); this.mark(DECISION_BLOCKED); return; }
            if (key === 'p') { event.preventDefault(); this.mark(DECISION_NEEDS_ATTENTION); return; }

            if (event.key === 'ArrowLeft' && this.$refs && this.$refs.prev) {
                event.preventDefault();
                this.$refs.prev.click();

                return;
            }

            if (event.key === 'ArrowRight' && this.$refs && this.$refs.next) {
                event.preventDefault();
                this.$refs.next.click();
            }
        },
    };
}

export default createLegacyBatchReview;
