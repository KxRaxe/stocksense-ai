// How long a forecast takes: start a weekly forecast and a monthly one, and time each until it is
// finished, while the app is still answering page requests. This is the "run time for about 50
// products" figure for the performance section of the thesis.
//
//   docker run --rm -i -v "$PWD/tests/load:/scripts" -e BASE_URL=http://host.docker.internal:8090 \
//     grafana/k6 run /scripts/forecast-run.js
import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, csrfHeader, signIn, useSession } from './lib.js';

// From pressing the button to the result being ready.
const runTime = new Trend('forecast_run_time', true);

export const options = {
    scenarios: {
        forecasts: { executor: 'shared-iterations', vus: 1, iterations: 2, maxDuration: '20m' },
        // Meanwhile, a person keeps using the app.
        browsing: { executor: 'constant-vus', vus: 3, duration: '1m', exec: 'browse' },
    },
    thresholds: {
        'forecast_run_time{granularity:week}': ['p(95)<300000'],
        'forecast_run_time{granularity:month}': ['p(95)<300000'],
        'http_req_duration{kind:page}': ['p(95)<2000'],
        http_req_failed: ['rate<0.01'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'max'],
};

export function setup() {
    return { cookies: signIn() };
}

/** Whether a forecast is still queued or running, read from the page's own data. */
const running = (granularity) => {
    const page = http.get(`${BASE_URL}/forecasts?granularity=${granularity}`, { tags: { kind: 'status' } });
    const active = (page.body.match(/&quot;activeRun&quot;:(null|\{)/) || page.body.match(/"activeRun":(null|\{)/) || [])[1];

    return active === '{';
};

export default function (data) {
    useSession(data.cookies);

    const granularity = __ITER % 2 === 0 ? 'week' : 'month';

    // Wait for any run that is already going, so what is timed is this one.
    for (let wait = 0; running(granularity) && wait < 120; wait++) {
        sleep(5);
    }

    const started = Date.now();
    const response = http.post(`${BASE_URL}/forecasts/run`, JSON.stringify({ granularity }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        redirects: 0,
    });

    if (!check(response, { 'started': (r) => r.status === 302 || r.status === 200 || r.status === 303 })) {
        fail(`could not start a ${granularity} forecast: ${response.status} ${response.body}`);
    }

    sleep(3);

    while (running(granularity)) {
        if (Date.now() - started > 15 * 60 * 1000) {
            fail(`the ${granularity} forecast took more than 15 minutes`);
        }

        sleep(5);
    }

    runTime.add(Date.now() - started, { granularity });
}

export function browse(data) {
    useSession(data.cookies);

    for (const path of ['/dashboard', '/recommendations', '/products', '/inventory']) {
        const response = http.get(`${BASE_URL}${path}`, { tags: { kind: 'page' } });

        check(response, { 'page loads while a forecast runs': (r) => r.status === 200 });
        sleep(2);
    }
}
