<?php

use OpenSim\Api\Contract;
use W4os\Grav\Lists;
use W4OS\Views\Contract as OpenApi;

describe('a list of the engine', function () {
    // The engine's own document, as /openapi.json gives it
    $document = Contract::document();

    test('is read from its list operation and its x-list', function () use ($document) {
        $list = (new OpenApi($document))->listOf('avatars');
        $name = $list['columns'][0];

        expect(array_column($list['columns'], 'name'))->toBe(['name', 'email', 'role', 'type', 'created', 'lastSeen', 'status'])
            ->and($name)->toMatchArray(['label' => 'avatar_name', 'type' => 'text', 'sort' => 'name', 'picture' => 'profileImage'])
            // Its status (enabled, online) in one indicators column
            ->and(array_column(array_column($list['columns'], null, 'name')['status']['indicators'], 'name'))->toBe(['accountState', 'ban'])
            ->and($list['sort'])->toMatchArray(['default' => '-created', 'descending' => '-'])
            ->and($list['sort']['values'])->toContain('level')
            ->and($list['search'])->toBe(['name', 'email'])
            ->and(array_column($list['filters'], 'name'))->toContain('status', 'role', 'technical')
            ->and(array_column($list['actions'], 'name'))->toBe(['enable', 'disable', 'reset', 'ban', 'unban', 'estateBan', 'estateUnban'])
            ->and((new OpenApi($document))->listOf('nothing'))->toBeNull();
    });

    test('a free filter keeps its format', function () use ($document) {
        $filters = array_column((new OpenApi($document))->listOf('regions')['filters'], null, 'name');

        expect($filters['owner'])->toMatchArray(['format' => 'uuid', 'values' => []])
            ->and($filters['flags'])->toMatchArray(['multiple' => true, 'dynamic' => true])
            ->and($filters['flags']['values'][0])->toBe(['value' => 'DefaultRegion', 'label' => 'region_flag_default_region', 'description' => 'region_flag_default_region_help']);
    });

    test('a filter of several values asks any of them, the ones it has only', function () use ($document) {
        $list = Lists::showing((new OpenApi($document))->listOf('regions'), ['flags', 'simulator']);
        $query = Lists::query($list, ['flags' => ['Hyperlink', 'Nothing', 'DefaultRegion']], 10);

        expect($query['page'])->toBe(['flags' => ['DefaultRegion', 'Hyperlink']])
            ->and($query['api']['filter[flags]'])->toBe('DefaultRegion,Hyperlink')
            ->and(Lists::query($list, ['flags' => 'Persistent,DefaultRegion'], 10)['page']['flags'])->toBe(['DefaultRegion', 'Persistent'])
            ->and(Lists::query($list, ['flags' => 'Nothing'], 10)['page'])->toBe([]);
    });

    test('a dynamic filter offers the values the rows have now, and the ones asked', function () use ($document) {
        $list = Lists::showing((new OpenApi($document))->listOf('regions'), ['flags']);
        $offered = Lists::offering($list, ['available' => ['flags' => ['Persistent', 'DefaultRegion']]], ['flags' => ['Hyperlink']]);

        expect(array_column($offered['filters'][0]['values'], 'value'))->toBe(['DefaultRegion', 'Persistent', 'Hyperlink'])
            ->and(count(Lists::offering($list, [], [])['filters'][0]['values']))->toBe(count($list['filters'][0]['values']));
    });

    test('a dynamic filter the engine lists no values of takes the ones of the rows', function () {
        $list = ['search' => null, 'sort' => ['default' => '', 'values' => [], 'descending' => '-'], 'actions' => [],
            'filters' => [['name' => 'simulator', 'label' => 'filter_simulator', 'values' => [], 'dynamic' => true]]];
        $query = Lists::query($list, ['simulator' => 'grid.welcome'], 10);
        $offered = Lists::offering($list, ['available' => ['simulator' => ['grid.welcome', 'grid.sandbox']]], $query['page']);

        expect($query['api'])->toHaveKey('filter[simulator]', 'grid.welcome')
            ->and($offered['filters'][0]['values'])->toBe([['value' => 'grid.welcome', 'label' => ''], ['value' => 'grid.sandbox', 'label' => '']]);
    });

    test('the simulators of the regions: several, as the rows have them, with their text', function () use ($document) {
        $list = Lists::showing((new OpenApi($document))->listOf('regions'), ['flags', 'simulator']);
        $query = Lists::query($list, ['simulator' => ['192.168.8.69:9010', '192.168.8.69:9020']], 10);
        $meta = ['available' => ['simulator' => [['value' => '192.168.8.69:9010', 'label' => 'Welcome sim']], 'flags' => ['Persistent']]];
        $offered = array_column(Lists::offering($list, $meta, $query['page'])['filters'], null, 'name');

        expect($query['api']['filter[simulator]'])->toBe('192.168.8.69:9010,192.168.8.69:9020')
            ->and($offered['simulator']['values'])->toBe([
                ['value' => '192.168.8.69:9010', 'label' => '', 'text' => 'Welcome sim'],
                ['value' => '192.168.8.69:9020', 'label' => ''],
            ])
            ->and(array_column($offered['flags']['values'], 'value'))->toBe(['Persistent']);
    });

    test('a column gives the labels of its values', function () use ($document) {
        $flags = array_column((new OpenApi($document))->listOf('regions')['columns'], null, 'name')['flagNames'];

        expect(array_column($flags['values'], 'label', 'value'))->toMatchArray(['DefaultRegion' => 'region_flag_default_region'])
            ->and($flags['values'][0])->toHaveKey('description');
    });

    test('a page shows the filters it names, in its order', function () use ($document) {
        $list = Lists::showing((new OpenApi($document))->listOf('avatars'), ['role', 'status', 'nothing']);

        expect(array_column($list['filters'], 'name'))->toBe(['role', 'status']);
    });

    test('builds the query of the engine from the address, what the list allows only', function () use ($document) {
        $list = Lists::showing((new OpenApi($document))->listOf('avatars'), ['status', 'role']);
        $query = Lists::query($list, ['search' => ' ada ', 'status' => 'pending', 'role' => 'emperor', 'sort' => '-level', 'page' => '2'], 20, ['technical' => 'no']);
        $plain = Lists::query($list, ['sort' => 'password', 'technical' => 'yes'], 20);

        expect($query['page'])->toBe(['search' => 'ada', 'status' => 'pending', 'sort' => '-level'])
            ->and($query['api'])->toBe(['page[size]' => 20, 'page[number]' => 2, 'filter[technical]' => 'no', 'sort' => '-level', 'search' => 'ada', 'filter[status]' => 'pending'])
            ->and($plain['page'])->toBe([])
            ->and($plain['api'])->toBe(['page[size]' => 20, 'page[number]' => 1, 'sort' => '-created']);
    });

    test('takes a free filter of its format only', function () use ($document) {
        $list = (new OpenApi($document))->listOf('regions');
        $uuid = '0b7c5c3a-1d2e-4f50-9a6b-7c8d9e0f1a2b';

        expect(Lists::query($list, ['owner' => $uuid], 10)['api'])->toHaveKey('filter[owner]', $uuid)
            ->and(Lists::query($list, ['owner' => 'Ada'], 10)['api'])->not->toHaveKey('filter[owner]')
            ->and(Lists::query($list, ['owner' => "$uuid-x"], 10)['api'])->not->toHaveKey('filter[owner]')
            ->and(Lists::query($list, ['owner' => strtoupper($uuid)], 10)['api'])->toHaveKey('filter[owner]');
    });

});
