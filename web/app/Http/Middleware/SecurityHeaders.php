<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections on every page the application sends.
 *
 * - Always: the page may not be framed by another site, may not be sniffed into
 *   another content type, sends little to other sites in the Referer, and may not
 *   use the camera, microphone or location.
 * - In production only: a Content-Security-Policy (the page may load scripts, styles,
 *   fonts and images only from itself, and submit forms only to itself), and
 *   Strict-Transport-Security once the request came over HTTPS. The policy is off in
 *   development because the Vite dev server serves scripts from another port.
 *
 * Scripts are allowed from the site's own files and from inline tags that carry this
 * request's nonce, a random value made for each response and put on the tags the page
 * itself writes (the Vite tags, and the small script that applies the dark theme before
 * the page is drawn). A script an attacker manages to inject has no nonce and does not
 * run. `style-src` allows inline styles because charts and dialogs set sizes through
 * style attributes; scripts, the thing a policy mostly exists to stop, get no such exception.
 */
class SecurityHeaders
{
    /** The policy, with a place for the nonce. */
    private const POLICY = "default-src 'self'; script-src 'self' 'nonce-%s'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    public static function policy(string $nonce): string
    {
        return sprintf(self::POLICY, $nonce);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Made before the page is, so the tags it writes can carry it.
        $nonce = config('security.csp') ? Vite::useCspNonce() : null;

        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($nonce !== null) {
            $headers->set('Content-Security-Policy', self::policy($nonce));
        }

        if (config('security.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
