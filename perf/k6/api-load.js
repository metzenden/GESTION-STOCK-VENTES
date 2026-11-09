// L7-04 — Test de charge k6 : parcours vendeur (connexion, recherche, stock, commandes, factures).
// Exécution : docker run --rm -i --network host -e BASE=https://preprod.example.com -e EMAIL=… -e PASSWORD=… grafana/k6 run - < perf/k6/api-load.js
// Seuils : p95 < 800 ms, moins de 1 % d'erreurs (docs/07 §8).
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '1m', target: 20 },
    { duration: '3m', target: 60 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<800'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE = __ENV.BASE || 'http://localhost:5173';

export function setup() {
  const jar = http.cookieJar();
  http.get(`${BASE}/sanctum/csrf-cookie`);
  const xsrf = decodeURIComponent(jar.cookiesForURL(BASE)['XSRF-TOKEN'] || '');
  const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, Referer: BASE },
  });
  check(res, { 'connexion': (r) => r.status === 200 });
  return { cookies: jar.cookiesForURL(BASE) };
}

export default function (data) {
  const jar = http.cookieJar();
  for (const [name, values] of Object.entries(data.cookies)) jar.set(BASE, name, values[0]);
  const params = { headers: { Accept: 'application/json', Referer: BASE } };

  for (const path of ['/api/v1/products?per_page=25', '/api/v1/stock/positions?per_page=25', '/api/v1/sales-orders?per_page=25',
    '/api/v1/invoices?per_page=25', '/api/v1/dashboard', '/api/v1/search?q=ch']) {
    const res = http.get(`${BASE}${path}`, params);
    check(res, { [`${path} 200`]: (r) => r.status === 200 });
  }
  sleep(1);
}
