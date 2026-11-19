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

    $render = function (array $models, array $rule) {
        $twig = new Environment(new ChainLoader([new ArrayLoader(['w4os/admin-form.html.twig' => '<form id="{{ form.name }}"></form>']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new TwigFunction('w4os_models', fn () => $models));
        $twig->addFunction(new TwigFunction('w4os_models_rule', fn () => $rule + ['changeable' => true, 'values' => ['match' => 'any', 'pattern' => 'Default'], 'error' => null, 'help' => [], 'preview' => null]));
        $twig->addFunction(new TwigFunction('forms', fn (string $name) => ['name' => $name]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $assets = new class {
            public array $js = [];
            public function addJs(string $url): void { $this->js[] = $url; }
        };

        return [$twig->render('w4os/models.html.twig', ['page' => [], 'assets' => $assets]), $assets->js];
    };

    test('shows the form of the rule, its help beside it, then the models it picks with their pictures and names, updated as it is changed', function () use ($render) {
        $preview = ['label' => 'models_preview', 'empty' => 'models_none', 'path' => '/grids/{grid}/avatar-models', 'filters' => ['match', 'pattern', 'list']];
        [$html, $js] = $render([['uuid' => 'm1', 'name' => 'Default Ruth', 'label' => 'Ruth', 'image' => '/assets/p1.jpg']], ['help' => ['models_help_choice', 'models_help_create'], 'preview' => $preview]);

        expect($html)->toContain('src="&#x2F;assets&#x2F;p1.jpg"', 'Ruth', 'Default Ruth', '<form id="w4os-models"></form>', '[w4os.models_preview]')
            ->and($html)->toMatch('~<form id="w4os-models"></form>.*<li>\[w4os.models_help_choice\]</li><li>\[w4os.models_help_create\]</li>.*data-w4os-models-preview="&#x3F;format&#x3D;preview" data-form="w4os-models" data-filters="&#x5B;&quot;match&quot;,&quot;pattern&quot;,&quot;list&quot;&#x5D;".*Default Ruth~s')
            ->and($js)->toBe(['plugin://w4os/js/models-preview.js']);
    });

    test('says what the engine says when the rule picks no model', function () use ($render) {
        [$html] = $render([], ['preview' => ['label' => 'models_preview', 'empty' => 'models_none', 'filters' => []]]);

        expect($html)->toContain('[w4os.models_none]')->not->toContain('w4os-model-picture');
    });

    test('shows the rule as the engine describes it: Match as radios (its widget), the list shown by the choice of a custom list', function () {
        $form = (new W4OS\Views\Contract(OpenSim\Api\Contract::document()))->settingsOf('models');
        $view = W4OS\Views\FormView::of($form, ['match' => 'any', 'pattern' => 'Default', 'list' => []], null, true, 200);
        $fields = Items::fields($view);

        expect($fields['match'])->toMatchArray(['type' => 'radio', 'label' => 'w4os.models_match'])
            ->and($fields['match']['options'])->toBe(['first' => 'w4os.models_match_first', 'any' => 'w4os.models_match_any', 'last' => 'w4os.models_match_last', 'uuid' => 'w4os.models_match_uuid'])
            ->and($fields['pattern'])->not->toHaveKey('outerclasses')
            ->and($fields['list'])->toMatchArray(['type' => 'w4os_avatar', 'placeholder' => 'w4os.models_list_placeholder', 'outerclasses' => 'w4os-unmet'])
            ->and(Items::conditions($view))->toBe(['pattern' => ['match' => ['first', 'any', 'last']], 'list' => ['match' => ['uuid']]]);
    });

    test('is under Avatars in the menu of the admin', function () {
        expect(Site::ADMIN_PAGES['models']['parent'])->toBe('manage');
    });

    test('picks several avatars in a field of several (the models of the list)', function () {
        expect(Items::input(['name' => 'list', 'label' => 'models_list', 'type' => 'avatar', 'multiple' => true]))
            ->toMatchArray(['type' => 'w4os_avatar', 'multiple' => true, 'validate' => ['type' => 'array']])
            ->and(Items::input(['name' => 'owner', 'label' => 'owner', 'type' => 'avatar']))->not->toHaveKey('multiple');
    });

    test('its page is laid out by the page, the grid of the pictures applies to their list alone (the page is w4os-models too)', function () {
        foreach (['css/admin.css', 'css/w4os.css'] as $file) {
            preg_match_all('/^([^{}]*\.w4os-models[^-\w][^{]*)\{/m', (string) file_get_contents(dirname(__DIR__)."/$file"), $rules);
            foreach ($rules[1] as $selector) {
                expect($selector)->toContain('ul.w4os-models');
            }
        }
    });

    test('shows its models two by row beside the form, as wide as the column they are in', function () use ($render) {
        [$html] = $render([['uuid' => 'm1', 'name' => 'Default Ruth', 'image' => '/assets/p1.jpg']], ['preview' => ['label' => 'models_preview', 'filters' => []]]);

        expect($html)->toContain('class="col-6 col-sm-4 col-lg-6"')->not->toContain('col-xl-2');
    });
});
