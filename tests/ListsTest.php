<?php

use OpenSim\Api\Contract;
use W4os\Grav\Lists;
use W4os\Grav\OpenApi;

describe('a list of the engine', function () {
    // The engine's own document, as /openapi.json gives it
    $document = Contract::document();

    test('is read from its list operation and its x-list', function () use ($document) {
        $list = (new OpenApi($document))->listOf('avatars');
        $name = $list['columns'][0];

        expect(array_column($list['columns'], 'name'))->toBe(['name', 'email', 'role', 'lastSeen', 'created', 'status'])
            ->and($name)->toMatchArray(['label' => 'avatar_name', 'type' => 'text', 'sort' => 'name', 'picture' => 'profileImage', 'tag' => 'online'])
            ->and($list['sort'])->toMatchArray(['default' => '-created', 'descending' => '-'])
            ->and($list['sort']['values'])->toContain('level')
            ->and($list['search'])->toBe(['name', 'email'])
            ->and(array_column($list['filters'], 'name'))->toContain('status', 'role', 'technical')
            ->and(array_column($list['actions'], 'name'))->toBe(['enable', 'disable', 'reset'])
            ->and((new OpenApi($document))->listOf('nothing'))->toBeNull();
    });

    test('a free filter keeps its format', function () use ($document) {
        $filters = array_column((new OpenApi($document))->listOf('regions')['filters'], null, 'name');

        expect($filters['owner'])->toMatchArray(['format' => 'uuid', 'values' => []])
            ->and($filters['online']['values'][0])->toBe(['value' => 'yes', 'label' => 'yes']);
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
            ->and(Lists::query($list, ['owner' => 'Ada'], 10)['api'])->not->toHaveKey('filter[owner]');
    });

    test('gives a row the actions it allows', function () use ($document) {
        $list = (new OpenApi($document))->listOf('avatars');
        $names = fn (array $item, ?string $self = null) => array_column(Lists::actions($list, $item, $self), 'name');
        $regions = (new OpenApi($document))->listOf('regions');

        expect($names(['uuid' => 'u1', 'status' => 'active', 'email' => 'ada@example.org']))->toBe(['disable', 'reset'])
            ->and($names(['uuid' => 'u1', 'status' => 'active', 'email' => '']))->toBe(['disable'])
            ->and($names(['uuid' => 'u1', 'status' => 'active', 'email' => 'ada@example.org'], 'u1'))->toBe(['reset'])
            ->and($names(['uuid' => 'u1', 'status' => 'pending', 'email' => 'ada@example.org']))->toBe(['enable'])
            ->and(array_column(Lists::actions($regions, ['uuid' => 'r1', 'enabled' => false]), 'name'))->toBe(['enable'])
            ->and(array_column(Lists::actions($regions, ['uuid' => 'r1', 'enabled' => null]), 'name'))->toBe([]);
    });

    test('makes the request of the engine for an action on a row', function () use ($document) {
        $actions = array_column((new OpenApi($document))->listOf('avatars')['actions'], null, 'name');
        $ada = ['uuid' => 'u1', 'name' => 'Ada Love', 'status' => 'active'];

        expect(Lists::request($actions['disable'], $ada))->toBe(['PATCH', '/avatars/u1', ['type' => 'avatars', 'id' => 'u1', 'attributes' => ['status' => 'disabled']]])
            ->and(Lists::request($actions['reset'], $ada, ['link' => 'https://site.example.org/reset?token={token}']))
            ->toBe(['POST', '/password-resets', ['type' => 'password-resets', 'attributes' => ['name' => 'Ada Love', 'link' => 'https://site.example.org/reset?token={token}']]]);
    });
});
