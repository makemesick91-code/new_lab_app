import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8008';
const EMAIL = __ENV.STRESS_EMAIL || 'stress.admin001@daengtisia.test';
const PASSWORD = __ENV.STRESS_PASSWORD || '';
const VISIT_ID = __ENV.VISIT_ID || '1000000';
const BRANCH_ID = __ENV.BRANCH_ID || '1';

export const full_flow_duration = new Trend('full_flow_duration');
export const full_flow_success_rate = new Rate('full_flow_success_rate');
export const full_flow_failures = new Counter('full_flow_failures');

let authenticated = false;

export const options = {
  scenarios: {
    full_flow_users: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 50),
      duration: __ENV.DURATION || '2m',
      gracefulStop: '30s',
      exec: 'fullFlow',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1000'],
    full_flow_success_rate: ['rate>0.99'],
    full_flow_duration: ['p(95)<15000'],
  },
};

function csrfFrom(html) {
  const match = html.match(/name="_token"\s+value="([^"]+)"/)
    || html.match(/value="([^"]+)"\s+name="_token"/)
    || html.match(/<meta name="csrf-token" content="([^"]+)"/);

  return match ? match[1] : '';
}

function expect200(res, label) {
  const ok = check(res, {
    [`${label} HTTP 200`]: (r) => r.status === 200,
  });

  if (!ok) {
    full_flow_failures.add(1, { step: label, status: String(res.status) });
  }

  return ok;
}

function loginAndContextOnce() {
  if (authenticated) {
    return true;
  }

  const loginPage = http.get(`${BASE_URL}/login`, {
    tags: { name: 'GET /login' },
    responseType: 'text',
  });

  if (!expect200(loginPage, 'login_page')) {
    return false;
  }

  const token = csrfFrom(loginPage.body || '');

  if (!token) {
    full_flow_failures.add(1, { step: 'csrf_login_missing' });
    return false;
  }

  const loginRes = http.post(
    `${BASE_URL}/login`,
    {
      _token: token,
      email: EMAIL,
      password: PASSWORD,
    },
    {
      tags: { name: 'POST /login' },
      redirects: 5,
    },
  );

  const loginOk = check(loginRes, {
    'login accepted': (r) => [200, 302].includes(r.status),
  });

  if (!loginOk) {
    full_flow_failures.add(1, { step: 'login_post', status: String(loginRes.status) });
    return false;
  }

  const contextPage = http.get(`${BASE_URL}/rme/online-context/select`, {
    tags: { name: 'GET /rme/online-context/select' },
    responseType: 'text',
  });

  const contextToken = csrfFrom(contextPage.body || '') || token;

  const contextRes = http.post(
    `${BASE_URL}/rme/online-context/admin-clinic`,
    {
      _token: contextToken,
      branch_id: BRANCH_ID,
    },
    {
      tags: { name: 'POST /rme/online-context/admin-clinic' },
      redirects: 5,
    },
  );

  const contextOk = check(contextRes, {
    'context accepted': (r) => [200, 302].includes(r.status),
  });

  if (!contextOk) {
    full_flow_failures.add(1, { step: 'context_post', status: String(contextRes.status) });
    return false;
  }

  const dashboard = http.get(`${BASE_URL}/rme/dashboard`, {
    tags: { name: 'GET /rme/dashboard auth_check' },
  });

  authenticated = expect200(dashboard, 'dashboard_auth_check');

  return authenticated;
}

export function fullFlow() {
  const started = Date.now();
  let ok = true;

  group('01 login once and context once per VU', () => {
    ok = loginAndContextOnce() && ok;
  });

  if (!ok) {
    full_flow_success_rate.add(false);
    return;
  }

  const urls = [
    ['rme_dashboard', `${BASE_URL}/rme/dashboard`, 'GET /rme/dashboard'],
    ['rme_visits', `${BASE_URL}/rme/visits`, 'GET /rme/visits'],
    ['rme_visit_show', `${BASE_URL}/rme/visits/${VISIT_ID}`, 'GET /rme/visits/:id'],
    ['rme_medical_record', `${BASE_URL}/rme/visits/${VISIT_ID}/medical-record`, 'GET /rme/visits/:id/medical-record'],
    ['rme_odontogram', `${BASE_URL}/rme/visits/${VISIT_ID}/odontogram`, 'GET /rme/visits/:id/odontogram'],
    ['rme_cashier', `${BASE_URL}/rme/cashier`, 'GET /rme/cashier'],
    ['rme_receivables', `${BASE_URL}/rme/cashier/receivables`, 'GET /rme/cashier/receivables'],
    ['rme_reports_patients', `${BASE_URL}/rme/reports/patients`, 'GET /rme/reports/patients'],
    ['rme_reports_payments', `${BASE_URL}/rme/reports/payments`, 'GET /rme/reports/payments'],
  ];

  for (const [label, url, tagName] of urls) {
    group(label, () => {
      const res = http.get(url, { tags: { name: tagName } });
      ok = expect200(res, label) && ok;
      sleep(0.2);
    });
  }

  full_flow_duration.add(Date.now() - started);
  full_flow_success_rate.add(ok);
}
