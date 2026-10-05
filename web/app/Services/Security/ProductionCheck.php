<?php

namespace App\Services\Security;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Looks over the running configuration for what must not go to production
 * unchanged: debug output, development secrets, passwords everyone knows, and
 * settings that leave sessions or email unprotected.
 *
 * Each finding is a failure (never run like this in production) or a warning
 * (works, but weaker than it should be). Run it with `php artisan app:check`;
 * the production containers run it when they start and refuse to start on a
 * failure.
 */
class ProductionCheck
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** What the ML token is when nobody has set it, in the config and in the compose files. */
    public const DEFAULT_ML_TOKEN = 'change-me-dev-token';

    /** Database passwords that appear in the examples, or that anyone would try first. */
    private const KNOWN_PASSWORDS = ['', 'secret', 'password', 'postgres', 'stocksense', 'root', 'admin', '123456'];

    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<array{status: string, check: string, advice: string}>
     */
    public function run(): array
    {
        $secureCookie = (bool) $this->config->get('session.secure');
        $https = Str::startsWith((string) $this->config->get('app.url'), 'https://');
        $key = (string) $this->config->get('app.key');
        $token = (string) $this->config->get('forecasting.ml.token');
        $password = (string) $this->config->get('database.connections.'.$this->config->get('database.default').'.password');

        return [
            $this->check('The environment is "production"', $this->config->get('app.env') === 'production', self::FAIL, 'Set APP_ENV=production.'),
            $this->check('Debug output is off', ! $this->config->get('app.debug'), self::FAIL, 'Set APP_DEBUG=false. Debug pages show code, file paths and settings to anyone who triggers an error.'),
            $this->check('The application key is set', $this->keyWorks($key), self::FAIL, 'Set APP_KEY to base64: followed by 32 random bytes, base64 encoded (`php artisan key:generate --show` makes one). It signs sessions and encrypts cookies, and the application cannot start with a key of any other length.'),
            $this->check('The ML service token is not the development one', $token !== '' && $token !== self::DEFAULT_ML_TOKEN && strlen($token) >= 24, self::FAIL, 'Set ML_INTERNAL_TOKEN to a long random value (at least 24 characters), the same on the app and the ML service.'),
            $this->check('The database password is not a well-known one', ! in_array(mb_strtolower($password), self::KNOWN_PASSWORDS, true) && strlen($password) >= 12, self::FAIL, 'Set DB_PASSWORD to a long random value (at least 12 characters).'),
            $this->check('Inertia DevTools recording is off', ! $this->config->get('inertia.devtools.enabled'), self::FAIL, 'Remove INERTIA_DEVTOOLS_ENABLED (or set it to false). It records the data of every page to disk.'),
            $this->check('Demo accounts cannot be created', ! $this->config->get('demo.allowed'), self::FAIL, 'Remove ALLOW_DEMO_DATA. With it, anyone who can run commands can create an Owner account whose password is in the README.'),
            $this->check('Session cookies are not readable by scripts', (bool) $this->config->get('session.http_only'), self::FAIL, 'Set SESSION_HTTP_ONLY=true.'),
            $this->check('Session cookies stay on the same site', in_array($this->config->get('session.same_site'), ['lax', 'strict'], true), self::WARN, 'Set SESSION_SAME_SITE=lax (or strict).'),
            $this->check('The site address is HTTPS', $https, self::WARN, 'Serve the site over HTTPS (a reverse proxy in front of this stack) and set APP_URL=https://... Without it, passwords and sessions cross the network in the clear.'),
            $this->check('Session cookies are only sent over HTTPS', $secureCookie, self::WARN, 'Set SESSION_SECURE_COOKIE=true once the site is served over HTTPS.'),
            $this->check('The Content-Security-Policy is on', (bool) $this->config->get('security.csp'), self::WARN, 'Set SECURITY_CSP=true.'),
            $this->check('Email goes somewhere real', ! in_array($this->config->get('mail.default'), ['log', 'array'], true) && $this->config->get('mail.mailers.smtp.host') !== 'mailpit', self::WARN, 'Set MAIL_MAILER, MAIL_HOST and the rest to a real SMTP service. Until then, nobody receives set-up links or alerts.'),
            $this->check('Background jobs run on a queue', $this->config->get('queue.default') !== 'sync', self::WARN, 'Set QUEUE_CONNECTION=redis, so imports, forecasts and emails do not run inside web requests.'),
            $this->check('Cache and sessions are shared, not per process', ! in_array($this->config->get('cache.default'), ['array', 'null'], true) && $this->config->get('session.driver') !== 'array', self::WARN, 'Use redis or the database for CACHE_STORE and SESSION_DRIVER.'),
        ];
    }

    /**
     * Whether the key can actually be used: Laravel needs exactly the length the cipher takes (32 bytes for
     * the default AES-256), and fails on every request otherwise, so "long enough" is not enough.
     */
    private function keyWorks(string $key): bool
    {
        $bytes = Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:'), true) : false;

        return $bytes !== false && Encrypter::supported($bytes, (string) $this->config->get('app.cipher'));
    }

    /**
     * Whether anything must be fixed before this goes to production.
     *
     * @param  list<array{status: string, check: string, advice: string}>  $results
     */
    public static function hasFailures(array $results): bool
    {
        return collect($results)->contains(fn (array $result) => $result['status'] === self::FAIL);
    }

    /**
     * @return array{status: string, check: string, advice: string}
     */
    private function check(string $name, bool $ok, string $otherwise, string $advice): array
    {
        return ['status' => $ok ? self::PASS : $otherwise, 'check' => $name, 'advice' => $ok ? '' : $advice];
    }
}
