/**
 * BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1 — submission-contract
 * tests for the Legacy Batch Review reviewer layer (RME + Odontogram share it).
 *
 * WHY THESE EXIST
 * ---------------
 * The shipped defect was invisible to every PHP test in the suite, because those
 * POST a payload that already contains a decision. They prove the SERVER
 * contract and structurally cannot see a client that serializes an empty field.
 * The decisive assertion is therefore about ORDERING: what did the field hold at
 * the exact instant `form.submit()` was called?
 *
 * The fake form records `decisionField.value` from inside `submit()`, so a
 * component that sets the value *after* submitting — or relies on Alpine's
 * microtask flush, which is what broke — fails here.
 *
 * Node's built-in runner, no new dependency:
 *     npm run test:js
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    createLegacyBatchReview,
    DECISION_REVIEWED,
    DECISION_BLOCKED,
    DECISION_NEEDS_ATTENTION,
} from '../../resources/js/legacy-batch-review.js';

/**
 * A component wired to a fake form. `submitted` captures the field value AS
 * SEEN BY THE BROWSER at serialization time — not afterwards.
 */
function mount({ mutable = true, withRefs = true } = {}) {
    const field = { value: '' };
    const submitted = [];

    const form = {
        submit() {
            // This is the serialization instant. Whatever the field holds right
            // now is what the server receives.
            submitted.push(field.value);
        },
    };

    const component = createLegacyBatchReview({ mutable });

    component.$refs = withRefs
        ? { form, decisionField: field }
        : {};

    return { component, field, submitted };
}

function keyEvent(key, { tag = 'body', meta = false } = {}) {
    let prevented = false;

    return {
        key,
        metaKey: meta,
        ctrlKey: false,
        altKey: false,
        target: { tagName: tag },
        preventDefault() { prevented = true; },
        get defaultPrevented() { return prevented; },
    };
}

// ---------------------------------------------------------------------------
// The defect itself: the decision must be in the field BEFORE submit.
// ---------------------------------------------------------------------------

test('marking REVIEWED has the decision in the field at the submit instant', () => {
    const { component, submitted } = mount();

    const didSubmit = component.mark(DECISION_REVIEWED);

    assert.equal(didSubmit, true);
    assert.deepEqual(submitted, [DECISION_REVIEWED],
        'the form was serialized with an empty decision — this is the shipped bug');
});

test('the decision reaches the field synchronously, with no microtask flush', () => {
    const { component, field, submitted } = mount();

    component.mark(DECISION_REVIEWED);

    // No await anywhere: if the value only arrived via a queued effect, the
    // captured submission above would have been ''.
    assert.equal(field.value, DECISION_REVIEWED);
    assert.equal(submitted[0], DECISION_REVIEWED);
});

test('keyboard R produces exactly the same payload as the button', () => {
    const viaButton = mount();
    viaButton.component.mark(DECISION_REVIEWED);

    const viaKey = mount();
    const event = keyEvent('r');
    viaKey.component.onKey(event);

    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(viaKey.submitted, viaButton.submitted,
        'the keyboard and the button must share one decision encoding');
    assert.deepEqual(viaKey.submitted, [DECISION_REVIEWED]);
});

// ---------------------------------------------------------------------------
// Withholding decisions: reason first, then one submission path.
// ---------------------------------------------------------------------------

for (const decision of [DECISION_BLOCKED, DECISION_NEEDS_ATTENTION]) {
    test(`${decision} reveals the reason panel instead of submitting`, () => {
        const { component, submitted } = mount();

        const didSubmit = component.mark(decision);

        assert.equal(didSubmit, false);
        assert.equal(component.needsReason, true);
        assert.equal(component.decision, decision);
        assert.deepEqual(submitted, [], 'a withheld document must not reach the server without a reason');
    });

    test(`${decision} submits with its decision once a reason is chosen`, () => {
        const { component, submitted } = mount();

        component.mark(decision);
        component.reasonCode = 'ILLEGIBLE_SOURCE';

        const didSubmit = component.confirmTriage();

        assert.equal(didSubmit, true);
        assert.deepEqual(submitted, [decision]);
    });

    test(`${decision} keyboard shortcut matches its button`, () => {
        const viaButton = mount();
        viaButton.component.mark(decision);
        viaButton.component.reasonCode = 'ILLEGIBLE_SOURCE';
        viaButton.component.confirmTriage();

        const viaKey = mount();
        viaKey.component.onKey(keyEvent(decision === DECISION_BLOCKED ? 'b' : 'p'));
        viaKey.component.reasonCode = 'ILLEGIBLE_SOURCE';
        viaKey.component.confirmTriage();

        assert.deepEqual(viaKey.submitted, viaButton.submitted);
    });
}

