<?php

use OpenSim\Models\Avatar;
use W4os\Grav\Site;

describe('managers', function () {
    test('read the admin pages from the level of the setting, customer service by default', function () {
        foreach ([100, 150, 200, 250] as $level) {
            expect(Site::readsAdmin($level, []))->toBeTrue();
        }
        expect(Site::readsAdmin(0, []))->toBeFalse()
            ->and(Site::readsAdmin(1, []))->toBeFalse()
            ->and(Site::readsAdmin(150, ['admin_level' => 200]))->toBeFalse()
            ->and(Site::readsAdmin(200, ['admin_level' => 200]))->toBeTrue();
    });

    test('change only as gods, as the engine says, whatever the setting', function () {
        expect(Site::changesAdmin(150))->toBeFalse()
            ->and(Site::changesAdmin(100))->toBeFalse()
            ->and(Site::changesAdmin(200))->toBeTrue()
            ->and(Site::changesAdmin(250))->toBeTrue();
    });

    test('the setting is one of the engine\'s levels, never a number of its own', function () {
        expect(array_keys(Site::adminLevels()))->toBe(array_values(array_filter(array_keys(Avatar::LEVELS), fn ($level) => $level > Avatar::GOD_NOT)))
            ->and(Site::adminLevels()[100])->toBe('w4os.role_customer_service')
            ->and(Site::adminLevel([]))->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and(Site::adminLevel(['admin_level' => '150']))->toBe(150)
            ->and(Site::adminLevel(['admin_level' => 180]))->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and(Site::adminLevel(['admin_level' => 0]))->toBe(Avatar::GOD_CUSTOMER_SERVICE);
    });

    test('a setting of an older version (manager_level) is not one any more', function () {
        $config = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml');

        expect($config)->not->toHaveKey('manager_level')->toHaveKey('admin_level', 100)
            ->and(file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->not->toContain('manager_level')->toContain('admin_level');
    });
});
