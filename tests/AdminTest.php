<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Site;

describe('admin', function () {
    test('takes the accent color of the site as the primary color of Tabler, nothing when there is none', function () {
        expect(Site::brandColor('#8428DF'))->toBe(['color' => '#8428df', 'rgb' => '132,40,223', 'lt-rgb' => '243,234,252'])
            ->and(Site::brandColor('#abc'))->toBe(['color' => '#aabbcc', 'rgb' => '170,187,204', 'lt-rgb' => '247,248,250'])
            ->and(Site::brandColor('blue'))->toBeNull()
            ->and(Site::brandColor(null))->toBeNull();
    });

    test('has its sections in their order, each with its icon of Tabler', function () {
        expect(array_keys(Site::ADMIN_PAGES))->toBe(['dashboard', 'simulators', 'regions', 'manage', 'settings'])
            ->and(array_column(Site::ADMIN_PAGES, 'icon'))->each->toStartWith('ti ti-');
    });

    test('is a layout of its own (Tabler), the site\'s identity in its sidebar, the sections the visitor opens', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $assets = new class {
            public array $css = [];
            public function addCss(string $url): void { $this->css[] = $url; }
            public function addJs(string $url): void {}
            public function css(): string { return implode("\n", array_map(fn ($u) => "<link href=\"$u\">", $this->css)); }
            public function js(string $group = ''): string { return ''; }
        };
        $twig->addFunction(new TwigFunction('w4os_brand', fn () => ['title' => 'Friendly Fowl', 'logo' => '/logo.png', 'color' => '#8428df', 'rgb' => '132,40,223', 'lt-rgb' => '243,234,252', 'home' => '/']));
        $twig->addFunction(new TwigFunction('w4os_admin_menu', fn () => [
            ['label' => 'Dashboard', 'url' => '/account/dashboard', 'icon' => 'ti ti-dashboard', 'active' => false],
            ['label' => 'Regions', 'url' => '/account/regions', 'icon' => 'ti ti-map', 'active' => true],
        ]));
        $twig->addFunction(new TwigFunction('url', fn (?string $stream) => '/'.str_replace('plugin://', 'user/plugins/', (string) $stream)));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $html = $twig->render('w4os-admin.html.twig', ['page' => new class {
            public array $header = [];
            public function __construct() { $this->header = ['w4os' => 'admin-messages']; }
            public function title(): string { return 'Regions'; }
        }, 'assets' => $assets, 'grav' => ['user' => ['w4os' => ['name' => 'Eli Mail']]]]);

        expect($html)->toContain('<aside class="navbar navbar-vertical navbar-expand-lg"', 'Friendly Fowl', 'src="&#x2F;logo.png"')
            ->and($html)->toContain('--tblr-primary: #8428df', '--tblr-primary-rgb: 132,40,223')
            ->and($html)->toMatch('~<li class="nav-item active">\s*<a class="nav-link" href="&#x2F;account&#x2F;regions"~')
            ->and($html)->toContain('<i class="ti&#x20;ti-dashboard"', 'navbar-toggler', '<!-- w4os:admin-messages -->')
            ->and($html)->toContain('href="&#x2F;user&#x2F;plugins&#x2F;w4os&#x2F;vendor&#x2F;npm-asset&#x2F;tabler-core&#x2F;dist&#x2F;css&#x2F;tabler.min.css"', 'tabler-icons.min.css', 'tabler.min.js')
            ->and($html)->toContain('Eli Mail')
            // The visitor's block at the bottom of the sidebar, the page as wide as the content area
            ->and($html)->toContain('<div class="navbar-footer">', '<div class="container-fluid">')
            ->and($html)->not->toContain('partials/base');
    });
});

describe('sign-in', function () {
    test('lands on the avatar\'s own profile, unless a page asked for the sign-in', function () {
        $profile = 'https://site.example.org/fr/profile/Ada.Love';

        // From the sign-in page: the profile, whatever page the Login plugin kept on the way (its after_login route, the last page seen)
        expect(Site::landing(null, true, '/account', $profile))->toBe($profile)
            ->and(Site::landing(null, true, null, $profile))->toBe($profile)
            // A page of the plugin that sent the visitor to sign in: back there
            ->and(Site::landing('/account/regions', true, '/account', $profile))->toBe('/account/regions')
            // A protected page of the site that showed the sign-in form itself: stays there
            ->and(Site::landing(null, false, '/members', $profile))->toBe('/members')
            ->and(Site::landing(null, false, null, $profile))->toBe($profile);
    });
});
