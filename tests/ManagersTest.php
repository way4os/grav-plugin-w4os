<?php

use W4OS\Engine\Api\Contract;
use W4OS\Engine\Models\Avatar;
use W4OS\Grav\Items;
use W4OS\Grav\Lists;
use W4OS\Views\Contract as OpenApi;
use W4OS\Grav\Site;

describe('managers', function () {
    $api = new OpenApi(Contract::document());

    test('do on each page what the contract allows their level (x-access, access.level), nothing written here', function () use ($api) {
        $avatars = $api->listOf('avatars');
        $regions = $api->listOf('regions');
        $region = ['uuid' => 'r1', 'enabled' => true, 'local' => true, 'hyperlink' => false];
        $avatar = ['uuid' => 'a1', 'status' => 'active'];
        $actions = fn (array $list, array $row, int $level) => array_column(W4OS\Views\Rows::actions($list, $row, 'me', $level), 'name');

        foreach ([100, 150, 200, 250] as $level) {
            expect(W4OS\Views\Access::allows($avatars['read'], $level))->toBeTrue()
                ->and(W4OS\Views\Access::allows($regions['read'], $level))->toBeTrue()
                ->and($actions($avatars, $avatar, $level))->toContain('disable');
        }
        expect(W4OS\Views\Access::allows($avatars['read'], 0))->toBeFalse()
            ->and($actions($regions, $region, 150))->toBe([])
            ->and($actions($regions, $region, 200))->toBe(['disable'])
            ->and($actions($regions, $region, 250))->toBe(['disable']);
    });

    test('ban an avatar from the level the action asks (a god), never their own', function () use ($api) {
        $avatars = $api->listOf('avatars');
        $actions = fn (array $row, int $level, ?string $self = 'me') => array_column(W4OS\Views\Rows::actions($avatars, $row, $self, $level), 'name');
        $active = ['uuid' => 'a1', 'status' => 'active', 'estateBanned' => false];
        $banned = ['uuid' => 'a1', 'status' => 'banned', 'estateBanned' => true];

        expect($actions($active, 150))->not->toContain('ban', 'estateBan')
            ->and($actions($active, 200))->toContain('ban', 'estateBan')
            ->and($actions($banned, 200))->toContain('unban', 'estateUnban')->not->toContain('ban', 'disable')
            ->and($actions($active, 200, 'a1'))->not->toContain('ban', 'estateBan');
    });


    test('change on an item the fields of their level: an avatar\'s details from 100, its role and a region from 200', function () use ($api) {
        $avatar = array_column($api->itemOf('avatars')['fields'], null, 'name');
        $region = array_column($api->itemOf('regions')['fields'], null, 'name');

        expect(W4OS\Views\FormView::editable($avatar['email'], ['manager'], 150))->toBeTrue()
            ->and(W4OS\Views\FormView::editable($avatar['email'], ['manager'], 100))->toBeTrue()
            ->and(W4OS\Views\FormView::editable($avatar['role'], ['manager'], 150))->toBeFalse()
            ->and(W4OS\Views\FormView::editable($avatar['role'], ['manager'], 200))->toBeTrue()
            ->and(W4OS\Views\FormView::editable($avatar['about'], ['self'], 0))->toBeTrue()
            ->and(W4OS\Views\FormView::editable($avatar['role'], ['self'], 0))->toBeFalse()
            ->and(W4OS\Views\FormView::editable($region['roles'], ['manager'], 150))->toBeFalse()
            ->and(W4OS\Views\FormView::editable($region['roles'], ['manager'], 200))->toBeTrue()
            ->and(W4OS\Views\FormView::editable($region['roles'], [], 250))->toBeFalse()
            ->and(W4OS\Views\FormView::of($api->itemOf('regions'), ['local' => true, 'hyperlink' => false], null, true, 150)->changeable)->toBeFalse();
    });

    test('leave out of a form of the site the fields they may not change, and what is posted for them', function () use ($api) {
        $form = $api->itemOf('avatars');
        $fields = ['account' => ['type' => 'tab', 'fields' => ['email' => ['type' => 'email'], 'role' => ['type' => 'select']]], 'avatar' => ['type' => 'hidden']];

        expect(W4OS\Views\FormView::locked($form, ['manager'], 150))->toContain('role')->not->toContain('email', 'about')
            ->and(W4OS\Views\FormView::locked($form, ['manager'], 200))->not->toContain('role')
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

    test('decide the access of a visitor by the level the engine gives it (the owner of the grid a god whatever its user level), its user level kept', function () {
        // The owner of the grid at user level 0 (Way Forest), a resident at level 0, a god, an engine before accessLevel
        $owner = Site::accessOf(['level' => 0, 'accessLevel' => 200, 'owner' => true]);
        $resident = Site::accessOf(['level' => 0, 'accessLevel' => 0, 'owner' => false]);

        expect($owner)->toBe(['level' => 200, 'userLevel' => 0, 'owner' => true])
            ->and(Site::readsAdmin($owner['level'], []))->toBeTrue()
            ->and($resident)->toBe(['level' => 0, 'userLevel' => 0, 'owner' => false])
            ->and(Site::readsAdmin($resident['level'], []))->toBeFalse()
            ->and(Site::accessOf(['level' => 250, 'accessLevel' => 250, 'owner' => false])['level'])->toBe(250)
            ->and(Site::accessOf(['level' => 200]))->toBe(['level' => 200, 'userLevel' => 200, 'owner' => false])
            // Site::user() keeps it for every check (Site::level())
            ->and((string) file_get_contents(dirname(__DIR__).'/classes/Site.php'))->toContain('$granted = self::accessOf($avatar);', "self::readsAdmin(\$granted['level'], \$this->config())", "'name' => (string) \$avatar['name']] + \$granted");
    });

    test('the setting is one of the engine\'s levels, never a number of its own', function () {
        expect(array_keys(Site::adminLevels()))->toBe(array_keys(Avatar::LEVELS))
            ->and(Site::adminLevels()[100])->toBe('w4os.role_customer_service')
            ->and(Site::adminLevel([]))->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            // Customer service (100) by default in the settings too, and in their form
            ->and(Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml')['admin_level'])->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and((string) file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->toMatch('/admin_level:\n\s+type: select\n(\s+\S.*\n)*?\s+default: 100\n/')
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
