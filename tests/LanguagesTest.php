<?php

use OpenSim\I18n\Catalog;
use W4os\Grav\Forms;

describe('languages', function () {
    test('the files are the ones the catalog of the engine and i18n/ give (composer languages)', function () {
        $folder = sys_get_temp_dir().'/w4os-languages-'.bin2hex(random_bytes(4));
        exec('php '.escapeshellarg(dirname(__DIR__).'/dev/languages.php').' '.escapeshellarg($folder), $output, $status);

        expect($status)->toBe(0);
        foreach (glob("$folder/*.yaml") as $file) {
            expect(file_get_contents(dirname(__DIR__).'/languages/'.basename($file)))->toBe(file_get_contents($file), basename($file).' is not up to date');
        }
        exec('rm -rf '.escapeshellarg($folder));
    });

    test('every problem code of the engine has its text, under the key the catalog gives it', function () {
        expect(Catalog::missingCodes())->toBe([])
            ->and(Forms::CODES)->toEqualCanonicalizing(array_keys(OpenSim\Api\Problem::CODES));
        foreach (Forms::CODES as $code) {
            expect('error_'.str_replace('-', '_', $code))->toBe(Catalog::errorKey($code))
                ->and(Catalog::MESSAGES)->toHaveKey(Catalog::errorKey($code));
        }
    });

    test('the plugin keeps only what is Grav\'s alone', function () {
        foreach (glob(dirname(__DIR__).'/i18n/*.yaml') as $file) {
            foreach (array_keys(Symfony\Component\Yaml\Yaml::parseFile($file)['w4os']) as $key) {
                expect($key)->toStartWith('admin_')->and(Catalog::MESSAGES)->not->toHaveKey($key);
            }
        }
    });
});
