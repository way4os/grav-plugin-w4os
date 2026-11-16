<?php

use W4os\Grav\Lists;
use W4os\Grav\OpenApi;

/** An engine's document with the list of the avatars as session A describes it (x-list), and the regions without one. */
function lists_document(): array
{
    $filter = fn (string $name, array $values) => ['name' => "filter[$name]", 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => $values]];

    return [
        'openapi' => '3.1.0',
        'components' => ['parameters' => ['search' => ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]]],
        'paths' => [
            '/grids/{grid}/avatars' => ['get' => [
                'parameters' => [
                    ['$ref' => '#/components/parameters/search'],
                    ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['name', '-name', 'created', '-created']]],
                    ['name' => 'page[size]', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 50]],
                    $filter('status', ['active', 'disabled', 'pending']),
                    $filter('role', ['resident', 'god']),
                    $filter('technical', ['yes', 'no']),
                ],
                'x-list' => [
                    'columns' => [
                        ['name' => 'name', 'key' => 'avatar_name', 'type' => 'text', 'picture' => 'profileImage', 'tag' => 'online'],
                        ['name' => 'created', 'type' => 'date'],
                        ['name' => 'status', 'type' => 'status'],
                    ],
                    'filters' => [
                        ['name' => 'status', 'key' => 'status', 'values' => ['active' => 'status_active']],
                        ['name' => 'role', 'key' => 'role'],
                    ],
                    'search' => ['name', 'email'],
                    'actions' => [
                        ['name' => 'disable', 'when' => ['status' => ['active']]],
                        ['name' => 'enable', 'when' => ['status' => ['disabled', 'pending']]],
                        ['name' => 'reset', 'when' => ['status' => ['active'], 'email' => true]],
                    ],
                ],
            ]],
            '/grids/{grid}/regions' => ['get' => ['parameters' => [
                ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string']],
                $filter('online', ['yes', 'no']),
            ]]],
        ],
    ];
}

describe('A list of the engine', function () {
    test('is read from its list operation and its x-list', function () {
        $list = (new OpenApi(lists_document()))->listOf('avatars');

        expect(array_column($list['columns'], 'name'))->toBe(['name', 'created', 'status'])
            ->and($list['columns'][1]['key'])->toBe('column_created')
            ->and($list['columns'][0]['picture'])->toBe('profileImage')
            ->and($list['sorts'])->toBe(['name', 'created'])
            ->and(array_column($list['filters'], 'name'))->toBe(['status', 'role'])
            ->and($list['filters'][0]['values'][0])->toBe(['value' => 'active', 'key' => 'status_active'])
            ->and($list['filters'][1]['values'][1])->toBe(['value' => 'god', 'key' => ''])
            ->and($list['search'])->toBe(['name', 'email'])
            ->and($list['pageSize'])->toBe(50)
            ->and((new OpenApi(lists_document()))->listOf('parcels'))->toBeNull();
    });

    test('without an x-list, shows every filter with values and no column', function () {
        $list = (new OpenApi(lists_document()))->listOf('regions');

        expect($list['columns'])->toBe([])->and(array_column($list['filters'], 'name'))->toBe(['online'])->and($list['search'])->toBeNull();
    });

    test('builds the query of the engine from the address, what the list allows only', function () {
        $list = (new OpenApi(lists_document()))->listOf('avatars');
        $query = Lists::query($list, ['search' => ' ada ', 'status' => 'pending', 'role' => 'emperor', 'sort' => '-name', 'page' => '2'], 20, ['filter[technical]' => 'no'], '-created');
        $plain = Lists::query($list, ['sort' => 'email'], 20, [], '-created');

        expect($query['page'])->toBe(['search' => 'ada', 'status' => 'pending', 'sort' => '-name'])
            ->and($query['api'])->toBe(['page[size]' => 20, 'page[number]' => 2, 'filter[technical]' => 'no', 'sort' => '-name', 'search' => 'ada', 'filter[status]' => 'pending'])
            ->and($plain['page'])->toBe([])->and($plain['api']['sort'])->toBe('-created');
    });

    test('gives a row the actions its attributes allow', function () {
        $list = (new OpenApi(lists_document()))->listOf('avatars');
        $names = fn (array $item) => array_column(Lists::actions($list, $item), 'name');

        expect($names(['status' => 'active', 'email' => 'ada@example.org']))->toBe(['disable', 'reset'])
            ->and($names(['status' => 'active', 'email' => '']))->toBe(['disable'])
            ->and($names(['status' => 'pending']))->toBe(['enable']);
    });
});
