<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The templates of the plugin alone, no theme: Grav's functions and the plugin's as stubs, the assets written as they are asked. */
function themeless_twig(bool $viewer = true): Environment
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    // A block with its title, none when the page asks it without (an empty title)
    $twig->addFunction(new TwigFunction('w4os_block', fn (string $name, array $args = []) => "<section class=\"block block-$name\">".(($args['title'] ?? null) === '' ? '' : '<h1 class="block-title">Bart Simpson</h1>').'</section>', ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('w4os_page_args', fn () => ['name' => 'Bart.Simpson']));
    $twig->addFunction(new TwigFunction('w4os_is_viewer', fn () => $viewer));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
    $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => $value));
    $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));

    return $twig;
}

/** The assets of a page as Grav's are asked: the files added, written where the template asks for them. */
final class FakeAssets
{
    public array $css = [];

    public function addCss(string $file): self
    {
        $this->css[] = $file;

        return $this;
    }

    public function css(): string
    {
        return implode('', array_map(fn ($file) => "<link href=\"$file\">", $this->css));
    }

    public function js(?string $group = null): string
    {
        return '';
    }
}

describe('themes', function () {
    test('a page of the plugin in a viewer is a page of its own, no theme needed: the helpers\' page style, compact, the block alone', function () {
        $html = themeless_twig()->render('w4os-viewer.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']], 'assets' => new FakeAssets()]);

        expect($html)->toStartWith('<!doctype html>')
            ->toContain('<body class="w4os-viewer viewer">', '<link href="plugin://w4os/vendor/w4os/w4os-helpers/assets/css/page.css">', '<link href="plugin://w4os/css/viewer.css">',
                '<div class="w4os w4os-page w4os-profile">', '<section class="block block-profile">')
            // The page is the theme there: it shows the title of the page, the block none
            ->and(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toContain('<h1>Bart Simpson</h1>')->not->toContain('block-title');
    });
});

describe('the sign-in page', function () {
    test('has "remember me" checked, whatever the theme draws around the form of the Login plugin', function () {
        $form = '<div class="form-input-wrapper"><input type="checkbox" value="1" name="rememberme" id="#rememberme"><label class="inline" for="#rememberme">Remember me</label></div>';

        expect(W4OS\Grav\Site::rememberChecked($form))->toContain('<input type="checkbox" value="1" name="rememberme" id="#rememberme" checked>')
            ->and(W4OS\Grav\Site::rememberChecked(W4OS\Grav\Site::rememberChecked($form)))->toBe(W4OS\Grav\Site::rememberChecked($form))
            ->and(W4OS\Grav\Site::rememberChecked('<p>No form</p>'))->toBe('<p>No form</p>')
            // A theme's own form (Mundana's: its classes first)
            ->and(W4OS\Grav\Site::rememberChecked('<input class="form-check-input" type="checkbox" value="1" name="rememberme" id="#rememberme">'))
            ->toBe('<input class="form-check-input" type="checkbox" value="1" name="rememberme" id="#rememberme" checked>');
    });
});

describe('the pages of the plugin', function () {
    test('show in the default page of any theme, the plugin filling their content; the admin pages keep their own layout', function () {
        foreach (glob(dirname(__DIR__).'/pages/*.md') as $file) {
            $header = Symfony\Component\Yaml\Yaml::parse(explode('---', file_get_contents($file))[1]);
            expect($header['template'])->toBe($header['template'] === 'w4os-admin' ? 'w4os-admin' : 'default', basename($file));
        }
        // No template of the plugin needs the blocks of a theme (Quark's header, content, footer...)
        foreach (glob(dirname(__DIR__).'/templates/*.twig') as $file) {
            expect(file_get_contents($file))->not->toContain("extends 'partials/base.html.twig'", basename($file));
        }
    });
});

