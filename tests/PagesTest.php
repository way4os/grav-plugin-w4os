<?php

use Symfony\Component\Yaml\Yaml;

describe('pages', function () {
    $languages = Yaml::parseFile(dirname(__DIR__).'/languages.yaml');

    test('every page has a front matter Grav can read, and its texts in English and French', function () use ($languages) {
        foreach (glob(dirname(__DIR__).'/pages/*.md') as $file) {
            $header = Yaml::parse(explode("---\n", (string) file_get_contents($file))[1] ?? '');
            expect($header)->toBeArray()->toHaveKey('w4os');
            preg_match_all('/PLUGIN_W4OS\.([A-Z_]+)/', json_encode($header), $keys);
            foreach (array_unique($keys[1]) as $key) {
                expect($languages['en']['PLUGIN_W4OS'])->toHaveKey($key)
                    ->and($languages['fr']['PLUGIN_W4OS'])->toHaveKey($key);
            }
        }
    });

    test('every text used by the code and the templates is in English and French', function () use ($languages) {
        $sources = array_merge(glob(dirname(__DIR__).'/{*.php,classes/*.php,templates/*/*.twig,templates/*.twig,templates/forms/fields/*/*.twig}', GLOB_BRACE));
        $used = [];
        foreach ($sources as $file) {
            preg_match_all('/PLUGIN_W4OS\.([A-Z][A-Z_]+[A-Z])/', (string) file_get_contents($file), $keys);
            $used = array_merge($used, $keys[1]);
        }
        // Keys built from a variable: the titles of the pages, the actions and messages of the managers
        $used = array_diff(array_unique($used), ['MANAGE', 'ACTION']);
        expect($used)->not->toBeEmpty();
        foreach ($used as $key) {
            expect($languages['en']['PLUGIN_W4OS'])->toHaveKey($key)
                ->and($languages['fr']['PLUGIN_W4OS'])->toHaveKey($key);
        }
        expect(array_keys($languages['fr']['PLUGIN_W4OS']))->toEqualCanonicalizing(array_keys($languages['en']['PLUGIN_W4OS']));
    });
});
