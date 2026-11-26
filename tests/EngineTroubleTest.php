<?php

use W4OS\Grav\Engine;
use W4OS\Grav\FormError;
use W4OS\Grav\Forms;
use W4OS\Grav\Site;

/** What a sign-in through an engine of these answers throws: the reason the site gives (Site::trouble()), null for none. */
function sign_in_trouble(Engine $engine): ?string
{
    try {
        (new Forms($engine, [], fn () => ''))->signIn('Ada Love', 'secret');
    } catch (FormError $e) {
        return Site::trouble($e) ?? $e->key;
    }

    return null;
}

describe('an engine the site cannot work with', function () {
    test('is its key refused or its API that does not answer, never a problem it answered nor a site not connected', function () {
        $unreachable = new Engine('https://grid.example.org/api/', 'osk_test', '', fn () => throw new RuntimeException('Connection refused'));

        expect(sign_in_trouble(fakeEngine([[401, null]])))->toBe('w4os.admin_engine_key_refused')
            ->and(sign_in_trouble(fakeEngine([[401, problem(401, 'The operation needs an API key', 'unauthorized')]])))->toBe('w4os.admin_engine_key_refused')
            // A web server or a PHP service in front of it: an error without a problem document, or no JSON at all
            ->and(sign_in_trouble(fakeEngine([[502, null]])))->toBe('w4os.admin_engine_down')
            ->and(sign_in_trouble(fakeEngine([[200, 'Bad gateway']])))->toBe('w4os.admin_engine_down')
            ->and(sign_in_trouble($unreachable))->toBe('w4os.admin_engine_down')
            // What the engine answered about what was asked: the visitor's own problem
            ->and(sign_in_trouble(fakeEngine([[401, problem(401, 'Wrong name or password', 'sign-in-refused')]])))->toBe('w4os.error_sign_in_refused')
            ->and(sign_in_trouble(fakeEngine([[503, problem(503, 'the grid has no files here', 'channel-unavailable')]])))->toBe('w4os.error_channel_unavailable')
            ->and(sign_in_trouble(fakeEngine([], key: '')))->toBe('w4os.error_channel_unavailable');
    });

    test('is said on Health instead of the engine\'s words, and on the dashboard, with a link to Health', function () {
        $failed = fn () => throw new W4OS\Grav\EngineException(401, 'Error 401');
        $said = fn (Throwable $e) => Site::trouble($e) ?? $e->getMessage();

        expect(Site::healthReport($failed, true, $said))->toBe(['health' => null, 'error' => 'w4os.admin_engine_key_refused', 'refreshError' => 'w4os.admin_engine_key_refused'])
            ->and(Site::healthReport($failed, false)['error'])->toBe('Error 401');

        $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new Twig\TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new Twig\TwigFilter($name, fn ($value) => $value));
        $twig->addFilter(new Twig\TwigFilter('t', fn ($key) => "[$key]"));
        $twig->addFunction(new Twig\TwigFunction('w4os_health_problems', fn () => ['count' => 0, 'warnings' => 0, 'labels' => [], 'url' => '/w4os/health',
            'trouble' => 'The engine refuses the key of the site: make a new one']));

        expect($twig->render('w4os/dashboard.html.twig'))->toContain('<div class="alert alert-danger" role="alert">', 'The engine refuses the key of the site: make a new one', 'href="&#x2F;w4os&#x2F;health">[w4os.admin_health_title]</a>');
    });

    test('never breaks the admin pages that say it: the logo of the grid left out', function () {
        expect((string) file_get_contents(dirname(__DIR__).'/classes/Site.php'))->toContain("            try {
                \$logo = (string) (\$this->engine()->gridLogo() ?? '');
            } catch (EngineException) {
            }");
    });

    test('is said to the visitor signing in as the grid unreachable, its reason in the log', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($plugin)->toContain("\$this->site()->translate(\$trouble === null ? \$e->key : 'w4os.error_channel_unavailable')", "), 'error');")
            ->and($plugin)->toContain("\$this->grav['log']->error('w4os: sign-in of '.\$login.' impossible: '.\$trouble.' ('.\$e->getMessage().')')")
            ->and($plugin)->toContain("\$this->grav['language']->translate(array_merge([\$key], \$vars), ['en'])");
    });
});

describe('a sign-in form without the token of its session', function () {
    test('is said as an error, before the Login plugin says it as a success, the form shown again, why in the log', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($plugin)->toContain("'onTask.login.login' => ['onTaskLogin', 10]", "Utils::verifyNonce((string) (\$_POST['login-form-nonce'] ?? ''), 'login-form')")
            ->and($plugin)->toContain("\$this->grav['messages']->add(htmlspecialchars(\$this->site()->translate('w4os.site_sign_in_expired')), 'error');")
            ->and($plugin)->toContain("\$this->grav['log']->warning('w4os: a sign-in form came back without the token of its session");
    });

});

describe('a browser that sends the cookie of the session more than once', function () {
    test('is found by the names of the sessions of the site only, each its ids in the order sent', function () {
        $header = 'grav-site-87d8ca0=old1; other=1; grav-site-87d8ca0=new2; grav-site-87d8ca0-admin=a; other=2';

        expect(Site::duplicateSessionCookies($header, 'grav-site'))->toBe(['grav-site-87d8ca0' => ['old1', 'new2']])
            ->and(Site::duplicateSessionCookies('grav-site-87d8ca0=one; other=1; other=2', 'grav-site'))->toBe([]);
    });

    test('reads the session the site keeps, else none', function () {
        $folder = sys_get_temp_dir().'/w4os-sessions-'.getmypid();
        @mkdir($folder);
        touch($folder.'/sess_new2');

        expect(Site::liveSession(['old1', 'new2'], $folder))->toBe('new2')
            ->and(Site::liveSession(['old1', '../x'], $folder))->toBeNull()
            ->and(Site::liveSession(['new2'], null))->toBeNull();
        unlink($folder.'/sess_new2');
        rmdir($folder);
    });

    test('has the others expired: host only and each domain above the host, at each path above the one asked, never the one of now', function () {
        $variants = Site::sessionCookieVariants('kimbe.red', '/opensim/fr/login', 'kimbe.red', '/opensim/');
        $paths = ['/', '/opensim', '/opensim/', '/opensim/fr', '/opensim/fr/', '/opensim/fr/login', '/opensim/fr/login/'];

        expect($variants)->toHaveCount(13)
            ->and($variants)->not->toContain(['domain' => 'kimbe.red', 'path' => '/opensim/'])
            ->and(array_column(array_filter($variants, fn ($v) => $v['domain'] === ''), 'path'))->toBe($paths)
            // A subdomain: the domains above it too; a leading dot the same domain
            ->and(array_values(array_unique(array_column(Site::sessionCookieVariants('www.kimbe.red', '/', '.www.kimbe.red', '/'), 'domain'))))->toBe(['', 'kimbe.red'])
            // An address takes no domain
            ->and(Site::sessionCookieVariants('192.0.2.7', '/w/', '', '/w/'))->toBe([['domain' => '', 'path' => '/'], ['domain' => '', 'path' => '/w']]);
    });

    test('is handled before Grav starts the session, the others expired once the cookie of now is set', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($plugin)->toContain('PluginsLoadedEvent::class => [\'onPluginsLoaded\', 0]', '$live === null || $_COOKIE[$name] = $live;', 'header_register_callback(function () use ($duplicates, $host, $requested) {');
    });
});
