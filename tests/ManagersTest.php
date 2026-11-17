<?php

use W4os\Grav\Site;

describe('managers', function () {
    test('are the gods of the grid, as the engine says (its user levels: each grants the rights below it)', function () {
        expect(Site::isManagerLevel(200))->toBeTrue()
            ->and(Site::isManagerLevel(250))->toBeTrue()
            ->and(Site::isManagerLevel(150))->toBeFalse()
            ->and(Site::isManagerLevel(100))->toBeFalse()
            ->and(Site::isManagerLevel(0))->toBeFalse();
    });

    test('a setting of an older version (manager_level) is not one any more', function () {
        $config = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml');

        expect($config)->not->toHaveKey('manager_level')
            ->and(file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->not->toContain('manager_level');
    });
});
