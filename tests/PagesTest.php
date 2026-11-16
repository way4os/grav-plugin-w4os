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
        $used = array_diff(array_unique($used), ['manage', 'action', 'error']);
        expect($used)->not->toBeEmpty();
        // Every problem code of the engine has its text
        $used = array_merge($used, array_map(fn (string $code) => 'error_'.str_replace('-', '_', $code), W4os\Grav\Forms::CODES));
        // Every label of the lists of the engine (their columns, filters, values, actions), the texts of their statuses and the
        // message of an action once done
        $document = OpenSim\Api\Contract::document();
        $types = array_filter(array_map(fn ($path) => preg_match('#^/grids/\{grid\}/([a-z-]+)$#', $path, $m) === 1 ? $m[1] : null, array_keys($document['paths'])));
        expect($types)->toContain('avatars', 'regions');
        foreach ($types as $type) {
            if (($list = (new W4os\Grav\OpenApi($document))->listOf($type)) === null || $list['columns'] === []) {
                continue;
            }
            $used = array_merge($used, array_column($list['columns'], 'label'), array_filter(array_column($list['columns'], 'tagLabel')), array_column($list['filters'], 'label'));
            foreach ($list['filters'] as $filter) {
                $used = array_merge($used, array_filter(array_column($filter['values'], 'label')));
                // A status column without labels of its own shows the texts status_<value> of the values of its filter
                $status = array_filter($list['columns'], fn ($column) => $column['type'] === 'status' && $column['name'] === $filter['name'] && ! isset($column['values']));
                $status === [] || array_push($used, ...array_map(fn ($value) => 'status_'.$value['value'], $filter['values']));
            }
            foreach ($list['actions'] as $action) {
                array_push($used, $action['label'], 'manage_'.$action['name']);
            }
        }
        // Every label of the pages of the items (their groups, fields and choices)
        foreach (['avatars', 'regions'] as $type) {
            $item = (new W4os\Grav\OpenApi($document))->itemOf($type);
            $used = array_merge($used, array_column($item['groups'], 'label'), array_column($item['fields'], 'label'));
            foreach ($item['fields'] as $field) {
                $used = array_merge($used, array_values((array) ($field['options'] ?? [])));
            }
        }
        foreach ($used as $key) {
            foreach ($languages as $texts) {
                expect($texts)->toHaveKey($key);
            }
        }
        foreach ($languages as $texts) {
            expect(array_keys($texts))->toEqualCanonicalizing(array_keys($languages['en']));
            // The same values put in each text (%s, %1$s...)
            foreach ($texts as $key => $text) {
                preg_match_all('/%(\d\$)?s/', $text, $mine);
                preg_match_all('/%(\d\$)?s/', $languages['en'][$key], $english);
                expect(count($mine[0]))->toBe(count($english[0]), $key);
            }
        }
    });
});
