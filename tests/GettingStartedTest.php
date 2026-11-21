<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Site;

/** A template of the plugin with the grid, the visitor and the steps given, the texts as their keys and their values, its entities decoded. */
function render_start(string $template, array $given, array $context = []): string
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $given += ['links' => null, 'uri' => '', 'grid' => 'Demo World', 'avatar' => [], 'steps' => [], 'routes' => ['register' => '/register']];
    $twig->addFunction(new TwigFunction('w4os_add_grid', fn () => $given['links']));
    $twig->addFunction(new TwigFunction('w4os_login_uri', fn () => $given['uri']));
    $twig->addFunction(new TwigFunction('w4os_grid_name', fn () => $given['grid']));
    $twig->addFunction(new TwigFunction('w4os_login_link', fn () => Site::loginLink($given['uri'])));
    $twig->addFunction(new TwigFunction('w4os_profile_page', fn () => '/profile/'.str_replace(' ', '.', (string) ($given['avatar']['name'] ?? ''))));
    $twig->addFunction(new TwigFunction('w4os_avatar', fn () => $given['avatar']));
    $twig->addFunction(new TwigFunction('w4os_start_steps', fn () => $given['steps']));
    $twig->addFunction(new TwigFunction('w4os_route', fn (string $type) => $given['routes'][$type] ?? null));
    $twig->addFilter(new TwigFilter('t', fn (string $key, ...$values) => $values === [] ? $key : $key.'('.implode(',', $values).')'));
    $assets = new class {
        public array $js = [];
        public function addJs(string $url): void { $this->js[] = $url; }
    };

    return html_entity_decode($twig->render($template, $context + ['assets' => $assets, 'base_url_relative' => '', 'config' => ['plugins' => ['login' => ['route' => '/login']]]]), ENT_QUOTES | ENT_HTML5);
}

describe('the steps of a newcomer', function () {
    test('the avatar to create first, then the grid to add to the viewer, logging in done once the avatar has been in-world', function () {
        expect(array_column(Site::steps(false, null), 'state'))->toBe(['next', 'todo', 'todo'])
            ->and(array_column(Site::steps(true, false), 'state'))->toBe(['done', 'next', 'todo'])
            ->and(array_column(Site::steps(true, null), 'state'))->toBe(['done', 'next', 'todo'])
            ->and(array_column(Site::steps(true, true), 'state'))->toBe(['done', 'done', 'done'])
            ->and(array_column(Site::steps(true, true), 'step'))->toBe(['avatar', 'viewer', 'login']);
    });

    test('stand out the one to do now: the avatar to create, a button alone; the grid to add, its button and its login URI; log in, a button alone', function () {
        $links = ['hop' => 'hop:///app/gridmanager/addgrid/grid.example.org%3A8002', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/grid.example.org%3A8002'];
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(false, null), 'uri' => 'grid.example.org:8002', 'links' => $links]);

        expect($html)->toContain('<h2 class="w4os-start-title">w4os.start_title</h2>', 'class="w4os-start-step w4os-start-avatar is-next" aria-current="step"')
            ->and($html)->toContain('<a class="btn btn-primary" href="/register">w4os.start_avatar</a>')
            ->and($html)->toContain('<h3 class="w4os-start-step-title">w4os.start_viewer</h3>', '<a class="btn btn-ghost w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/grid.example.org%3A8002">w4os.start_add_grid(Demo World)</a>')
            ->and($html)->toContain('<code class="w4os-login-uri">grid.example.org:8002</code>')
            ->and($html)->not->toContain('start_viewer_note', 'firestormviewer')
            ->and($html)->toContain('<a class="btn btn-ghost w4os-login-button" href="secondlife:///app/login?grid=grid.example.org%3A8002">w4os.start_login</a>')
            ->and(substr_count($html, '<h3'))->toBe(1)
            ->and($html)->not->toContain('w4os.start_next', 'w4os.log_in', 'secondlife:///app/gridmanager');
    });

    test('mark the avatar done with its name, a link to its profile, the grid to add next', function () {
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(true, false), 'avatar' => ['name' => 'Ada Love'], 'uri' => 'grid.example.org:8002',
            'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]);

        expect($html)->toContain('w4os-start-avatar is-done', '<span class="visually-hidden">w4os.start_done</span>', '<a href="/profile/Ada.Love">w4os.start_avatar_done(Ada Love)</a>', 'w4os-start-viewer is-next', 'w4os-start-login is-todo')
            ->and($html)->toContain('<a class="btn btn-primary w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/x">w4os.start_add_grid(Demo World)</a>')
            ->and($html)->not->toContain('w4os.start_avatar<');
    });

    test('the sign-in in place of the avatar to create while registration is closed, the title a site gives', function () {
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(false, null), 'routes' => []], ['start_title' => 'Try it']);

        expect($html)->toContain('<h2 class="w4os-start-title">Try it</h2>', '<a class="btn btn-primary" href="/login">w4os.log_in</a>')
            ->and($html)->not->toContain('w4os.start_avatar<', 'w4os-login-button', 'w4os-login-uri');
    });

    test('the link that opens the viewer on its login with the grid chosen, from the login URI', function () {
        expect(Site::loginLink('grid.example.org:8002'))->toBe('secondlife:///app/login?grid=grid.example.org%3A8002')
            ->and(Site::loginLink(''))->toBeNull();
    });
});

