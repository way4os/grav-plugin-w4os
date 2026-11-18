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
            ->toContain('<body class="w4os-viewer viewer">', '<link href="plugin://w4os/vendor/magicoli/opensim-helpers/assets/css/page.css">', '<link href="plugin://w4os/css/viewer.css">',
                '<div class="w4os w4os-page w4os-profile">', '<section class="block block-profile">')
            // The page is the theme there: it shows the title of the page, the block none
            ->and(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toContain('<h1>Bart Simpson</h1>')->not->toContain('block-title');
    });
});

describe('the sign-in page', function () {
    test('has "remember me" checked, whatever the theme draws around the form of the Login plugin', function () {
        $form = '<div class="form-input-wrapper"><input type="checkbox" value="1" name="rememberme" id="#rememberme"><label class="inline" for="#rememberme">Remember me</label></div>';

        expect(W4os\Grav\Site::rememberChecked($form))->toContain('<input type="checkbox" value="1" name="rememberme" id="#rememberme" checked>')
            ->and(W4os\Grav\Site::rememberChecked(W4os\Grav\Site::rememberChecked($form)))->toBe(W4os\Grav\Site::rememberChecked($form))
            ->and(W4os\Grav\Site::rememberChecked('<p>No form</p>'))->toBe('<p>No form</p>')
            // A theme's own form (Mundana's: its classes first)
            ->and(W4os\Grav\Site::rememberChecked('<input class="form-check-input" type="checkbox" value="1" name="rememberme" id="#rememberme">'))
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

        expect(W4os\Grav\Site::withLoginLinks($page, '<p class="links"></p>'))->toBe('<main><form id="search"></form><section id="grav-login">'.$form.'<p class="links"></p></section><section id="other"></section></main>')
            ->and(W4os\Grav\Site::withLoginLinks('<section style="height: 80vh">'.$form.'</section>', '<p class="links"></p>'))->toBe('<section style="height: 80vh">'.$form.'<p class="links"></p></section>')
            ->and(W4os\Grav\Site::withLoginLinks('<p>No form</p>', '<p class="links"></p>'))->toBe('<p>No form</p>');
    });
});

describe('the title of a page of the plugin', function () {
    test('heads its content, as the text of a page of the site does (# Title): the themes show the content alone', function () {
        $html = themeless_twig(false)->render('w4os/page.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']]]);

        expect($html)->toContain('<h1 class="w4os-page-title">Bart Simpson</h1>', '<section class="block block-profile">')
            ->and(strpos($html, '<h1'))->toBeLessThan(strpos($html, '<section class="block block-profile">'));
    });

    test('is the theme\'s alone where the theme shows the title of a page itself (Editorial, Learn2, Mundana): no title twice', function () {
        $ours = '<div class="w4os w4os-page"><h1 class="w4os-page-title">Créer mon avatar</h1><form></form></div>';

        expect(W4os\Grav\Site::withoutDuplicateTitle('<header><h2 class="major"><a href="/register">Cr&eacute;er mon avatar</a></h2></header>'.$ours, 'Créer mon avatar'))
            ->not->toContain('w4os-page-title')->toContain('<form></form>')
            ->and(W4os\Grav\Site::withoutDuplicateTitle('<header><h1>My grid</h1></header>'.$ours, 'Créer mon avatar'))->toContain('<h1 class="w4os-page-title">Créer mon avatar</h1>')
            ->and(W4os\Grav\Site::withoutDuplicateTitle('<h1 class="title">CRÉER MON AVATAR</h1>'.$ours, 'Créer mon avatar'))->not->toContain('w4os-page-title');
    });

    test('is the viewer\'s layout\'s in a viewer (its own heading)', function () {
        $html = themeless_twig()->render('w4os/page.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']]]);

        expect($html)->not->toContain('<h1');
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

    test('has the files of its blocks before the theme writes its head, as any page with blocks of the helpers in its text', function () {
        expect(W4os\Grav\Site::shortcodeBlocks("# Hi\n[w4os-home]Text[/w4os-home]"))->toBe(['popular_places', 'destination_guide', 'grid_info', 'grid_status', 'motd'])
            ->and(W4os\Grav\Site::shortcodeBlocks('[w4os block="grid-status" /] and [w4os block="profile" name="A.B" /]'))->toBe(['grid_status', 'profile'])
            ->and(W4os\Grav\Site::shortcodeBlocks('No block'))->toBe([]);
    });
});

describe('a page of an earlier version', function () {
    test('laid out by the splash template of the plugin is the home page made content, nothing to change on an upgrade', function () {
        expect(W4os\Grav\Site::isSplash('w4os/splash'))->toBeTrue()
            ->and(W4os\Grav\Site::isSplash('default'))->toBeFalse()
            ->and(W4os\Grav\Site::isSplash(null))->toBeFalse();
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