test('confirming triage without a reason submits nothing', () => {
    const { component, submitted } = mount();

    component.mark(DECISION_BLOCKED);

    assert.equal(component.confirmTriage(), false);
    assert.deepEqual(submitted, []);
});

test('cancelling triage clears the decision so nothing is left armed', () => {
    const { component, field, submitted } = mount();

    component.mark(DECISION_BLOCKED);
    component.reasonCode = 'ILLEGIBLE_SOURCE';
    component.cancelTriage();

    assert.equal(component.decision, '');
    assert.equal(component.reasonCode, '');
    assert.equal(component.needsReason, false);
    assert.equal(component.confirmTriage(), false);
    assert.equal(field.value, '');
    assert.deepEqual(submitted, []);
});

// ---------------------------------------------------------------------------
// No default REVIEWED, ever. An untouched document stays untouched.
// ---------------------------------------------------------------------------

test('an untouched document submits nothing at all', () => {
    const { component, field, submitted } = mount();

    assert.equal(component.decision, '');
    assert.equal(field.value, '');
    assert.deepEqual(submitted, []);
});

test('an empty or unknown decision is refused and never defaults to REVIEWED', () => {
    for (const bad of ['', null, undefined, 'reviewed', 'APPROVED', 'ALL', 'PUBLISH', 0, true]) {
        const { component, field, submitted } = mount();

        assert.equal(component.mark(bad), false, `mark(${JSON.stringify(bad)}) must refuse`);
        assert.equal(component.submitDecision(bad), false, `submitDecision(${JSON.stringify(bad)}) must refuse`);
        assert.deepEqual(submitted, [], `${JSON.stringify(bad)} must not reach the server`);
        assert.notEqual(field.value, DECISION_REVIEWED, 'nothing may silently become REVIEWED');
    }
});

test('an immutable session submits nothing from any path', () => {
    const { component, submitted } = mount({ mutable: false });

    assert.equal(component.mark(DECISION_REVIEWED), false);
    assert.equal(component.submitDecision(DECISION_REVIEWED), false);

    component.onKey(keyEvent('r'));

    assert.deepEqual(submitted, []);
});

test('a form without the decision field refuses rather than submitting blind', () => {
    const { component, submitted } = mount({ withRefs: false });

    assert.equal(component.mark(DECISION_REVIEWED), false);
    assert.deepEqual(submitted, []);
});

// ---------------------------------------------------------------------------
// The keyboard layer must not fight the operator.
// ---------------------------------------------------------------------------

test('shortcuts are inert while typing in a field', () => {
    for (const tag of ['INPUT', 'TEXTAREA', 'SELECT']) {
        const { component, submitted } = mount();

        component.onKey(keyEvent('r', { tag }));

        assert.deepEqual(submitted, [], `typing in <${tag.toLowerCase()}> must not attest`);
    }
});

test('a modified keystroke is never a decision', () => {
    const { component, submitted } = mount();

    component.onKey(keyEvent('r', { meta: true }));

    assert.deepEqual(submitted, []);
});

test('arrow navigation moves focus and never attests', () => {
    const { component, submitted } = mount();
    let prevClicks = 0;
    let nextClicks = 0;

    component.$refs.prev = { click() { prevClicks += 1; } };
    component.$refs.next = { click() { nextClicks += 1; } };

    component.onKey(keyEvent('ArrowLeft'));
    component.onKey(keyEvent('ArrowRight'));

    assert.equal(prevClicks, 1);
    assert.equal(nextClicks, 1);
    assert.deepEqual(submitted, [], 'navigating must never record a decision');
});
