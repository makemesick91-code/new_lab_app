// Sprint 67.6 — k6 RME WRITE full-flow local test (stress env only).
//
// SAFE LOCAL-ONLY load test that exercises the RME *write* journey under
// concurrency using unique synthetic markers per VU/iteration. It targets the
// local stress server (http://127.0.0.1:8008, daengtisia_stress) and never the
// VPS / pilot / live data.
//
// Implemented (PASS) write journey, per iteration:
//   1. (once per VU) Login as a stress "Admin Klinik" user.
//   2. (once per VU) Select RME online context (GET, follows redirect to the
//      auto-resolved branch dashboard — Admin Klinik branch is implicit).
//   3. GET the visit-create form and extract the CSRF _token.
//   4. POST /rme/visits — create a NEW clinic visit for an existing patient,
//      tagged with the unique "K6 Sprint 67.6 / K6S676" namespace. This is the
//      real concurrent DB write (INSERT into trx_clinic_visits).
//   5. Parse the 302 redirect Location to capture the created visit id.
//   6. GET the created visit, the visit index, and the patient queue (all 200).
//
// Deferred sub-flows (WATCH — see stage6 summary doc for exact blockers):
//   * Create a *new patient*: no seeded stress role has `manage patients`, so
//     `patient_mode=new` (and settings/patients store) returns 403. We instead
//     attach each new visit to an existing seeded patient.
//   * Assign room / write medical record / odontogram / cashier+payment: room
//     assignment requires exactly one non-expired *online doctor* in the room
//     (UserOnlineContextService::resolveDoctorIdForRoom) and the medical-record
//     routes are gated behind room assignment (EnsureVisitRoomAssigned). These
//     need a multi-actor online-doctor session that is out of scope for a single
//     k6 HTTP VU this sprint.
//
// Run (password is passed via env only — NEVER commit it):
//   k6 run -e VUS=5 -e ITER_PER_VU=3 -e STRESS_PASSWORD='***' \
//     tests/k6/rme-write-full-flow-local.js
//
// No password / cookie / token value is hard-coded or printed by this script.

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8008';
// Password MUST be supplied via -e STRESS_PASSWORD=... It is intentionally not
// defaulted so the script never embeds a credential.
const PASSWORD = __ENV.STRESS_PASSWORD || '';
const BRANCH_ID = __ENV.BRANCH_ID || '1';
const TREATMENT_ID = __ENV.TREATMENT_ID || '1';
// Existing-patient pool: visits attach to a random patient id in [1, MAX].
const PATIENT_ID_MAX = Number(__ENV.PATIENT_ID_MAX || 200000);
// Seeded Admin Klinik accounts to spread VUs across. The default range is
// stress.admin002..admin010 (9 users) — they share one password, while
// stress.admin001 was seeded with a different password and is excluded so the
// whole pool works with a single STRESS_PASSWORD value.
const ADMIN_USER_START = Number(__ENV.ADMIN_USER_START || 2);
const ADMIN_USER_COUNT = Number(__ENV.ADMIN_USER_COUNT || 9);

export const write_flow_duration = new Trend('write_flow_duration', true);
export const write_flow_success_rate = new Rate('write_flow_success_rate');
export const write_flow_failures = new Counter('write_flow_failures');
export const visit_create_success_rate = new Rate('visit_create_success_rate');
export const page_verify_success_rate = new Rate('page_verify_success_rate');

export const options = {
  scenarios: {
    write_flow: {
      executor: 'per-vu-iterations',
      vus: Number(__ENV.VUS || 1),
      iterations: Number(__ENV.ITER_PER_VU || 3),
      maxDuration: __ENV.MAX_DURATION || '10m',
      exec: 'writeFlow',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    write_flow_success_rate: ['rate>0.99'],
    visit_create_success_rate: ['rate>0.99'],
  },
};

function csrfFrom(html) {
  const match = html.match(/name="_token"\s+value="([^"]+)"/)
    || html.match(/value="([^"]+)"\s+name="_token"/)
    || html.match(/<meta name="csrf-token" content="([^"]+)"/);

  return match ? match[1] : '';
}

function adminEmail() {
  // Spread VUs across the seeded Admin Klinik accounts to avoid hammering a
  // single user session; cycles START..START+COUNT-1.
  const n = ADMIN_USER_START + ((__VU - 1) % ADMIN_USER_COUNT);
  return `stress.admin${String(n).padStart(3, '0')}@daengtisia.test`;
}

function fail(label, res) {
  write_flow_failures.add(1, { step: label, status: String(res ? res.status : 'n/a') });
}

