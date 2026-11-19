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
        expect(array_keys(Site::ADMIN_PAGES))->toBe(['dashboard', 'simulators', 'regions', 'manage', 'models', 'flux', 'settings_economy', 'settings_search', 'settings_guide', 'settings_offline', 'settings_assets', 'health', 'shortcodes', 'settings'])
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
            // The logo alone in the brand, the title of the site as its text
            ->and($html)->toContain('src="&#x2F;logo.png" alt="Friendly&#x20;Fowl"')
            ->and($html)->not->toContain('w4os-admin-title')
            ->and($html)->toContain('--tblr-primary: #8428df', '--tblr-primary-rgb: 132,40,223')
            ->and($html)->toMatch('~<li class="nav-item active">\s*<a class="nav-link" href="&#x2F;account&#x2F;regions"~')
            ->and($html)->toContain('<i class="ti&#x20;ti-dashboard"', 'navbar-toggler', '<!-- w4os:admin-messages -->')
            ->and($html)->toContain('href="&#x2F;user&#x2F;plugins&#x2F;w4os&#x2F;vendor&#x2F;npm-asset&#x2F;tabler-core&#x2F;dist&#x2F;css&#x2F;tabler.min.css"', 'tabler-icons.min.css', 'tabler.min.js')
            ->and($html)->toContain('Eli Mail')
            // The icon of w4os, whatever the theme
            ->and($html)->toContain('<link rel="icon" type="image/png" sizes="32x32" href="&#x2F;user&#x2F;plugins&#x2F;w4os&#x2F;vendor&#x2F;magicoli&#x2F;opensim-engine&#x2F;share&#x2F;images&#x2F;logos&#x2F;cropped-w4os-logo-v1-square-32x32.png"', 'rel="apple-touch-icon"')
            // The visitor's block at the bottom of the sidebar, the page as wide as the content area
            ->and($html)->toContain('<div class="navbar-footer">', '<div class="container-fluid">')
            ->and($html)->not->toContain('partials/base');
    });
});

describe('the dashboard of the admin', function () {
    test('has the widgets of w4os-wp first, the grid info and status, then the regions and the simulators online of all, the counts of the avatars in one card, the recent users', function () {
        $page = (string) file_get_contents(dirname(__DIR__).'/pages/dashboard.md');
        $header = Symfony\Component\Yaml\Yaml::parse(explode('---', $page)[1]);

        expect($header['w4os_cards'])->toBe([
            ['block' => 'grid-info', 'title' => 'admin_dashboard_grid_info'],
            ['block' => 'grid-status', 'title' => 'admin_dashboard_grid_status'],
            ['page' => 'regions', 'part' => ['status' => 'online'], 'text' => 'regions_online'],
            ['page' => 'simulators', 'part' => ['running' => 'yes'], 'text' => 'simulators_running'],
            ['page' => 'manage', 'counts' => [['online' => 'online'], ['status' => 'pending'], ['type' => 'model'], ['type' => 'service']]],
            ['page' => 'manage', 'rows' => 5, 'item' => 'edit', 'title' => 'recent_users'],
        ])->and($header)->not->toHaveKey('w4os_blocks');
    });
});

