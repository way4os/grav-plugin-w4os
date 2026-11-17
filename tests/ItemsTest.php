<?php

use OpenSim\Api\Contract;
use W4os\Grav\Items;
use W4os\Grav\OpenApi;

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

    test('knows who the visitor is to it', function () {
        expect(Items::roles(['uuid' => 'u1', 'owner' => 'u2'], 'u2', false))->toBe(['owner'])
            ->and(Items::roles(['uuid' => 'u1'], 'u1', true))->toBe(['manager', 'self'])
            ->and(Items::roles(['uuid' => 'u1'], null, false))->toBe([]);
    });

    test('shows a field by its condition, lets change it by its access', function () use ($api) {
        $fields = array_column($api->itemOf('regions')['fields'], null, 'name');
        $avatar = array_column($api->itemOf('avatars')['fields'], null, 'name');

        expect(Items::visible($fields['enabled'], ['hyperlink' => false, 'local' => true], ['manager']))->toBeTrue()
            ->and(Items::visible($fields['enabled'], ['hyperlink' => false, 'local' => false], ['manager']))->toBeFalse()
            ->and(Items::editable($fields['enabled'], ['manager']))->toBeTrue()
            ->and(Items::editable($fields['enabled'], ['owner']))->toBeFalse()
            ->and(Items::editable($fields['name'], ['manager']))->toBeFalse()
            ->and(Items::editable($avatar['about'], ['self']))->toBeTrue()
            ->and(Items::editable($avatar['role'], ['self']))->toBeFalse();
    });

    test('shows its groups with the fields the visitor sees, and a form of the ones it changes', function () use ($api) {
        $form = $api->itemOf('regions');
        $local = ['uuid' => 'r1', 'name' => 'Welcome', 'hyperlink' => false, 'local' => true, 'enabled' => true];
        $far = ['uuid' => 'r2', 'name' => 'Far', 'hyperlink' => true, 'local' => false];
        $names = fn (array $groups) => array_merge(...array_map(fn ($group) => array_column($group['fields'], 'name'), $groups));
        $fields = Items::fields($form, $local, ['manager']);

        expect($names(Items::groups($form, $local, ['manager'])))->toContain('name', 'enabled')
            ->and($names(Items::groups($form, $far, ['manager'])))->not->toContain('enabled')
            ->and(Items::changeable($form, $local, ['manager']))->toBeTrue()
            ->and(Items::changeable($form, $far, ['manager']))->toBeFalse()
            ->and(Items::changeable($form, $local, ['owner']))->toBeFalse()
            ->and($fields['id'])->toBe(['type' => 'hidden'])
            ->and(json_encode($fields))->toContain('"enabled":{"type":"checkbox"', '"owner":{"type":"w4os_avatar"')->not->toContain('"name":')
            ->and(Items::values($form, ['uuid' => 'r1', 'enabled' => true], ['manager']))->toBe(['id' => 'r1', 'owner' => '', 'roles' => [], 'enabled' => true])
            ->and(Items::changes($form, $local + ['roles' => ['Persistent']], ['roles' => ['Persistent', 'FallbackRegion'], 'enabled' => '1'], ['manager']))
            ->toBe(['roles' => ['FallbackRegion', 'Persistent']])
            ->and(Items::changes($form, $local + ['roles' => ['Persistent']], ['roles' => ['Persistent'], 'enabled' => '1'], ['manager']))->toBe([]);
    });

    test('a posted form changes what differs only', function () use ($api) {
        $region = $api->itemOf('regions');
        $avatar = $api->itemOf('avatars');
        $ada = ['uuid' => 'u1', 'about' => 'Hi', 'wantsList' => ['meet', 'build'], 'role' => 'resident'];

        expect(Items::changes($region, ['hyperlink' => false, 'local' => true, 'enabled' => true], [], ['manager']))->toBe(['enabled' => false])
            ->and(Items::changes($region, ['hyperlink' => false, 'local' => true, 'enabled' => true], ['enabled' => '1'], ['manager']))->toBe([])
            ->and(Items::changes($region, ['hyperlink' => false, 'local' => false, 'enabled' => null], [], ['manager']))->toBe([])
            ->and(Items::changes($region, ['hyperlink' => false, 'local' => true, 'enabled' => true], [], ['owner']))->toBe([])
            ->and(Items::changes($avatar, $ada, ['about' => 'Hi', 'wantsList' => ['build', 'meet'], 'role' => 'god', 'password' => ''], ['self']))
            ->toBe([])
            ->and(Items::changes($avatar, $ada, ['about' => 'Hello', 'wantsList' => ['build', 'meet'], 'role' => 'god', 'password' => 'secret1'], ['manager']))
            ->toMatchArray(['about' => 'Hello', 'role' => 'god', 'password' => 'secret1']);
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
