<?php

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Site;

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
            ->and(array_keys($header['form']['fields']['appearance']['fields']))->toBe(['theme', 'logo', 'favicon'])
            ->and($header['form']['process'])->toBe([['upload' => true], ['w4os' => 'settings']])
            ->and($html)->toContain('class="list-group list-group-transparent"', 'href="#w4os-settings-appearance"', '[w4os.admin_site_appearance]', '<form id="w4os-settings"></form>');
    });
});

describe('the brand of the site', function () {
    test('goes to the active theme\'s own fields, the ones its blueprint has, nothing for a theme without them', function () {
        $has = fn (array $fields) => fn (string $name) => in_array($name, $fields, true);

        expect(Site::brandFields($has(['custom_logo', 'custom_logo_mobile', 'custom_favicon'])))->toBe(['logo' => 'custom_logo', 'favicon' => 'custom_favicon'])
            ->and(Site::brandFields($has(['custom_logo_enabled', 'custom_logo'])))->toBe(['logo' => 'custom_logo'])
            ->and(Site::brandFields($has(['custom_logo', 'favicon'])))->toBe(['logo' => 'custom_logo', 'favicon' => 'favicon'])
            ->and(Site::brandFields($has([])))->toBe([])
            // Editorial shows its logo once it is enabled: the switch goes with it
            ->and(Site::brandSwitches($has(['custom_logo_enabled', 'custom_logo'])))->toBe(['custom_logo_enabled' => true])
            ->and(Site::brandSwitches($has(['custom_logo'])))->toBe([]);
    });
});

describe('the choice of the theme', function () {
    test('is a gallery of the themes installed, each its picture and name, the current one checked, a radio each', function () {
        $twig = new Environment(new ChainLoader([new ArrayLoader(['forms/field.html.twig' => '{% block input %}{% endblock %}']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $twig->addFilter(new TwigFilter('fieldName', fn ($name) => "data[$name]"));
        $html = $twig->render('forms/fields/w4os_themes/w4os_themes.html.twig', ['field' => ['name' => 'theme', 'options' => ['quark2' => 'Quark 2', 'woo' => 'Woo'], 'thumbnails' => ['quark2' => '/user/themes/quark2/thumbnail.jpg']], 'value' => 'woo', 'scope' => '']);

        expect($html)->toContain('class="form-imagecheck', 'type="radio" name="data[theme]" value="quark2"', '<img src="/user/themes/quark2/thumbnail.jpg"', 'Quark 2', 'Woo', 'w4os-theme-none')
            ->and($html)->toMatch('/value="woo"[^>]*checked/')
            ->and($html)->not->toMatch('/value="quark2"[^>]*checked/');
    });
});

describe('the files of the brand of the site', function () {
    test('are the site\'s data, found first where any theme looks for its logo and favicon, the theme\'s own after', function () {
        expect(Site::brandPaths('quark2'))->toBe([
            'images/logo' => ['user://data/theme/images/logo', 'themes://quark2/images/logo'],
            'images/favicon' => ['user://data/theme/images/favicon', 'themes://quark2/images/favicon'],
        ]);
    });
});