describe('the sign-in page links', function () {
    test('come under the sign-in form, the Login plugin\'s or a theme\'s own', function () {
        $form = '<form method="post"><button type="submit" name="task" value="login.login">Log in</button></form>';
        $page = '<main><form id="search"></form><section id="grav-login">'.$form.'</section><section id="other"></section></main>';

        expect(W4OS\Grav\Site::withLoginLinks($page, '<p class="links"></p>'))->toBe('<main><form id="search"></form><section id="grav-login">'.$form.'<p class="links"></p></section><section id="other"></section></main>')
            ->and(W4OS\Grav\Site::withLoginLinks('<section style="height: 80vh">'.$form.'</section>', '<p class="links"></p>'))->toBe('<section style="height: 80vh">'.$form.'<p class="links"></p></section>')
            ->and(W4OS\Grav\Site::withLoginLinks('<p>No form</p>', '<p class="links"></p>'))->toBe('<p>No form</p>');
    });
});

describe('the title of a page of the plugin', function () {
    test('is the theme\'s to show: the content of the page has none, its block neither', function () {
        $html = themeless_twig(false)->render('w4os/page.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']]]);

        expect($html)->toContain('<section class="block block-profile">')->not->toContain('<h1')
            ->and(file_get_contents(dirname(__DIR__).'/templates/w4os/page.html.twig'))->not->toContain('page.title');
    });
});

describe('the actions of a page of the plugin', function () {
    test('are icons at the top right of its content: edit on a profile the visitor may change, the theme\'s text color', function () {
        $twig = themeless_twig(false);
        $twig->addFunction(new TwigFunction('w4os_profile_edit_url', fn () => '/account'));
        $html = $twig->render('w4os/page.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']]]);

        expect($html)->toContain('<div class="w4os-page-actions">', 'href="&#x2F;account"', 'aria-label="&#x5B;w4os.edit_profile&#x5D;"', 'title="&#x5B;w4os.edit_profile&#x5D;"', '<svg', 'stroke="currentColor"')
            ->and(strpos($html, 'w4os-page-actions'))->toBeLessThan(strpos($html, '<section class="block block-profile">'))
            ->and($html)->not->toContain('class="button"');
    });

    test('use the same icons in the lists of the admin: view and edit as icons of the row, its other actions in its menu', function () {
        $template = (string) file_get_contents(dirname(__DIR__).'/templates/w4os/list-cells.html.twig');

        expect($template)->toContain('ti ti-eye', 'ti ti-pencil')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/icons.html.twig'))->toContain('eye', 'pencil', 'logout');
    });
});

