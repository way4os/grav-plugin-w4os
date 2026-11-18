<?php

use OpenSim\Api\Contract;
use W4os\Grav\Items;
use W4OS\Views\Contract as OpenApi;

describe('the page of an item of the engine', function () {
    $api = new OpenApi(Contract::document());

    test('is read from the x-form of its operation', function () use ($api) {
        $region = $api->itemOf('regions');

        expect(array_column($region['groups'], 'name'))->toBe(['region'])
            ->and(array_column($region['fields'], 'name'))->toContain('name', 'uri', 'enabled')
            ->and(array_column($region['actions'], 'name'))->toBe(['enable', 'disable'])
            ->and(array_column(array_column($region['fields'], null, 'name')['roles']['values'], 'label', 'value'))->toMatchArray(['FallbackRegion' => 'region_flag_fallback_region'])
            ->and($api->itemOf('avatars')['groups'][0])->toBe(['name' => 'inworld', 'label' => 'profile_tab_inworld'])
            ->and($api->itemOf('nothing'))->toBeNull();
    });

    test('its form for Grav: the inputs of the fields the viewer changes (w4os-views FormView), the id hidden', function () use ($api) {
        $form = $api->itemOf('regions');
        $local = ['uuid' => 'r1', 'name' => 'Welcome', 'hyperlink' => false, 'local' => true, 'enabled' => true];
        $fields = Items::fields(W4OS\Views\FormView::of($form, $local, null, true, 200));

        expect($fields['id'])->toBe(['type' => 'hidden'])
            ->and(json_encode($fields))->toContain('"enabled":{"type":"checkbox"', '"owner":{"type":"w4os_avatar"')->not->toContain('"name":');
    });
});

test('a form of the site takes the choices of its fields from the engine', function () {
    $form = (new OpenApi(Contract::document()))->itemOf('avatars');
    $fields = ['tabs' => ['type' => 'tabs', 'fields' => ['account' => ['type' => 'tab', 'fields' => [
        'role' => ['type' => 'select', 'options' => ['old' => 'w4os.old']],
        'email' => ['type' => 'email'],
    ]]]]];
    $role = Items::withOptions($fields, $form)['tabs']['fields']['account']['fields']['role'];

    expect(array_keys($role['options']))->toContain('resident', 'god')->not->toContain('old')
        ->and($role['options']['resident'])->toBe('w4os.role_resident')
        ->and(Items::withOptions($fields, $form)['tabs']['fields']['account']['fields']['email'])->toBe(['type' => 'email']);
});

test('a picture says what stands for it when there is none: a person for the avatars, a place for the regions', function () {
    $api = new OpenApi(Contract::document());
    $name = array_column($api->listOf('avatars')['columns'], null, 'name')['name'];
    $picture = array_column($api->itemOf('avatars')['fields'], null, 'name')['profileImage'];
    $map = array_column($api->listOf('regions')['columns'], null, 'name')['name'];

    expect($name['default'] ?? null)->toBe('person')
        ->and($picture['default'] ?? null)->toBe('person')
        ->and($map['default'] ?? null)->toBe('place');
});