describe('the grid added to a viewer', function () {
    test('its button (hop), the other link (secondlife), and the login URI with a button that copies it, shown only where the browser has a clipboard (https)', function () {
        $html = render_start('w4os/add-grid.html.twig', ['uri' => 'grid.example.org:8002',
            'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]);

        expect($html)->toContain('<a class="btn btn-primary w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/x">w4os.viewer_add_grid(Demo World)</a>')
            ->and($html)->toContain('href="secondlife:///app/gridmanager/addgrid/x">w4os.viewer_add_grid_other</a>', 'w4os.viewer_login_uri_by_hand')
            ->and($html)->toContain('<code class="w4os-login-uri">grid.example.org:8002</code>', 'w4os-copy" hidden data-w4os-copy="grid.example.org:8002"', 'data-w4os-copied="w4os.copied"')
            ->and((string) file_get_contents(dirname(__DIR__).'/js/copy.js'))->toContain('if (navigator.clipboard) {', 'button.hidden = false');
    });

    test('the login URI alone when the engine gives no link, nothing when it gives neither', function () {
        expect(render_start('w4os/add-grid.html.twig', ['uri' => 'grid.example.org:8002']))->toContain('w4os-login-uri')->not->toContain('w4os-add-grid-button', 'by_hand')
            ->and(trim(render_start('w4os/add-grid.html.twig', [])))->toBe('');
    });

    test('is read from the grid info of the engine (addGrid), null when it has none', function () {
        $links = ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x'];

        expect(fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['addGrid' => $links]]]]], $log)->addGrid())->toBe($links)
            ->and(fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['addGrid' => null]]]]], $log)->addGrid())->toBeNull();
    });

    test('shows on the profile of the avatar signed in (the steps while one is left) and on the page of the email to confirm', function () {
        $account = (string) file_get_contents(dirname(__DIR__).'/templates/w4os/account.html.twig');

        expect($account)->toContain("include 'w4os/getting-started.html.twig'", "include 'w4os/add-grid.html.twig'")
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/confirm.html.twig'))->toContain("include 'w4os/add-grid.html.twig'")
            ->and(array_column(Site::SHORTCODES, 'code'))->toContain('[login-uri]', '[getting-started]')
            ->and(array_column(Site::SHORTCODES[array_search('[getting-started]', array_column(Site::SHORTCODES, 'code'), true)]['options'], 'option'))->toBe(['title'])
            ->and(array_column(Site::SHORTCODES[array_search('[login-uri]', array_column(Site::SHORTCODES, 'code'), true)]['options'], 'option'))->toBe(['add-grid']);
    });

    test('keeps its links inline in a sidebar, whose list links a theme may make blocks; side by side, the content of each step in its middle', function () {
        expect((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->toContain('.w4os-start-step a { display: inline-block; }', 'justify-content: center; align-items: flex-start;', '.w4os-start-step-title { margin: 0 0 .5rem; font: inherit; font-weight: 600; letter-spacing: normal; }');
    });
});
