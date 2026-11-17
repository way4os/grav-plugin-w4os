<?php

use OpenSim\Api\Contract;
use OpenSim\Models\Avatar;
use W4os\Grav\Items;
use W4os\Grav\Lists;
use W4os\Grav\OpenApi;
use W4os\Grav\Site;

describe('managers', function () {
    $api = new OpenApi(Contract::document());

    test('do on each page what the contract allows their level (x-access, access.level), nothing written here', function () use ($api) {
        $avatars = $api->listOf('avatars');
        $regions = $api->listOf('regions');
        $region = ['uuid' => 'r1', 'enabled' => true, 'local' => true, 'hyperlink' => false];
        $avatar = ['uuid' => 'a1', 'status' => 'active'];
        $actions = fn (array $list, array $row, int $level) => array_column(Lists::actions($list, $row, 'me', $level), 'name');

        foreach ([100, 150, 200, 250] as $level) {
            expect(OpenApi::allows($avatars['read'], $level))->toBeTrue()
                ->and(OpenApi::allows($regions['read'], $level))->toBeTrue()
                ->and($actions($avatars, $avatar, $level))->toContain('disable');
        }
        expect(OpenApi::allows($avatars['read'], 0))->toBeFalse()
            ->and($actions($regions, $region, 150))->toBe([])
            ->and($actions($regions, $region, 200))->toBe(['disable'])
            ->and($actions($regions, $region, 250))->toBe(['disable']);
    });

    test('allowed by the engine\'s Access when it is there, by the same rule read in the contract without it', function () use ($api) {
        foreach (Contract::document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['operationId'], $operation['x-access'])) {
                    continue;
                }
                $access = $api->access($method, $path);
                expect($access['operation'])->toBe($operation['operationId']);
                foreach (array_keys(Avatar::LEVELS) as $level) {
                    foreach ([false, true] as $own) {
                        $engine = OpenSim\Api\Access::allows($level, $operation['operationId'], $own);
                        expect(OpenApi::allowsByContract($access, $level, $own))->toBe($engine, "{$operation['operationId']} at $level")
                            ->and(OpenApi::allows($access, $level, $own))->toBe($engine);
                    }
                }
            }
        }
    });

    test('change on an item the fields of their level: an avatar\'s details from 100, its role and a region from 200', function () use ($api) {
        $avatar = array_column($api->itemOf('avatars')['fields'], null, 'name');
        $region = array_column($api->itemOf('regions')['fields'], null, 'name');

        expect(Items::editable($avatar['email'], ['manager'], 150))->toBeTrue()
            ->and(Items::editable($avatar['email'], ['manager'], 100))->toBeTrue()
            ->and(Items::editable($avatar['role'], ['manager'], 150))->toBeFalse()
            ->and(Items::editable($avatar['role'], ['manager'], 200))->toBeTrue()
            ->and(Items::editable($avatar['about'], ['self'], 0))->toBeTrue()
            ->and(Items::editable($avatar['role'], ['self'], 0))->toBeFalse()
            ->and(Items::editable($region['roles'], ['manager'], 150))->toBeFalse()
            ->and(Items::editable($region['roles'], ['manager'], 200))->toBeTrue()
            ->and(Items::editable($region['roles'], [], 250))->toBeFalse()
            ->and(Items::changeable($api->itemOf('regions'), ['local' => true, 'hyperlink' => false], ['manager'], 150))->toBeFalse();
    });

    test('leave out of a form of the site the fields they may not change, and what is posted for them', function () use ($api) {
        $form = $api->itemOf('avatars');
        $fields = ['account' => ['type' => 'tab', 'fields' => ['email' => ['type' => 'email'], 'role' => ['type' => 'select']]], 'avatar' => ['type' => 'hidden']];

        expect(Items::locked($form, ['manager'], 150))->toContain('role')->not->toContain('email', 'about')
            ->and(Items::locked($form, ['manager'], 200))->not->toContain('role')
            ->and(Items::without($fields, ['role']))->toBe(['account' => ['type' => 'tab', 'fields' => ['email' => ['type' => 'email']]], 'avatar' => ['type' => 'hidden']]);
    });

    test('read the admin pages from the level of the setting, customer service by default', function () {
        foreach ([100, 150, 200, 250] as $level) {
            expect(Site::readsAdmin($level, []))->toBeTrue();
        }
        expect(Site::readsAdmin(0, []))->toBeFalse()
            ->and(Site::readsAdmin(1, []))->toBeFalse()
            ->and(Site::readsAdmin(150, ['admin_level' => 200]))->toBeFalse()
            ->and(Site::readsAdmin(200, ['admin_level' => 200]))->toBeTrue();
    });

    test('the setting is one of the engine\'s levels, never a number of its own', function () {
        expect(array_keys(Site::adminLevels()))->toBe(array_keys(Avatar::LEVELS))
            ->and(Site::adminLevels()[100])->toBe('w4os.role_customer_service')
            ->and(Site::adminLevel([]))->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and(Site::adminLevel(['admin_level' => '150']))->toBe(150)
            ->and(Site::adminLevel(['admin_level' => 180]))->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and(Site::adminLevel(['admin_level' => 0]))->toBe(Avatar::GOD_NOT);
    });

    test('a setting of an older version (manager_level) is not one any more', function () {
        $config = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml');

        expect($config)->not->toHaveKey('manager_level')->toHaveKey('admin_level', 100)
            ->and(file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->not->toContain('manager_level')->toContain('admin_level');
    });
});
