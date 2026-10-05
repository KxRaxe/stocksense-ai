<?php

use App\Services\Security\ProductionCheck;

/**
 * A configuration that would pass every check, to spoil one setting at a time.
 *
 * @return array<string, mixed>
 */
function goodConfig(): array
{
    return [
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.cipher' => 'AES-256-CBC',
        'app.url' => 'https://shop.example.com',
        'forecasting.ml.token' => bin2hex(random_bytes(16)),
        'database.default' => 'pgsql',
        'database.connections.pgsql.password' => bin2hex(random_bytes(12)),
        'inertia.devtools.enabled' => false,
        'demo.allowed' => false,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.secure' => true,
        'session.driver' => 'redis',
        'cache.default' => 'redis',
        'queue.default' => 'redis',
        'security.csp' => true,
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.com',
    ];
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, array{status: string, advice: string}> By check name
 */
function checked(array $changes = []): array
{
    config([...goodConfig(), ...$changes]);

    return collect(app(ProductionCheck::class)->run())->keyBy('check')->all();
}

it('passes a sound production configuration', function () {
    $results = checked();

    expect(collect($results)->pluck('status')->unique()->all())->toBe(['pass'])
        ->and(collect($results)->pluck('advice')->filter()->all())->toBe([])
        ->and(ProductionCheck::hasFailures(array_values($results)))->toBeFalse();
});

it('has a check for each thing that can go wrong, with its own name', function () {
    $names = array_keys(checked());

    expect($names)->toHaveCount(count(array_unique($names)))->and(count($names))->toBeGreaterThanOrEqual(12);
});

it('fails what must never go to production', function (array $spoiled, string $check) {
    $results = checked($spoiled);

    expect($results[$check]['status'])->toBe('fail')
        ->and($results[$check]['advice'])->not->toBe('')
        ->and(ProductionCheck::hasFailures(array_values($results)))->toBeTrue();
})->with([
    'not production' => [['app.env' => 'local'], 'The environment is "production"'],
    'debug on' => [['app.debug' => true], 'Debug output is off'],
    'no key' => [['app.key' => ''], 'The application key is set'],
    'a key that is too short' => [['app.key' => 'base64:'.base64_encode('short')], 'The application key is set'],
    'a key that is long enough but not a length the cipher takes' => [['app.key' => 'base64:'.base64_encode(str_repeat('k', 40))], 'The application key is set'],
    'a key that is not base64' => [['app.key' => 'plain-text-key-that-is-long-enough-honest'], 'The application key is set'],
    'the development ML token' => [['forecasting.ml.token' => 'change-me-dev-token'], 'The ML service token is not the development one'],
    'no ML token' => [['forecasting.ml.token' => ''], 'The ML service token is not the development one'],
    'a short ML token' => [['forecasting.ml.token' => 'abc123'], 'The ML service token is not the development one'],
    'the example database password' => [['database.connections.pgsql.password' => 'secret'], 'The database password is not a well-known one'],
    'a well-known database password in other case' => [['database.connections.pgsql.password' => 'PASSWORD'], 'The database password is not a well-known one'],
    'no database password' => [['database.connections.pgsql.password' => ''], 'The database password is not a well-known one'],
    'a short database password' => [['database.connections.pgsql.password' => 'k3j5h7g9'], 'The database password is not a well-known one'],
    'DevTools recording on' => [['inertia.devtools.enabled' => true], 'Inertia DevTools recording is off'],
    'demo accounts allowed' => [['demo.allowed' => true], 'Demo accounts cannot be created'],
    'cookies readable by scripts' => [['session.http_only' => false], 'Session cookies are not readable by scripts'],
]);

it('warns about what works but is weaker than it should be', function (array $spoiled, string $check) {
    $results = checked($spoiled);

    expect($results[$check]['status'])->toBe('warn')
        ->and($results[$check]['advice'])->not->toBe('')
        ->and(ProductionCheck::hasFailures(array_values($results)))->toBeFalse();
})->with([
    'cookies that cross sites' => [['session.same_site' => 'none'], 'Session cookies stay on the same site'],
    'a plain HTTP address' => [['app.url' => 'http://shop.example.com'], 'The site address is HTTPS'],
    'cookies that travel over HTTP' => [['session.secure' => false], 'Session cookies are only sent over HTTPS'],
    'no content security policy' => [['security.csp' => false], 'The Content-Security-Policy is on'],
    'mail that goes to the log' => [['mail.default' => 'log'], 'Email goes somewhere real'],
    'mail that goes nowhere' => [['mail.default' => 'array'], 'Email goes somewhere real'],
    'mail that goes to the development catcher' => [['mail.mailers.smtp.host' => 'mailpit'], 'Email goes somewhere real'],
    'jobs that run in the request' => [['queue.default' => 'sync'], 'Background jobs run on a queue'],
    'a cache that forgets' => [['cache.default' => 'array'], 'Cache and sessions are shared, not per process'],
    'sessions that forget' => [['session.driver' => 'array'], 'Cache and sessions are shared, not per process'],
]);

it('accepts strict same-site cookies', function () {
    expect(checked(['session.same_site' => 'strict'])['Session cookies stay on the same site']['status'])->toBe('pass');
});

it('tells a failure from a warning when both are present', function () {
    $results = array_values(checked(['app.debug' => true, 'session.secure' => false]));

    expect(collect($results)->where('status', 'fail')->count())->toBe(1)
        ->and(collect($results)->where('status', 'warn')->count())->toBe(1)
        ->and(ProductionCheck::hasFailures($results))->toBeTrue();
});

describe('the command', function () {
    it('says all is well, and succeeds even in strict mode', function () {
        config(goodConfig());

        $this->artisan('app:check --strict')
            ->expectsOutputToContain('PASS  The environment is "production"')
            ->expectsOutputToContain('No failures, 0 warnings.')
            ->assertSuccessful();
    });

    it('lists what is wrong with advice on how to fix it', function () {
        config([...goodConfig(), 'app.debug' => true, 'session.secure' => false]);

        $this->artisan('app:check')
            ->expectsOutputToContain('FAIL  Debug output is off')
            ->expectsOutputToContain('Set APP_DEBUG=false.')
            ->expectsOutputToContain('WARN  Session cookies are only sent over HTTPS')
            ->expectsOutputToContain('1 must be fixed before this goes to production (1 warning besides).')
            ->assertSuccessful();   // a report, unless asked to be strict
    });

    it('refuses to succeed in strict mode when something must be fixed', function () {
        config([...goodConfig(), 'forecasting.ml.token' => 'change-me-dev-token']);

        $this->artisan('app:check --strict')->assertFailed();
    });

    it('lets warnings through in strict mode', function () {
        config([...goodConfig(), 'session.secure' => false, 'security.csp' => false]);

        $this->artisan('app:check --strict')->expectsOutputToContain('No failures, 2 warnings.')->assertSuccessful();
    });

    it('is run on a development setup, and says what would stop it going to production', function () {
        config(['app.env' => 'local', 'app.debug' => true, 'forecasting.ml.token' => 'change-me-dev-token']);

        $this->artisan('app:check --strict')->assertFailed();
    });
});