describe('the home page of a grid', function () {
    test('is content of any page (a shortcode), its text first, the blocks of the grid beside it only where there is room', function () {
        $twig = themeless_twig();
        $html = $twig->render('w4os/home.html.twig', ['content' => '<h1>Welcome</h1>']);

        expect($html)->toContain('<div class="w4os-home">', '<div class="w4os-home-layout">', '<section class="block block-grid-info">', '<section class="block block-motd">')
            ->and(strpos($html, '<h1>Welcome</h1>'))->toBeLessThan(strpos($html, 'block-grid-info'))
            ->and(strpos($html, 'block-popular-places'))->toBeLessThan(strpos($html, 'block-grid-info'))
            ->and(file_exists(dirname(__DIR__).'/templates/w4os/splash.html.twig'))->toBeFalse()
            ->and((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->toContain('@container');
    });

    test('has its main column a section, its blocks titled h2, its side columns asides, their blocks titled h3', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => $value));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $twig->addFunction(new TwigFunction('w4os_block', fn (string $name, array $args = []) => "<section class=\"block block-$name\"><".($args['level'] ?? 'h3')." class=\"block-title\">$name</".($args['level'] ?? 'h3').'></section>', ['is_safe' => ['html']]));
        $html = $twig->render('w4os/home.html.twig', ['content' => '<h1>Welcome</h1>']);

        expect($html)->toContain('<section class="w4os-home-main">', '<aside class="w4os-home-grid">', '<aside class="w4os-home-news">')
            ->and($html)->toContain('<h2 class="block-title">popular-places</h2>', '<h2 class="block-title">destination_guide</h2>')
            ->and($html)->toContain('<h3 class="block-title">grid-info</h3>', '<h3 class="block-title">grid-status</h3>', '<h3 class="block-title">motd</h3>');
    });

    test('has the Connect block under what the grid is, the last three news of the site under its message of the day, then a link to all of them', function () {
        $twig = themeless_twig();
        $post = fn (int $n) => new class($n) {
            public array $header = [];
            public string $content = '<p>Text of the post</p>';
            public string $url;
            public string $title;
            public int $date;
            public object $media;

            public function __construct(int $n)
            {
                [$this->url, $this->title, $this->date] = ["/news/post-$n", "Post $n", 1_700_000_000 + $n];
                $n === 2 && $this->content = '<p>'.str_repeat('Long text ', 20).'</p>';
                $this->media = new class { public array $images = []; };
            }
        };
        $news = new class([$post(1), $post(2), $post(3), $post(4)]) {
            public string $url = '/news';
            public string $title = 'News';

            public function __construct(private array $posts) {}

            public function collection(): array { return $this->posts; }
        };
        $twig->addFunction(new TwigFunction('w4os_home_news', fn () => $news));
        // Grav's truncate, its ellipsis &hellip; by default
        $twig->addFilter(new TwigFilter('truncate', fn (string $text, int $limit, bool $word = false, string $break = ' ', string $pad = '&hellip;') => mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).$pad));
        $raw = $twig->render('w4os/home.html.twig', ['content' => '']);
        $html = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        $grid = substr($html, (int) strpos($html, '<aside class="w4os-home-grid">'), (int) strpos($html, '<aside class="w4os-home-news">') - (int) strpos($html, '<aside class="w4os-home-grid">'));

        expect($grid)->toContain('<section class="w4os-start">', '<h3 class="w4os-start-title">')
            ->and(strpos($grid, 'block-grid-status'))->toBeLessThan(strpos($grid, 'w4os-start'))
            ->and($html)->toContain('<h3 class="w4os-latest-title">News</h3>', '<p class="w4os-latest-all"><a class="w4os-more" href="/news">[w4os.home_all_news]</a></p>', '<h4><a href="/news/post-1">Post 1</a></h4>', 'Post 3', 'Text of the post', '<time datetime="2023-11-14">')
            ->and($html)->not->toContain('Post 4')
            // The excerpt cut with an ellipsis written once, never escaped again (&amp;hellip;)
            ->and($raw)->toContain(str_repeat('Long text ', 14).'…')->not->toContain('hellip')
            ->and(themeless_twig()->render('w4os/home.html.twig', ['content' => '']))->not->toContain('w4os-latest');
    });

    test('takes the boxes of a theme in its asides, the theme extending it (@w4os) rather than copying it', function () {
        $loader = new FilesystemLoader(dirname(__DIR__).'/templates');
        $loader->addPath(dirname(__DIR__).'/templates', 'w4os');
        $twig = new Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['w4os/home.html.twig' => "{% extends '@w4os/w4os/home.html.twig' %}{% set start_title = 'Try it' %}{% block w4os_home_grid_extra %}<p>Support</p>{% endblock %}{% block w4os_home_news_before %}<p>Before</p>{% endblock %}{% block w4os_home_news_extra %}<p>Extra</p>{% endblock %}"]), $loader]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => $value));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/home.html.twig', ['content' => '']);

        expect($html)->toContain('<p>Support</p>', '<p>Before</p>', '<p>Extra</p>', '<aside class="w4os-home-grid">', '>Try it</h3>')
            // The texts of the Connect block from the front matter of the page, where the theme sets none
            ->and($twig->render('w4os/home.html.twig', ['content' => '', 'grav' => ['page' => ['header' => ['start_footer' => 'Full help']]]]))->toContain('<div class="w4os-start-footer">Full help</div>')
            // Between the message of the day and the news, in the order of the page (screen readers read it so)
            ->and(strpos($html, 'block-motd'))->toBeLessThan(strpos($html, '<p>Before</p>'))
            ->and(strpos($html, '<p>Before</p>'))->toBeLessThan(strpos($html, '<p>Extra</p>'))
            ->and(strpos($html, 'w4os-start'))->toBeLessThan(strpos($html, 'Support'))
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("addPath(__DIR__.'/templates', 'w4os')")
            ->and((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->toContain('grid-template-rows: auto 1fr;', '.w4os-home-news { align-self: start; }');
    });

    test('has the files of its blocks before the theme writes its head, as any page with blocks of the helpers in its text', function () {
        expect(W4OS\Grav\Site::shortcodeBlocks("# Hi\n[w4os-home]Text[/w4os-home]"))->toBe(['popular_places', 'destination_guide', 'grid_info', 'grid_status', 'motd'])
            ->and(W4OS\Grav\Site::shortcodeBlocks('[grid-status] and [avatar-profile name="A.B" /], [new-avatars]'))->toBe(['grid_status', 'profile', 'new_users'])
            ->and(W4OS\Grav\Site::shortcodeBlocks('[grid-statusbar] [/grid-info]'))->toBe([])
            ->and(W4OS\Grav\Site::shortcodeBlocks('No block'))->toBe([]);
    });
});

