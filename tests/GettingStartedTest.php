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
    $twig->addFunction(tp_link_function());
    $twig->addFunction(new TwigFunction('w4os_grid_name', fn () => $given['grid']));
    $twig->addFunction(new TwigFunction('w4os_login_link', fn () => Site::loginLink($given['uri'])));
    $twig->addFunction(new TwigFunction('w4os_profile_page', fn () => '/profile/'.str_replace(' ', '.', (string) ($given['avatar']['name'] ?? ''))));
    $twig->addFunction(new TwigFunction('w4os_avatar', fn () => $given['avatar']));
    $twig->addFunction(new TwigFunction('w4os_start_steps', fn () => $given['steps']));
    $twig->addFunction(new TwigFunction('w4os_route', fn (string $type) => $given['routes'][$type] ?? null));
    // The texts of a site as w4os_text gives them, HTML here, from a /fr page
    $twig->addFilter(new TwigFilter('w4os_text', fn (?string $text) => trim((string) $text) === '' ? '' : Site::localLinks((string) $text, Site::siteLinks('', '/fr', ['en', 'fr'], '/aide')), ['is_safe' => ['html']]));
    $twig->addFunction(link_function());
    $twig->addFunction(new TwigFunction('w4os_anchor', fn (string $href, string $text, string $kind = 'plain', string $class = '') => W4OS\Views\Link::html($href, $text, $kind, $class), ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('w4os_url', fn (?string $target, array $args = []) => W4OS\Grav\Site::siteLinks('', '', [], '/')->build((string) $target, $args)));
    $twig->addFilter(new TwigFilter('t', fn (string $key, ...$values) => $values === [] ? $key : $key.'('.implode(',', $values).')'));
    $assets = new class {
        public array $js = [];
        public function addJs(string $url): void { $this->js[] = $url; }
    };

    return html_entity_decode($twig->render($template, $context + ['assets' => $assets, 'config' => ['plugins' => ['login' => ['route' => '/login']]]]), ENT_QUOTES | ENT_HTML5);
}

