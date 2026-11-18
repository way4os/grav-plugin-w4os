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

    test('have their page in the admin: the theme first, its sections as side tabs (Tabler\'s settings layout)', function () {
        $header = Yaml::parse(explode('---', (string) file_get_contents(dirname(__DIR__).'/pages/settings.md'))[1]);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['forms/form.html.twig' => '<form id="{{ form.name }}"></form>']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new TwigFunction('forms', fn (string $name) => ['name' => $name]));
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