describe('the menu of the admin', function () {
    test('nests a page under the one it belongs to, its list first named List (not the name of the parent), open on the page shown', function () {
        $items = [
            ['type' => 'regions', 'label' => 'Regions', 'url' => '/w4os/regions', 'active' => false],
            ['type' => 'manage', 'label' => 'Avatars', 'url' => '/w4os/manage', 'active' => false],
            ['type' => 'models', 'label' => 'Avatar Models', 'url' => '/w4os/models', 'active' => true, 'parent' => 'manage'],
        ];
        $menu = Site::menuTree($items, 'List');

        expect(array_column($menu, 'type'))->toBe(['regions', 'manage'])
            ->and($menu[1]['active'])->toBeTrue()
            ->and($menu[1]['label'])->toBe('Avatars')
            ->and(array_column($menu[1]['children'], 'label'))->toBe(['List', 'Avatar Models'])
            ->and(array_column($menu[1]['children'], 'active'))->toBe([false, true])
            ->and(Site::menuTree([$items[2]]))->toBe([$items[2]]);
    });

    test('shows the pages nested under their parent', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_brand', fn () => ['title' => 'Friendly Fowl', 'home' => '/']));
        $twig->addFunction(new TwigFunction('w4os_admin_menu', fn () => [
            ['label' => 'Avatars', 'url' => '/w4os/manage', 'icon' => 'ti ti-users', 'active' => true, 'children' => [
                ['label' => 'List', 'url' => '/w4os/manage', 'active' => false],
                ['label' => 'Avatar Models', 'url' => '/w4os/models', 'active' => true],
            ]],
        ]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $html = $twig->render('w4os-admin.html.twig', ['page' => new class {
            public array $header = ['w4os' => 'admin-messages'];
            public function title(): string { return 'Avatar Models'; }
        }, 'assets' => new class {
            public function css(): string { return ''; }
            public function js(string $group = ''): string { return ''; }
        }, 'grav' => ['user' => []]]);

        // The parent opens its list, its pages shown under it on the page of one of them
        expect($html)->toMatch('~<li class="nav-item dropdown active">\s*<a class="nav-link dropdown-toggle" href="&#x2F;w4os&#x2F;manage" aria-expanded="true">~')
            ->and($html)->not->toContain('data-bs-toggle="dropdown"')
            ->and($html)->toMatch('~<div class="dropdown-menu show">\s*<a class="dropdown-item" href="&#x2F;w4os&#x2F;manage">List</a>\s*<a class="dropdown-item active" href="&#x2F;w4os&#x2F;models" aria-current="page">Avatar Models</a>~');
    });
});

describe('the brand of the admin', function () {
    test('is the site\'s own logo (the admin setting, else the brand of the site), never the one of the theme of the site', function () {
        $brand = ['logo' => ['user/data/theme/images/logo/fowl.png' => ['name' => 'fowl.png', 'path' => 'user/data/theme/images/logo/fowl.png']]];
        $exists = fn (string $path) => $path === 'user/data/theme/images/logo/fowl.png';

        expect(Site::adminLogo('', $brand, $exists))->toBe('user/data/theme/images/logo/fowl.png')
            ->and(Site::adminLogo('/my-logo.png', $brand, $exists))->toBe('/my-logo.png')
            ->and(Site::adminLogo('', [], $exists))->toBeNull()
            ->and(Site::adminLogo('', $brand, fn () => false))->toBeNull()
            ->and(Site::adminLogo('', ['logo' => ['../x.png' => ['path' => '../../x.png']]], fn () => true))->toBeNull();
    });
});

describe('the styles of the admin', function () {
    test('are Tabler\'s and its own: the plugin\'s and the Form plugin\'s (its drop zones), none of the other plugins of the site', function () {
        $css = implode("\n", [
            '<link href="/user/plugins/form/assets/form-styles.css?6afe" type="text/css" rel="stylesheet">',
            '<link href="/user/plugins/login/css/login.css?6afe" type="text/css" rel="stylesheet">',
            '<link href="/user/plugins/oli-langswitcher/css/oli-langswitcher.css?6afe" type="text/css" rel="stylesheet">',
            '<link href="/user/plugins/w4os/vendor/magicoli/opensim-helpers/assets/profile.css" type="text/css" rel="stylesheet">',
            '<link href="/user/themes/quark2/css/theme.css" type="text/css" rel="stylesheet">',
        ]);

        expect(Site::adminCss($css))->toContain('form-styles.css', 'opensim-helpers/assets/profile.css')
            ->not->toContain('login.css')->not->toContain('oli-langswitcher')->not->toContain('quark2');
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

describe('the forms of the admin', function () {
    test('are Tabler\'s horizontal forms, one rule for them all: the label and its help on the left, the field on the right', function () {
        $form = (string) file_get_contents(dirname(__DIR__).'/templates/w4os/admin-form.html.twig');

        expect($form)->toContain("form_field_outer_classes = 'mb-3 row'", "form_field_outer_label_classes = 'col-12 col-md-3 col-form-label'", "form_field_outer_data_classes = 'col-12 col-md-9'");
        // Every form of the admin goes through it
        foreach (['edit', 'item', 'settings'] as $page) {
            expect((string) file_get_contents(dirname(__DIR__)."/templates/w4os/$page.html.twig"))->toContain("'w4os/admin-form.html.twig'");
        }
    });
});