describe('the menus of the visitor', function () {
    test('open below their name on hover and on focus (the keyboard), shown as they are in the menu of a phone (.w4os-menus-open)', function () {
        $css = (string) file_get_contents(dirname(__DIR__).'/css/w4os.css');

        expect($css)->toContain('.w4os-user-menu:hover .w4os-user-list, .w4os-user-menu:focus-within .w4os-user-list { visibility: visible;')
            ->and($css)->toMatch('/\.w4os-menus-open \.w4os-user-list \{ position: static; visibility: visible;/')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/admin-menu.html.twig'))->toContain('<div class="w4os-user-menu w4os-admin-menu">', '<ul class="w4os-user-list">')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/user-menu.html.twig'))->toContain('<div class="w4os-user-menu">', '<ul class="w4os-user-list">');
    });
});

describe('a page of an earlier version', function () {
    test('laid out by the splash template of the plugin is the home page made content, nothing to change on an upgrade', function () {
        expect(W4OS\Grav\Site::isSplash('w4os/splash'))->toBeTrue()
            ->and(W4OS\Grav\Site::isSplash('default'))->toBeFalse()
            ->and(W4OS\Grav\Site::isSplash(null))->toBeFalse();
    });
});

describe('the styles of the plugin in the site', function () {
    test('lay out only: no colours of a theme (its variables) nor of their own, the theme\'s buttons and links as they are', function () {
        $css = (string) file_get_contents(dirname(__DIR__).'/css/w4os.css');

        expect($css)->not->toMatch('/--pico-|--q2-/')
            ->and($css)->not->toMatch('/#[0-9a-f]{3,8}\b|rgba?\(/i')
            ->and($css)->not->toMatch('/button[^{]*\{[^}]*(background|color|border)\s*:/');
        // A link is a link, never around a button (the Form plugin's button with an address does it: none of the plugin's has one)
        foreach (glob(dirname(__DIR__).'/templates/w4os/{page,account,home,post-actions,login-links}.html.twig', GLOB_BRACE) as $file) {
            expect((string) file_get_contents($file))->not->toMatch('/<a[^>]*>\s*<button/');
        }
        foreach (glob(dirname(__DIR__).'/pages/*.md') as $file) {
            $page = (string) file_get_contents($file);
            str_contains($page, 'template: w4os-admin') || expect($page)->not->toMatch('/type: button[^}]*url:/');
        }
    });
});
