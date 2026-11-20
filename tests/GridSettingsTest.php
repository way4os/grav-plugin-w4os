<?php

use W4os\Grav\Items;
use W4os\Grav\Site;
use W4OS\Views\Contract;
use W4OS\Views\FormView;

describe('the settings of the grid and of its avatars', function () {
    test('live under the admin route, the grid before the services, the avatars under Avatars', function () {
        $routes = Site::adminRoutes(['admin' => '/w4os'], []);

        expect($routes['settings_grid'])->toBe('/w4os/grid')
            ->and($routes['settings_avatars'])->toBe('/w4os/avatars/settings')
            ->and(Site::ADMIN_PAGES['settings_avatars']['parent'])->toBe('manage')
            ->and(Site::serviceGroup('settings_grid'))->toBe('grid')
            ->and(Site::serviceGroup('settings_avatars'))->toBe('avatars')
            ->and(is_file(dirname(__DIR__).'/pages/settings_grid.md') && is_file(dirname(__DIR__).'/pages/settings_avatars.md'))->toBeTrue();
    });

    test('the grid in the tabs of its groups: Grid Info, Robust, Services, Avatars', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('grid');
        $fields = Items::fields(FormView::of($form, [], null, true, 250), shown: true);
        $tabs = $fields['groups']['fields'];

        expect($fields['groups']['type'])->toBe('tabs')
            ->and(array_keys($tabs))->toBe(['grid', 'robust', 'services', 'avatars'])
            ->and($tabs['grid']['title'])->toBe('w4os.group_grid_info')
            ->and(array_keys($tabs['robust']['fields']))->toContain('gridServerUri', 'database', 'consoleUser', 'consolePass', 'consolePort')
            ->and($tabs['robust']['fields']['database'])->toMatchArray(['type' => 'fieldset', 'label' => 'w4os.setting_robust_database'])
            ->and($tabs['avatars']['fields']['excludeFromStats'])->toMatchArray(['type' => 'checkboxes'])
            ->and($tabs['robust']['fields']['consolePass'])->toMatchArray(['type' => 'password']);

        return $tabs;
    });

    test('an address with a default (the assets service) as a field of its own, its Use default then its value without a label of its own, hidden while it is checked', function (array $tabs) {
        $uri = $tabs['services']['fields']['assetServerUri'];
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('grid');
        $own = Items::fields(FormView::of($form, ['assetServerUri' => 'http://assets.example.org:8003'], null, true, 250), shown: true)['groups']['fields']['services']['fields']['assetServerUri'];

        expect($uri)->toMatchArray(['type' => 'fieldset', 'label' => 'w4os.setting_assets_service_uri', 'classes' => 'w4os-defaulted'])
            ->and(array_keys($uri['fields']))->toBe(['assetServerUri.useDefault', 'assetServerUri.value'])
            ->and($uri['fields']['assetServerUri.useDefault'])->toMatchArray(['type' => 'checkbox', 'label' => 'w4os.db_use_default'])
            ->and($uri['fields']['assetServerUri.value'])->toMatchArray(['type' => 'url', 'label' => false, 'outerclasses' => 'w4os-fallback-of-assetServerUri w4os-unmet'])
            ->and($own['fields']['assetServerUri.value']['outerclasses'])->toBe('w4os-fallback-of-assetServerUri')
            // The script that shows it as the box is unchecked, on a form without a condition too
            ->and(Items::conditions(FormView::of($form, [], null, true, 250)))->toBe([])
            ->and(Items::defaulted(FormView::of($form, [], null, true, 250)))->toBeTrue()
            ->and(Items::defaulted(FormView::of((new Contract(OpenSim\Api\Contract::document()))->settingsOf('avatars'), [], null, true, 250)))->toBeFalse();
    })->depends('the grid in the tabs of its groups: Grid Info, Robust, Services, Avatars');

    test('a Use default unchecked is no more checked, though the form was filled with it', function () {
        $data = ['assetServerUri' => ['useDefault' => true, 'value' => 'http://a.example.org:8003'], 'moneyDatabase' => ['useDefault' => true], 'gridName' => 'Mine'];
        $posted = Items::posted($data, fn (string $key) => ['moneyDatabase.useDefault' => '1'][$key] ?? null);

        expect($posted['assetServerUri'])->toBe(['useDefault' => false, 'value' => 'http://a.example.org:8003'])
            ->and($posted['moneyDatabase'])->toBe(['useDefault' => true])
            ->and($posted['gridName'])->toBe('Mine');
    });

    test('the names an avatar may not take as a list, one by line; several avatars by email as a box', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('avatars');
        $fields = Items::fields(FormView::of($form, ['reservedNames' => ['Default', 'Test']], null, true, 250), shown: true);

        expect($fields['reservedNames'])->toMatchArray(['type' => 'array', 'value_only' => true])
            ->and($fields['allowMultipleAvatars']['type'])->toBe('checkbox');
    });
});

describe('the web assets of the grid', function () {
    test('served under the path of their settings when the site provides them, routed to the engine, not a page of the site', function () {
        expect(Site::providedRoute(['provide' => true, 'path' => 'helpers/assets']))->toBe('/helpers/assets')
            ->and(Site::providedRoute(['provide' => true, 'path' => 'assets/']))->toBe('/assets')
            ->and(Site::providedRoute(['provide' => false, 'path' => 'helpers/assets', 'assetsUrl' => 'https://assets.example.org/']))->toBeNull()
            ->and(Site::assetAsked('/helpers/assets', '/helpers/assets/00000000-0000-1111-9999-000000000001.jpg'))->toBe(['00000000-0000-1111-9999-000000000001', 'jpg'])
            ->and(Site::assetAsked('/helpers/assets/', '/helpers/assets/00000000-0000-1111-9999-000000000001.png'))->toBe(['00000000-0000-1111-9999-000000000001', 'png'])
            ->and(Site::assetAsked('/helpers/assets', '/helpers/assets/page'))->toBeNull()
            ->and(Site::assetAsked('/helpers/assets', '/helpers/register.php'))->toBeNull();
    });
});
