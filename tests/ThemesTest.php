<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The templates of the plugin alone, no theme: Grav's functions and the plugin's as stubs, the assets written as they are asked. */
function themeless_twig(): Environment
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    // A block with its title, none when the page asks it without (an empty title)
    $twig->addFunction(new TwigFunction('w4os_block', fn (string $name, array $args = []) => "<section class=\"block block-$name\">".(($args['title'] ?? null) === '' ? '' : '<h1 class="block-title">Bart Simpson</h1>').'</section>', ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('w4os_page_args', fn () => ['name' => 'Bart.Simpson']));
    $twig->addFunction(new TwigFunction('w4os_is_viewer', fn () => true));
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

describe('the login status', function () {
    $render = function (array $user) {
        $twig = themeless_twig();
        $twig->addFunction(new TwigFunction('w4os_admin_menu', fn () => ($user['manager'] ?? false) ? [['url' => '/admin', 'label' => 'Avatars']] : []));
        $twig->addFunction(new TwigFunction('w4os_profile_page', fn () => '/profile/Ada.Love'));
        $twig->addFunction(new TwigFunction('w4os_route', fn (string $type) => "/$type"));
        $twig->addFunction(new TwigFunction('url', fn ($url) => $url));

        return $twig->render('partials/login-status.html.twig', ['grav' => ['user' => $user], 'base_url_relative' => '', 'uri' => new class {
            public function path(): string { return '/'; }
            public function addNonce(string $url): string { return "$url/nonce"; }
        }, 'config' => ['plugins' => ['w4os' => ['routes' => ['account' => '/account', 'forgot' => '/forgot']], 'login' => ['route' => '/login']], 'system' => ['param_sep' => ':']]]);
    };

    test('is the menu of the visitor wherever a theme shows Grav\'s login status: its avatar, its account, sign out; the admin for a manager', function () use ($render) {
        $ada = $render(['authenticated' => true, 'fullname' => 'Ada Love', 'w4os' => ['uuid' => 'u1']]);

        expect($ada)->toContain('class="login-status', 'href="/profile/Ada.Love"', 'Ada Love', 'href="/account"', 'login.logout', '[w4os.logout]')
            ->not->toContain('[w4os.menu_admin]')
            ->and($render(['authenticated' => true, 'fullname' => 'Mona', 'w4os' => ['uuid' => 'u2'], 'manager' => true]))->toContain('[w4os.menu_admin]', 'href="/admin"')
            // Not signed in: the way to sign in
            ->and($render(['authenticated' => false]))->toContain('href="/login"', '[w4os.log_in]');
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

describe('the menu of the site', function () {
    test('has the entry of the visitor, as every theme draws the pages: log in, else the account of the avatar', function () {
        expect(W4os\Grav\Site::menuKey(null))->toBe('w4os.log_in')
            ->and(W4os\Grav\Site::menuKey(['uuid' => 'u1', 'name' => 'Ada Love']))->toBe('w4os.menu_account');
    });

    test('leads to the account page, which has the profile, the admin for a manager and sign out, whatever the theme', function () {
        $twig = themeless_twig();
        $twig->addFunction(new TwigFunction('w4os_admin_menu', fn () => [['url' => '/admin', 'label' => 'Avatars']]));
        $twig->addFunction(new TwigFunction('w4os_profile_page', fn () => '/profile/Ada.Love'));
        $twig->addFunction(new TwigFunction('url', fn ($url) => $url));
        $html = $twig->render('w4os/account-links.html.twig', ['uri' => new class {
            public function path(): string { return '/account'; }
            public function addNonce(string $url): string { return "$url/nonce"; }
        }, 'config' => ['system' => ['param_sep' => ':']]]);

        expect($html)->toContain('href="/profile/Ada.Love"', '[w4os.profile_title]', 'href="/admin"', '[w4os.menu_admin]', 'login.logout', '[w4os.logout]');
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
    test('is the theme\'s to show: the content of the page has none, its block neither', function () {
        $html = themeless_twig()->render('w4os/page.html.twig', ['page' => ['title' => 'Bart Simpson', 'header' => ['w4os' => 'profile', 'w4os_block' => 'profile']]]);

        expect($html)->toContain('<section class="block block-profile">')->not->toContain('<h1')
            ->and(file_get_contents(dirname(__DIR__).'/templates/w4os/page.html.twig'))->not->toContain('page.title');
    });
});
