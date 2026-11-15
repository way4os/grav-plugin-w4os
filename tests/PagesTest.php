<?php

use Symfony\Component\Yaml\Yaml;

describe('pages', function () {
    // Every language file of the plugin (languages/<lang>.yaml), by its language
    $languages = [];
    foreach (glob(dirname(__DIR__).'/languages/*.yaml') as $file) {
        $languages[basename($file, '.yaml')] = Yaml::parseFile($file)['w4os'];
    }

    test('every page has a front matter Grav can read, and its texts in every language', function () use ($languages) {
        foreach (glob(dirname(__DIR__).'/pages/*.md') as $file) {
            $header = Yaml::parse(explode("---\n", (string) file_get_contents($file))[1] ?? '');
            expect($header)->toBeArray()->toHaveKey('w4os');
            preg_match_all('/w4os\.([a-z][a-z0-9_]*[a-z0-9])/', json_encode($header), $keys);
            foreach (array_unique($keys[1]) as $key) {
                foreach ($languages as $texts) {
                    expect($texts)->toHaveKey($key);
                }
            }
        }
    });

    test('every text used by the code and the templates is in every language file', function () use ($languages) {
        $sources = array_merge(glob(dirname(__DIR__).'/{*.php,classes/*.php,templates/*/*.twig,templates/*.twig,templates/forms/fields/*/*.twig}', GLOB_BRACE));
        $used = [];
        foreach ($sources as $file) {
            preg_match_all('/[\'"]w4os\.([a-z][a-z0-9_]*[a-z0-9])/', (string) file_get_contents($file), $keys);
            $used = array_merge($used, $keys[1]);
        }
        // Keys built from a variable: the titles of the pages, the actions and messages of the managers
        $used = array_diff(array_unique($used), ['manage', 'action']);
        expect($used)->not->toBeEmpty();
        foreach ($used as $key) {
            foreach ($languages as $texts) {
                expect($texts)->toHaveKey($key);
            }
        }
        foreach ($languages as $texts) {
            expect(array_keys($texts))->toEqualCanonicalizing(array_keys($languages['en']));
        }
    });
});