describe('the steps of a newcomer', function () {
    test('the avatar to create first, then the grid to add to the viewer, logging in done once the avatar has been in-world', function () {
        expect(array_column(Site::steps(false, null), 'state'))->toBe(['next', 'todo', 'todo'])
            ->and(array_column(Site::steps(true, false), 'state'))->toBe(['done', 'next', 'todo'])
            ->and(array_column(Site::steps(true, null), 'state'))->toBe(['done', 'next', 'todo'])
            ->and(array_column(Site::steps(true, true), 'state'))->toBe(['done', 'done', 'done'])
            ->and(array_column(Site::steps(true, true), 'step'))->toBe(['avatar', 'viewer', 'login'])
            // Registration closed: no avatar to create for a visitor (the managers create them), the one signed in keeps its step
            ->and(Site::steps(false, null, false))->toBe([['step' => 'viewer', 'state' => 'next'], ['step' => 'login', 'state' => 'todo']])
            ->and(array_column(Site::steps(true, false, false), 'step'))->toBe(['avatar', 'viewer', 'login']);
    });

    test('stand out the one to do now: the avatar to create, a button alone; the grid to add, its button and its login URI; log in, a button alone', function () {
        $links = ['hop' => 'hop:///app/gridmanager/addgrid/grid.example.org%3A8002', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/grid.example.org%3A8002'];
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(false, null), 'uri' => 'grid.example.org:8002', 'links' => $links]);

        expect($html)->toContain('<h2 class="w4os-start-title">w4os.start_title</h2>', 'class="w4os-start-step w4os-start-avatar is-next" aria-current="step"')
            ->and($html)->toContain('<a class="btn btn-primary" href="/register">w4os.start_avatar</a>')
            ->and($html)->toContain('<a class="btn btn-ghost w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/grid.example.org%3A8002">w4os.start_add_grid</a>')
            // Under its button that adds the grid, its login URI to copy, nothing else
            ->and($html)->toContain('<p class="w4os-start-uri"><span class="w4os-uri-field"><span class="w4os-tp" data-w4os-tp data-client="browser"><span class="w4os-tp-main"><code class="w4os-login-uri">grid.example.org:8002</code></span>'."\u{2060}".'<button type="button" class="w4os-tp-action w4os-tp-copy"')
            ->and(explode('<p class="w4os-start-uri">', $html)[1])->not->toContain('w4os-tp-toggle', 'addgrid/grid')
            ->and($html)->not->toContain('start_viewer_note', 'firestormviewer')
            ->and($html)->toContain('<a class="btn btn-ghost w4os-login-button" href="secondlife:///app/login?grid=grid.example.org%3A8002">w4os.start_login</a>')
            ->and(substr_count($html, '<h3'))->toBe(0)
            ->and($html)->not->toContain('w4os.start_next', 'w4os.log_in', 'secondlife:///app/gridmanager');
    });

    test('mark the avatar done with its name, a link to its profile, the grid to add next', function () {
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(true, false), 'avatar' => ['name' => 'Ada Love'], 'uri' => 'grid.example.org:8002',
            'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]);

        expect($html)->toContain('w4os-start-avatar is-done', '<span class="visually-hidden">w4os.start_done</span>', '<a href="/profile/Ada.Love">w4os.start_avatar_done(Ada Love)</a>', 'w4os-start-viewer is-next', 'w4os-start-login is-todo')
            ->and($html)->toContain('<a class="btn btn-primary w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/x">w4os.start_add_grid</a>')
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
    test('its button (hop), the other link (secondlife), and the login URI with a quiet icon at its end that copies it (Copy its label), shown only where the browser has a clipboard (https)', function () {
        $html = render_start('w4os/add-grid.html.twig', ['uri' => 'grid.example.org:8002',
            'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]);

        expect($html)->toContain('<a class="btn btn-primary w4os-add-grid-button" href="hop:///app/gridmanager/addgrid/x">w4os.viewer_add_grid(Demo World)</a>')
            ->and($html)->toContain('href="secondlife:///app/gridmanager/addgrid/x">w4os.viewer_add_grid_other</a>', 'w4os.viewer_login_uri_by_hand')
            // The login URI a link of its own (the links the engine gives): adding the grid, its exact address to copy, nothing else
            ->and($html)->toContain('<span class="w4os-uri-field"><span class="w4os-tp" data-w4os-tp data-client="browser"><a class="w4os-tp-main" href="hop:///app/gridmanager/addgrid/grid.example.org%3A8002"', '><code class="w4os-login-uri">grid.example.org:8002</code></a>', 'data-w4os-tp-copy="grid.example.org:8002"')
            ->and(explode('w4os-uri-field', $html)[1])->not->toContain('hop://grid.example.org')
            ->and(dirname(__DIR__).'/js/copy.js')->not->toBeFile();
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
            ->and(array_column(Site::SHORTCODES[array_search('[getting-started]', array_column(Site::SHORTCODES, 'code'), true)]['options'], 'option'))->toBe(['title', 'content', 'footer'])
            ->and(array_column(Site::SHORTCODES[array_search('[login-uri]', array_column(Site::SHORTCODES, 'code'), true)]['options'], 'option'))->toBe(['add-grid']);
    });

    test('keeps its links inline in a sidebar, whose list links a theme may make blocks; side by side, the content of each step in its middle; the login URI a link', function () {
        expect((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->toContain('.w4os-uri-field .w4os-login-uri { color: inherit; }', '.w4os-start-step:has([aria-expanded="true"]) { opacity: 1; z-index: 1; }', '.w4os-start-step a { display: inline-block; }', 'justify-content: center; align-items: flex-start;', '.w4os-start-title::before { display: none; }');
    });

    test('takes the texts of a site above and under its steps, a link to a path of the site in the language of the page, nothing when empty', function () {
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(false, null), 'routes' => []],
            ['start_content' => '<p>Three steps</p>', 'start_footer' => '<p><a href="/aide">Aide complète</a></p>']);

        expect($html)->toContain('<div class="w4os-start-content"><p>Three steps</p></div>', '<div class="w4os-start-footer"><p><a href="/fr/aide">Aide complète</a></p></div>')
            ->and(strpos($html, 'w4os-start-title'))->toBeLessThan(strpos($html, 'w4os-start-content'))
            ->and(strpos($html, 'w4os-start-content'))->toBeLessThan(strpos($html, 'w4os-start-steps'))
            ->and(strpos($html, 'w4os-start-steps'))->toBeLessThan(strpos($html, 'w4os-start-footer'))
            ->and(render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(false, null), 'routes' => []]))->not->toContain('w4os-start-content', 'w4os-start-footer')
            ->and(Site::localLinks('<a href="/fr/aide">a</a> <a href="https://example.org/x">b</a> <a href="//cdn.example.org/y">c</a>', Site::siteLinks('', '/fr', ['en', 'fr'], '/')))
            ->toBe('<a href="/fr/aide">a</a> <a href="https://example.org/x">b</a> <a href="//cdn.example.org/y">c</a>');
    });

    test('on the home page, has the texts the page gives it (start_title, start_content, start_footer)', function () {
        $home = (string) file_get_contents(dirname(__DIR__).'/templates/w4os/home.html.twig');

        expect($home)->toContain("start_title: start_title ?? grav.page.header.start_title ?? ''", "start_content: start_content ?? grav.page.header.start_content ?? ''", "start_footer: start_footer ?? grav.page.header.start_footer ?? ''")
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("'start_content' => trim((string) \$shortcode->getParameter('content', ''))", "'start_footer' => trim((string) \$shortcode->getParameter('footer', ''))");
    });

    test('prints the texts of a site as HTML where Twig escapes what it prints, as Grav does', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'), ['autoescape' => 'html']);
        $twig->addFunction(link_function('/fr'));
        $twig->addFunction(new TwigFunction('w4os_start_steps', fn () => Site::steps(false, null)));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn (string $key) => $key));
        $twig->addFilter(new TwigFilter('w4os_text', function (?string $text, bool $more = false) {
            $html = trim((string) $text) === '' ? '' : Site::localLinks('<p>'.$text.'</p>', Site::siteLinks('', '/fr', ['en', 'fr'], '/'));

            return $more ? Site::moreLink($html) : $html;
        }, ['is_safe' => ['html']]));
        $html = $twig->render('w4os/getting-started.html.twig', ['start_content' => 'Three steps', 'start_footer' => '<a href="/aide">Aide détaillée</a>']);

        expect($html)->toContain('<div class="w4os-start-footer"><p><a class="w4os-more" href="/fr/aide">Aide détaillée</a></p></div>', '<div class="w4os-start-content"><p>Three steps</p></div>')
            ->and($html)->not->toContain('&lt;p&gt;')
            // Its buttons built by the views' Link (w4os_link): the address in the language of the page, the class of their kind
            ->and($html)->toContain('<a class="btn btn-primary" href="/fr/login">w4os.log_in</a>')
            ->and($twig->render('w4os/getting-started.html.twig', ['start_footer' => '  ']))->not->toContain('w4os-start-footer');
    });

    test('makes a footer that is one link alone a link to more, at the right with a chevron, like All news and See all', function () {
        expect(Site::moreLink('<p><a href="/fr/aide">Aide détaillée</a></p>'))->toBe('<p><a class="w4os-more" href="/fr/aide">Aide détaillée</a></p>')
            ->and(Site::moreLink('<p><a class="w4os-more" href="/x">X</a></p>'))->toBe('<p><a class="w4os-more" href="/x">X</a></p>')
            ->and(Site::moreLink('<p>See <a href="/help">the help</a></p>'))->toBe('<p>See <a href="/help">the help</a></p>')
            ->and(Site::moreLink('<p><a href="/a">A</a> <a href="/b">B</a></p>'))->toBe('<p><a href="/a">A</a> <a href="/b">B</a></p>')
            ->and(Site::moreLink(''))->toBe('');
        foreach (['css/w4os.css', 'css/admin.css'] as $css) {
            expect((string) file_get_contents(dirname(__DIR__)."/$css"))->toContain('.w4os-more { display: block; margin-top: .6rem; text-align: right; font-weight: 600; }', '.w4os-more::before { content: "\\203A\\00A0"; }');
        }
        expect((string) file_get_contents(dirname(__DIR__).'/templates/w4os/dashboard.html.twig'))->toContain("<div class=\"card-footer text-end\">{{ w4os_link(card.url, 'w4os.see_all'|t, 'more') }}</div>");
    });

    test('for an avatar that has been in the world: signed in as its name, the login URI without the button that adds the grid, Enjoy! to log in', function () {
        $html = render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(true, true), 'avatar' => ['name' => 'Way Forest'], 'uri' => 'grid.example.org:8002',
            'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]);

        expect($html)->toContain('w4os.start_avatar_done(Way Forest)', '<code class="w4os-login-uri">grid.example.org:8002</code>', '<a class="btn btn-ghost w4os-login-button" href="secondlife:///app/login?grid=grid.example.org%3A8002">w4os.start_login_done</a>')
            ->and($html)->not->toContain('w4os-add-grid-button', 'w4os.start_add_grid', '>w4os.start_login<')
            // Signed in, not yet in the world: the button that adds the grid, Log in
            ->and(render_start('w4os/getting-started.html.twig', ['steps' => Site::steps(true, false), 'avatar' => ['name' => 'Way Forest'], 'uri' => 'grid.example.org:8002',
                'links' => ['hop' => 'hop:///app/gridmanager/addgrid/x', 'secondlife' => 'secondlife:///app/gridmanager/addgrid/x']]))->toContain('w4os-add-grid-button', '>w4os.start_login</a>');
    });
});
