// Everyday browsing under load: many people opening the pages that matter, at once.
//
//   docker run --rm -i -v "$PWD/tests/load:/scripts" -e BASE_URL=http://host.docker.internal:8090 \
//     grafana/k6 run /scripts/pages.js
//
// Each virtual user opens the pages a person opens in a working day, with a short pause between
// them. The load rises to 25 people at once (see VUS and THINK below to change that). The thresholds are the targets the app is held to:
// almost nothing fails, and 95 of 100 page loads finish in under a second.
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { BASE_URL, signIn, useSession } from './lib.js';

// VUS is the most people at once (25 by default); THINK is the pause between pages in seconds
// (1 to 3 by default, like a person reading). A stress run: VUS=100 THINK=0.3.
const PEAK = Number(__ENV.VUS || 25);
const THINK = Number(__ENV.THINK || 2);

export const options = {
    scenarios: {
        browsing: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: '20s', target: Math.ceil(PEAK / 5) },
                { duration: '40s', target: Math.ceil(PEAK * 0.6) },
                { duration: '60s', target: PEAK },
                { duration: '20s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
        'http_req_duration{kind:page}': ['p(95)<1000', 'p(99)<2000'],
        'http_req_duration{page:dashboard}': ['p(95)<1200'],
        'http_req_duration{page:products}': ['p(95)<800'],
        'http_req_duration{page:recommendations}': ['p(95)<1000'],
        'http_req_duration{page:report}': ['p(95)<1200'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return { cookies: signIn() };
}

const visit = (name, path) => {
    const response = http.get(`${BASE_URL}${path}`, { tags: { kind: 'page', page: name } });

    check(response, {
        [`${name}: 200`]: (r) => r.status === 200,
        [`${name}: is the page, not the login`]: (r) => !r.url.endsWith('/login'),
    });

    sleep(THINK * (0.5 + Math.random()));
};

export default function (data) {
    useSession(data.cookies);

    group('start of the day', () => {
        visit('dashboard', '/dashboard');
        visit('recommendations', '/recommendations');
        visit('recommendations', '/recommendations?risk=critical');
    });

    group('stock', () => {
        visit('products', '/products');
        visit('products', '/products?search=rice');
        visit('inventory', '/inventory');
        visit('inventory', '/inventory?stock=low');
    });

    group('sales and forecasts', () => {
        visit('sales', '/sales');
        visit('forecasts', '/forecasts');
        visit('forecasts', '/forecasts?granularity=month');
        visit('accuracy', '/forecasts/accuracy');
    });

    group('looking things up', () => {
        visit('report', '/reports/sales');
        visit('report', '/reports/inventory');
        visit('notifications', '/notifications');
    });
}
