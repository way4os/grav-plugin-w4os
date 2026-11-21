<?php

use W4OS\Engine\I18n\Catalog;
use W4OS\Grav\Forms;

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

    test('each file reads back every text as the catalog has it, on several lines and with quotes included', function () {
        $folder = sys_get_temp_dir().'/w4os-languages-'.bin2hex(random_bytes(4));
        exec('php '.escapeshellarg(dirname(__DIR__).'/dev/languages.php').' '.escapeshellarg($folder));
        $texts = ['en' => Catalog::messages('en'), 'cy' => array_merge(Catalog::messages('en'), Catalog::messages('cy_GB'))];
        foreach ($texts as $lang => $catalog) {
            $read = (array) Symfony\Component\Yaml\Yaml::parseFile("$folder/$lang.yaml")['w4os'];
            foreach ($catalog as $key => $text) {
                expect($read[$key] ?? null)->toBe($text, "$lang $key");
            }
        }
        exec('rm -rf '.escapeshellarg($folder));
    });

    test('every problem code of the engine has its text, under the key the catalog gives it', function () {
        expect(Catalog::missingCodes())->toBe([])
            ->and(Forms::CODES)->toEqualCanonicalizing(array_keys(W4OS\Engine\Api\Problem::CODES));
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

    test('every text of the plugin is in each of its languages, with the same placeholders', function () {
        $read = fn (string $lang) => (array) Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__)."/i18n/$lang.yaml")['w4os'];
        $placeholders = fn (string $text) => preg_match_all('/%[sd]/', $text, $found) ? $found[0] : [];
        $english = $read('en');
        foreach (['fr', 'de', 'it', 'nl', 'pt-BR', 'cy'] as $lang) {
            $texts = $read($lang);
            expect(array_keys($texts))->toEqualCanonicalizing(array_keys($english), "$lang has not every text");
            foreach ($english as $key => $text) {
                expect($placeholders($texts[$key]))->toBe($placeholders($text), "$lang $key");
            }
        }
    });

    test('the texts of the plugin follow the words of the engine: viewer and helpers kept, no word of another meaning', function () {
        $read = fn (string $lang) => (array) Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__)."/i18n/$lang.yaml")['w4os'];
        // The patterns of the catalog test of the engine: a port of ships, a raster or a grate for the grid, an assistant for the
        // helpers, a projector for the viewer
        $wrong = [
            'cy' => '/porthladd|cynorthwy|\\bg?wyl(iwr|wyr)\\b|golygydd/iu',
            'de' => '/\\b(Hafen|Raster)\\b|Helfer|Assistent|Betrachter/u',
            'fr' => '/\\bassistants?\\b|visionneuse|visualiseur/iu',
            'it' => '/\\bporto\\b|\\b(aiutant|assistent|visualizzator)\\w*/iu',
            'nl' => '/\\b(haven|raster|rooster|kijker)\\b|assistent/iu',
            'pt-BR' => '/\\b(porto|grade|visualizador|ajudantes?|assistentes?|auxiliar(es)?)\\b/iu',
        ];
        $english = $read('en');
        foreach ($wrong as $lang => $pattern) {
            $texts = $read($lang);
            expect(array_filter($texts, fn (string $text) => preg_match($pattern, $text) === 1))->toBe([], $lang);
            foreach (['viewer', 'helper'] as $term) {
                $renamed = array_filter($texts, fn (string $text, string $key) => stripos($english[$key], $term) !== false && stripos($text, $term) === false, ARRAY_FILTER_USE_BOTH);
                expect($renamed)->toBe([], "$lang $term");
            }
        }
    });
});
