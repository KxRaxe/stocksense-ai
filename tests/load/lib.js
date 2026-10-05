// Shared by the k6 scripts: where the app is, and how to be signed in.
//
// Signing in is rate limited (five tries a minute for each address), so a script signs in once, in
// setup(), and hands the session to every virtual user.
import http from 'k6/http';
import { check, fail } from 'k6';

export const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8090').replace(/\/$/, '');
const EMAIL = __ENV.EMAIL || 'owner@stocksense.test';
const PASSWORD = __ENV.PASSWORD || 'password';

/** Signs in and returns the session cookies as a plain object, to pass from setup() to the users. */
export function signIn() {
    const jar = http.cookieJar();

    // The first request makes the cross-site-forgery cookie, which the sign-in must send back.
    http.get(`${BASE_URL}/login`);

    const token = decodeURIComponent((jar.cookiesForURL(`${BASE_URL}/login`)['XSRF-TOKEN'] || [''])[0]);

    const response = http.post(
        `${BASE_URL}/login`,
        JSON.stringify({ email: EMAIL, password: PASSWORD }),
        { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token } },
    );

    if (!check(response, { 'signed in': (r) => r.status === 200 || r.status === 204 || r.status === 302 })) {
        fail(`could not sign in as ${EMAIL}: status ${response.status} ${response.body}`);
    }

    const cookies = {};

    for (const [name, values] of Object.entries(jar.cookiesForURL(`${BASE_URL}/`))) {
        cookies[name] = values[0];
    }

    return cookies;
}

/** Gives this virtual user the session that setup() signed in. */
export function useSession(cookies) {
    const jar = http.cookieJar();

    for (const [name, value] of Object.entries(cookies)) {
        jar.set(BASE_URL, name, value);
    }
}

/** The cross-site-forgery token to send with a change, from the session. */
export function csrfHeader() {
    const jar = http.cookieJar();
    const value = (jar.cookiesForURL(`${BASE_URL}/`)['XSRF-TOKEN'] || [''])[0];

    return { 'X-XSRF-TOKEN': decodeURIComponent(value) };
}
