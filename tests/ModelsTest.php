<?php

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Items;
use W4os\Grav\Site;

describe('the avatar models page of the admin', function () {
    test('lives under the admin route, in the admin menu, for whom the contract lets read the rule of the models', function () {
        expect(Site::adminRoutes(['admin' => '/w4os'], [])['models'])->toBe('/w4os/models')
            ->and(Site::ADMIN_PAGES['models']['label'])->toBe('w4os.settings_models')
            ->and(Site::ADMIN_OPERATIONS['models'])->toBe(['get', '/grids/{grid}/settings/models'])
            ->and(Site::titleKey('models'))->toBe('w4os.settings_models');
    });

    test('shows the models with their pictures and names, then the form of the rule', function () {
        $twig = new Environment(new ChainLoader([new ArrayLoader(['w4os/admin-form.html.twig' => '<form id="{{ form.name }}"></form>']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new TwigFunction('w4os_models', fn () => [['uuid' => 'm1', 'name' => 'Default Ruth', 'label' => 'Ruth', 'image' => '/assets/p1.jpg']]));
        $twig->addFunction(new TwigFunction('w4os_models_rule', fn () => ['changeable' => true, 'values' => ['match' => 'any', 'pattern' => 'Default'], 'error' => null]));
        $twig->addFunction(new TwigFunction('forms', fn (string $name) => ['name' => $name]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/models.html.twig', ['page' => []]);

        expect($html)->toContain('src="&#x2F;assets&#x2F;p1.jpg"', 'Ruth', 'Default Ruth', '<form id="w4os-models"></form>');
    });

    test('picks several avatars in a field of several (the models of the list)', function () {
        expect(Items::input(['name' => 'list', 'label' => 'models_list', 'type' => 'avatar', 'multiple' => true]))
            ->toMatchArray(['type' => 'w4os_avatar', 'multiple' => true, 'validate' => ['type' => 'array']])
            ->and(Items::input(['name' => 'owner', 'label' => 'owner', 'type' => 'avatar']))->not->toHaveKey('multiple');
    });
});
