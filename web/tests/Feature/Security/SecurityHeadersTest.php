<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('on every page', function () {
    it('keeps the page from being framed, sniffed or leaked', function () {
        $response = $this->get(route('login'));

        expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=(), payment=()');
    });

    it('does the same for signed-in pages and for data requests', function () {
        $this->actingAs(User::factory()->owner()->create());

        foreach ([$this->get(route('dashboard')), $this->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])] as $response) {
            expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        }
    });

    it('does the same for downloads, redirects and refusals', function () {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->inventoryStaff()->create();

        $download = $this->actingAs($owner)->get(route('reports.export', ['sales', 'xlsx']));
        $refused = $this->actingAs($staff)->get(route('reports.show', 'sales'));
        $redirect = $this->post(route('logout'));

        foreach ([$download, $refused, $redirect] as $response) {
            expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
        }
    });

    it('sets each header once', function () {
        $response = $this->get(route('login'));

        expect($response->headers->all('X-Frame-Options'))->toHaveCount(1)
            ->and($response->headers->all('X-Content-Type-Options'))->toHaveCount(1);
    });
});

describe('the content security policy', function () {
    /** The nonce in a policy header. */
    function nonceIn(string $policy): string
    {
        preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $found);

        return $found[1] ?? '';
    }

    it('is off outside production, where the Vite dev server needs to load scripts from elsewhere', function () {
        expect($this->get(route('login'))->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('is on when enabled, and allows scripts only from the site itself and tags carrying its nonce', function () {
        config(['security.csp' => true]);

        $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');

        expect($policy)->toStartWith("default-src 'self'; script-src 'self' 'nonce-")
            ->and(strlen(nonceIn($policy)))->toBeGreaterThanOrEqual(20)
            ->and($policy)->not->toContain('unsafe-eval')
            ->and($policy)->not->toMatch('/script-src[^;]*unsafe-inline/')
            ->and($policy)->toContain("object-src 'none'")
            ->and($policy)->toContain("frame-ancestors 'self'")
            ->and($policy)->toContain("form-action 'self'")
            ->and($policy)->toContain("base-uri 'self'");
    });

    it('puts the same nonce on the inline script the page writes itself, so that one runs and an injected one does not', function () {
        config(['security.csp' => true]);

        $response = $this->get(route('login'));
        $nonce = nonceIn($response->headers->get('Content-Security-Policy'));

        $response->assertSee('<script nonce="'.$nonce.'">', false);

        // Every <script> that runs code (not data) on the page carries it.
        preg_match_all('/<script(?![^>]*type="application\/json")[^>]*>/', $response->getContent(), $tags);

        foreach ($tags[0] as $tag) {
            expect($tag)->toContain('nonce="'.$nonce.'"');
        }
    });

    it('makes a new nonce for every response', function () {
        config(['security.csp' => true]);

        $first = nonceIn($this->get(route('login'))->headers->get('Content-Security-Policy'));
        $second = nonceIn($this->get(route('login'))->headers->get('Content-Security-Policy'));

        expect($first)->not->toBe('')->and($second)->not->toBe('')->and($first)->not->toBe($second);
    });

    it('lets styles be inline, and nothing else be', function () {
        $directives = collect(explode(';', SecurityHeaders::policy('abc')))->map(fn ($d) => trim($d));

        expect($directives->contains("style-src 'self' 'unsafe-inline'"))->toBeTrue()
            ->and($directives->filter(fn ($d) => str_contains($d, 'unsafe-inline'))->count())->toBe(1);
    });

    it('is on by default in production and off by default elsewhere', function () {
        $config = require base_path('config/security.php');

        expect($config['csp'])->toBeFalse()->and($config['hsts'])->toBeFalse();   // this run is the testing environment
    });
});

describe('strict transport security', function () {
    it('is sent only on secure requests, and only when enabled', function () {
        config(['security.hsts' => true]);

        expect($this->call('GET', 'http://localhost/login')->headers->get('Strict-Transport-Security'))->toBeNull()
            ->and($this->call('GET', 'https://localhost/login')->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
    });

    it('is not sent when disabled, even over HTTPS', function () {
        config(['security.hsts' => false]);

        expect($this->call('GET', 'https://localhost/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
    });
});

describe('behind a proxy that ends HTTPS', function () {
    it('believes what a trusted proxy says about the request', function () {
        config(['trustedproxy.proxies' => '*', 'security.hsts' => true]);

        $response = $this->call('GET', 'http://localhost/login', server: ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);

        expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
    });

    it('does not believe a visitor who is not a trusted proxy', function () {
        config(['trustedproxy.proxies' => ['10.0.0.1'], 'security.hsts' => true]);

        $response = $this->call('GET', 'http://localhost/login', server: ['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '198.51.100.7']);

        expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('is read from the environment as a list or as everything', function () {
        putenv('TRUSTED_PROXIES=10.0.0.1, 10.0.0.2');
        expect((require base_path('config/trustedproxy.php'))['proxies'])->toBe(['10.0.0.1', '10.0.0.2']);

        putenv('TRUSTED_PROXIES=*');
        expect((require base_path('config/trustedproxy.php'))['proxies'])->toBe('*');

        putenv('TRUSTED_PROXIES=');
        expect((require base_path('config/trustedproxy.php'))['proxies'])->toBeNull();

        putenv('TRUSTED_PROXIES');
    });
});
