<?php

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Site;

describe('the settings of the site', function () {
    test('have their page titled in the language of the page, as every page of the plugin', function () {
        expect(Site::titleKey('settings'))->toBe('w4os.admin_site_settings')
            ->and(Site::titleKey('account'))->toBe('w4os.edit_profile')
            ->and(Site::titleKey('search'))->toBe('w4os.search')
            ->and(Site::titleKey('register'))->toBe('w4os.register_title');
    });

    test('write Grav\'s own setting in the configuration of the site, nothing else of it', function () {
        $config = ['home' => ['alias' => '/home'], 'pages' => ['theme' => 'w4os', 'markdown' => ['extra' => true]]];

        expect(Site::withSetting($config, 'pages.theme', 'mundana'))->toBe(['home' => ['alias' => '/home'], 'pages' => ['theme' => 'mundana', 'markdown' => ['extra' => true]]])
            ->and(Site::withSetting([], 'pages.theme', 'mundana'))->toBe(['pages' => ['theme' => 'mundana']]);
    });

    test('of a theme stay out of the configuration of the site while they are the theme\'s own defaults', function () {
        $logo = ['user/themes/w4os/images/logo/opensimulator-logo.png' => ['name' => 'opensimulator-logo.png', 'type' => 'image/png', 'path' => 'user/themes/w4os/images/logo/opensimulator-logo.png']];
        $posted = ['user/themes/w4os/images/logo/opensimulator-logo.png' => ['name' => 'opensimulator-logo.png', 'type' => 'image/png', 'size' => 17395, 'path' => 'user/themes/w4os/images/logo/opensimulator-logo.png']];
        $mine = ['user/data/theme/images/logo/mine.png' => ['name' => 'mine.png', 'type' => 'image/png', 'path' => 'user/data/theme/images/logo/mine.png']];

        // The same files (by their paths), the same switch, nothing set where the theme has nothing: its defaults
        expect(Site::isDefault($posted, $logo))->toBeTrue()
            ->and(Site::isDefault(true, true))->toBeTrue()
            ->and(Site::isDefault([], null))->toBeTrue()
            ->and(Site::isDefault($mine, $logo))->toBeFalse()
            ->and(Site::isDefault([], $logo))->toBeFalse()
            ->and(Site::isDefault($mine, null))->toBeFalse()
            ->and(Site::isDefault(true, false))->toBeFalse();
        // A setting back to its default leaves the configuration, with the sections it empties
        expect(Site::withoutSetting(['custom_logo' => $mine, 'accent-color' => '#123456'], 'custom_logo'))->toBe(['accent-color' => '#123456'])
            ->and(Site::withoutSetting(['pages' => ['theme' => 'woo']], 'pages.theme'))->toBe([])
            ->and(Site::withoutSetting(['pages' => ['theme' => 'woo', 'markdown' => ['extra' => true]]], 'pages.theme'))->toBe(['pages' => ['markdown' => ['extra' => true]]])
            ->and(Site::withoutSetting(['accent-color' => '#123456'], 'custom_logo'))->toBe(['accent-color' => '#123456']);
    });

    test('have their page in the admin: the theme first, its sections as side tabs (Tabler\'s settings layout)', function () {
        $header = Yaml::parse(explode('---', (string) file_get_contents(dirname(__DIR__).'/pages/settings.md'))[1]);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['forms/form.html.twig' => '<form id="{{ form.name }}"></form>']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new TwigFunction('forms', fn (string $name) => ['name' => $name]));
        $twig->addFunction(new TwigFunction('w4os_settings_fields', fn ($form) => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/settings.html.twig', ['page' => ['header' => $header]]);

        expect($header)->toMatchArray(['template' => 'w4os-admin', 'w4os' => 'settings'])
            ->and(array_keys($header['form']['fields']))->toBe(['appearance'])
            ->and(array_keys($header['form']['fields']['appearance']['fields']))->toBe(['theme', 'logo', 'favicon', 'backdrop', 'backdrop_light', 'backdrop_dark'])
            ->and($header['form']['process'])->toBe([['upload' => true], ['w4os' => 'settings']])
            ->and($html)->toContain('class="list-group list-group-transparent"', 'href="#w4os-settings-appearance"', '[w4os.admin_site_appearance]', '<form id="w4os-settings"></form>');
    });
});

describe('the brand of the site', function () {
    test('is given to the active theme in its own fields, as their upload would: the copy in the theme, its name and path', function () {
        $logo = ['user/data/theme/images/logo/fowl.png' => ['name' => 'fowl.png', 'type' => 'image/png', 'path' => 'user/data/theme/images/logo/fowl.png']];
        $icon = ['user/data/theme/images/favicon/fowl-icon.png' => ['name' => 'fowl-icon.png', 'type' => 'image/png', 'path' => 'user/data/theme/images/favicon/fowl-icon.png']];
        $brand = ['logo' => $logo, 'favicon' => $icon];
        $copy = fn (string $path) => [$path => ['name' => basename($path), 'type' => 'image/png', 'path' => $path]];

        expect(Site::themeBrand($brand, ['logo' => 'custom_logo', 'favicon' => 'favicon'], ['logo' => 'user/themes/future2021/images/logo/fowl.png', 'favicon' => 'user/themes/future2021/images/fowl-icon.png'], []))
            ->toBe(['custom_logo' => $copy('user/themes/future2021/images/logo/fowl.png'), 'favicon' => $copy('user/themes/future2021/images/fowl-icon.png')])
        // Editorial: its switch on with the logo
            ->and(Site::themeBrand(['logo' => $logo], ['logo' => 'custom_logo'], ['logo' => 'user/themes/editorial/images/logo/fowl.png'], ['custom_logo_enabled' => true]))
            ->toBe(['custom_logo' => $copy('user/themes/editorial/images/logo/fowl.png'), 'custom_logo_enabled' => true])
        // Nothing set for the site, a theme without the field, a copy that could not be made: the theme as it is
            ->and(Site::themeBrand(['logo' => [], 'favicon' => $icon], ['logo' => 'custom_logo'], ['logo' => 'user/themes/quark2/images/logo/fowl.png'], ['custom_logo_enabled' => true]))->toBe([])
            ->and(Site::themeBrand(['logo' => $logo], ['logo' => 'custom_logo'], [], []))->toBe([])
            ->and(Site::themeBrand(['logo' => $logo], ['logo' => 'custom_logo', 'favicon' => 'favicon'], ['logo' => 'user/themes/quark2/images/logo/fowl.png'], []))
            ->toBe(['custom_logo' => $copy('user/themes/quark2/images/logo/fowl.png')]);
    });

    test('is copied where the theme takes it, never over a file of the theme, again once a theme update erased it', function () {
        $root = sys_get_temp_dir().'/w4os-brand-'.bin2hex(random_bytes(4));
        mkdir("$root/user/data/brand", 0777, true);
        mkdir("$root/user/themes/quark2/images/logo", 0777, true);
        file_put_contents("$root/user/data/brand/logo.png", 'site');
        file_put_contents("$root/user/data/brand/fowl.png", 'fowl');
        // The theme's own logo.png: the site's beside it
        file_put_contents("$root/user/themes/quark2/images/logo/logo.png", 'theme');
        $folder = 'user/themes/quark2/images/logo';

        expect(Site::brandCopy($root, 'user/data/brand/fowl.png', $folder))->toBe("$folder/fowl.png")
            ->and(file_get_contents("$root/$folder/fowl.png"))->toBe('fowl')
            ->and(Site::brandCopy($root, 'user/data/brand/logo.png', $folder))->toBe("$folder/site-logo.png")
            ->and(file_get_contents("$root/$folder/logo.png"))->toBe('theme')
            ->and(file_get_contents("$root/$folder/site-logo.png"))->toBe('site');
        // Erased by an update of the theme, its folder too: made again
        exec('rm -rf '.escapeshellarg("$root/user/themes/quark2"));
        expect(Site::brandCopy($root, 'user/data/brand/fowl.png', $folder))->toBe("$folder/fowl.png")
            ->and(Site::brandCopy($root, 'user/data/brand/missing.png', $folder))->toBeNull();
        exec('rm -rf '.escapeshellarg($root));
    });

    test('goes into the file a theme takes it from when it has no field for it (Woo\'s images/logo.png), its own put back without it', function () {
        $root = sys_get_temp_dir().'/w4os-fixed-'.bin2hex(random_bytes(4));
        mkdir("$root/user/themes/woo/templates/partials", 0777, true);
        mkdir("$root/user/themes/woo/images", 0777, true);
        mkdir("$root/user/data/brand", 0777, true);
        file_put_contents("$root/user/themes/woo/templates/partials/header.html.twig", '<img src="{{ theme_url }}/images/logo.png"> <link href="{{ theme_url }}/images/favicon.png">');
        file_put_contents("$root/user/themes/woo/images/logo.png", 'woo');
        file_put_contents("$root/user/themes/woo/images/footer-logo.png", 'footer');
        file_put_contents("$root/user/data/brand/a.png", 'brand a');
        file_put_contents("$root/user/data/brand/b.png", 'brand b');
        $logo = "$root/user/themes/woo/images/logo.png";
        $kept = "$root/user/data/w4os/theme-files/woo/images/logo.png";

        expect(Site::fixedBrandFiles("$root/user/themes/woo"))->toBe(['logo' => 'images/logo.png', 'favicon' => 'images/favicon.png']);
        // The site's logo where Woo takes it, Woo's own kept once; another logo of the site, Woo's own still the one kept
        Site::brandOver("$root/user/data/brand/a.png", $logo, $kept);
        expect(file_get_contents($logo))->toBe('brand a')->and(file_get_contents($kept))->toBe('woo');
        Site::brandOver("$root/user/data/brand/b.png", $logo, $kept);
        expect(file_get_contents($logo))->toBe('brand b')->and(file_get_contents($kept))->toBe('woo');
        // An update of Woo: its new own kept, the site's logo again
        file_put_contents($logo, 'woo 2');
        Site::brandOver("$root/user/data/brand/b.png", $logo, $kept);
        expect(file_get_contents($logo))->toBe('brand b')->and(file_get_contents($kept))->toBe('woo 2');
        // No logo for the site: Woo's own back, nothing kept
        Site::brandOver(null, $logo, $kept);
        expect(file_get_contents($logo))->toBe('woo 2')->and(file_exists($kept))->toBeFalse()
            ->and(file_get_contents("$root/user/themes/woo/images/footer-logo.png"))->toBe('footer');
        exec('rm -rf '.escapeshellarg($root));
    });

    test('leaves the streams of Grav as they are (no folder of the site in the theme\'s)', function () {
        foreach (['w4os.php', ...glob(dirname(__DIR__).'/classes/*.php')] as $file) {
            expect((string) file_get_contents(str_starts_with($file, '/') ? $file : dirname(__DIR__)."/$file"))->not->toContain("addPath('theme'");
        }
    });
});