// k6 resets the per-VU cookie jar at the start of every iteration, so the
// Laravel session cookie does not survive between iterations. We therefore log
// in at the start of each iteration to keep an authenticated, context-ready
// session for the write — a true "login once per VU" is not possible under k6's
// cookie-reset model without fragile manual cookie replay.
function login() {
  const loginPage = http.get(`${BASE_URL}/login`, {
    tags: { name: 'GET /login' },
    responseType: 'text',
  });
  if (!check(loginPage, { 'login page 200': (r) => r.status === 200 })) {
    fail('login_page', loginPage);
    return false;
  }

  const token = csrfFrom(loginPage.body || '');
  if (!token) {
    fail('csrf_login_missing', loginPage);
    return false;
  }

  const loginRes = http.post(
    `${BASE_URL}/login`,
    { _token: token, email: adminEmail(), password: PASSWORD },
    { tags: { name: 'POST /login' }, redirects: 5 },
  );
  if (!check(loginRes, { 'login accepted': (r) => [200, 302].includes(r.status) })) {
    fail('login_post', loginRes);
    return false;
  }

  // Open the RME online-context select page. An UNauthenticated session follows
  // the redirect back to the 200 login page, so we assert the final URL is not
  // /login to confirm authentication (a bare status===200 is a false positive).
  const selectPage = http.get(`${BASE_URL}/rme/online-context/select`, {
    tags: { name: 'GET /rme/online-context/select' },
    redirects: 5,
    responseType: 'text',
  });
  const authed = check(selectPage, {
    'authenticated (not /login)': (r) => r.status === 200 && !r.url.endsWith('/login'),
  });
  if (!authed) {
    fail('login_not_authenticated', selectPage);
    return false;
  }

  // Select the RME branch context (Admin Klinik → admin-clinic). This sets the
  // active online branch the new visit is registered under.
  const ctxToken = csrfFrom(selectPage.body || '');
  if (!ctxToken) {
    fail('csrf_context_missing', selectPage);
    return false;
  }
  const ctxRes = http.post(
    `${BASE_URL}/rme/online-context/admin-clinic`,
    { _token: ctxToken, branch_id: BRANCH_ID },
    { tags: { name: 'POST /rme/online-context/admin-clinic' }, redirects: 0 },
  );
  const ctxLocation = ctxRes.headers['Location'] || ctxRes.headers['location'] || '';
  const ctxOk = check(ctxRes, {
    'context set (redirect, not /login)': (r) => [302, 303].includes(r.status) && !ctxLocation.endsWith('/login'),
  });
  if (!ctxOk) {
    fail('context_post', ctxRes);
  }

  return ctxOk;
}

export function writeFlow() {
  const started = Date.now();

  if (!login()) {
    write_flow_success_rate.add(false);
    visit_create_success_rate.add(false);
    return;
  }

  let visitId = null;
  let ok = true;

  group('create visit (write)', () => {
    // CSRF token source is the visit INDEX page (~70 KB). We deliberately do NOT
    // fetch /rme/visits/create here: that form eager-loads the entire patient
    // master (~47 MB / ~9 s on the 250k-patient stress dataset) and would make
    // this a page-render test rather than a write-throughput test. The 47 MB
    // create form is recorded as a separate documented bottleneck (see summary).
    const indexPage = http.get(`${BASE_URL}/rme/visits`, {
      tags: { name: 'GET /rme/visits (token+verify)' },
      responseType: 'text',
    });
    const pageOk = check(indexPage, { 'visit index 200': (r) => r.status === 200 });
    if (!pageOk) {
      fail('visits_index', indexPage);
      ok = false;
      return;
    }

    const token = csrfFrom(indexPage.body || '');
    if (!token) {
      fail('csrf_index_missing', indexPage);
      ok = false;
      return;
    }

    const patientId = Math.floor(Math.random() * PATIENT_ID_MAX) + 1;
    // Unique synthetic marker — no real patient data. Namespace lets a later SQL
    // count identify exactly the rows this run created.
    const marker = `K6 Sprint 67.6 | K6S676 | VU${__VU} ITER${__ITER} ${Date.now()}`;

    const res = http.post(
      `${BASE_URL}/rme/visits`,
      {
        _token: token,
        patient_mode: 'existing',
        visit_type: 'new',
        branch_id: BRANCH_ID,
        patient_id: String(patientId),
        initial_treatment_id: TREATMENT_ID,
        chief_complaint: marker,
      },
      { tags: { name: 'POST /rme/visits' }, redirects: 0 },
    );

    // A successful create redirects (302/303) to rme.visits.show.
    const location = res.headers['Location'] || res.headers['location'] || '';
    const m = location.match(/\/rme\/visits\/(\d+)/);
    visitId = m ? m[1] : null;

    const created = check(res, {
      'visit create redirected': (r) => [302, 303].includes(r.status),
      'visit id parsed': () => visitId !== null,
    });
    visit_create_success_rate.add(created);
    if (!created) {
      fail('visit_create', res);
      ok = false;
    }
  });

  if (visitId) {
    group('verify pages (200)', () => {
      const show = http.get(`${BASE_URL}/rme/visits/${visitId}`, { tags: { name: 'GET /rme/visits/:id' } });
      const queue = http.get(`${BASE_URL}/rme/patient-queue`, { tags: { name: 'GET /rme/patient-queue' } });

      const verified = check({ show, queue }, {
        'visit show 200': (o) => o.show.status === 200,
        'patient queue 200': (o) => o.queue.status === 200,
      });
      page_verify_success_rate.add(verified);
      if (!verified) {
        ok = false;
        if (show.status !== 200) fail('visit_show', show);
        if (queue.status !== 200) fail('patient_queue', queue);
      }
    });
  }

  write_flow_duration.add(Date.now() - started);
  write_flow_success_rate.add(ok && visitId !== null);
  sleep(0.3);
}
