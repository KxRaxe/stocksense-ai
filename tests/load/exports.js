// Report downloads under load. Making an Excel file or a PDF is real work, so fewer people do it at
// once than browse; the limit is also what the app's own rate limit (10 downloads a minute for each
// person) is built for, so this script uses one session and stays within it.
//
//   docker run --rm -i -v "$PWD/tests/load:/scripts" -e BASE_URL=http://host.docker.internal:8090 \
//     grafana/k6 run /scripts/exports.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, signIn, useSession } from './lib.js';

export const options = {
    scenarios: {
        downloads: {
            executor: 'constant-arrival-rate',
            rate: 6,
            timeUnit: '1m',
            duration: '2m',
            preAllocatedVUs: 3,
            maxVUs: 6,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{kind:xlsx}': ['p(95)<6000'],
        'http_req_duration{kind:pdf}': ['p(95)<12000'],
        checks: ['rate>0.99'],
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return { cookies: signIn() };
}

const downloads = [
    ['xlsx', '/reports/sales/export/xlsx'],
    ['pdf', '/reports/sales/export/pdf'],
    ['xlsx', '/reports/inventory/export/xlsx'],
    ['pdf', '/reports/inventory/export/pdf'],
    ['xlsx', '/reports/replenishment/export/xlsx'],
    ['pdf', '/reports/forecast-accuracy/export/pdf'],
];

let next = 0;

export default function (data) {
    useSession(data.cookies);

    const [kind, path] = downloads[next++ % downloads.length];
    const response = http.get(`${BASE_URL}${path}`, { tags: { kind } });

    check(response, {
        [`${kind}: 200`]: (r) => r.status === 200,
        [`${kind}: has content`]: (r) => r.body && r.body.length > 1000,
        [`${kind}: is the right kind of file`]: (r) =>
            kind === 'pdf'
                ? (r.headers['Content-Type'] || '').includes('application/pdf')
                : (r.headers['Content-Type'] || '').includes('spreadsheetml'),
    });

    sleep(1);
}
