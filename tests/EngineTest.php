<?php

use W4OS\Grav\EngineException;

describe('engine', function () {
    test('signs in by name or email, with the key and a JSON:API body', function () {
        $engine = fakeEngine([[200, avatarDocument()], [200, avatarDocument()]], $log);

        expect($engine->signIn(' Ada Love ', 'secret')['uuid'])->toBe('u1');
        $engine->signIn('ada@example.org', 'secret');

        expect($log[0]['method'])->toBe('POST')
            ->and($log[0]['url'])->toBe('https://grid.example.org/api/sign-ins')
            ->and($log[0]['headers']['Authorization'])->toBe('Bearer osk_test')
            ->and($log[0]['headers']['Content-Type'])->toBe('application/vnd.api+json')
            ->and($log[0]['body'])->toBe(['data' => ['type' => 'sign-ins', 'attributes' => ['name' => 'Ada Love', 'password' => 'secret']]])
            ->and($log[1]['body']['data']['attributes'])->toHaveKey('email', 'ada@example.org');
    });

    test('asks under the grid when the API serves several', function () {
        $engine = fakeEngine([[200, ['data' => []]]], $log, 'demo');
        $engine->items('avatars', ['search' => 'ada', 'page[size]' => 10]);

        expect($log[0]['url'])->toBe('https://grid.example.org/api/grids/demo/avatars?search=ada&page%5Bsize%5D=10')
            ->and($engine->assetUrl('abc'))->toBe('https://grid.example.org/api/grids/demo/assets/abc.jpg');
    });

    test('asks what belongs to the machine (its simulators) outside the grid', function () {
        $simulator = ['type' => 'simulators', 'id' => 'h:9040', 'attributes' => ['slug' => 'demo.solo']];
        $engine = fakeEngine([[200, ['data' => [$simulator]]], [200, ['data' => $simulator]], [200, ['data' => $simulator]]], $log, 'demo');
        [$items] = $engine->items('simulators', ['filter[grid]' => 'demo'], grid: false);
        $engine->item('simulators', 'h:9040', grid: false);

        // A row whose attributes have no id of their own (a simulator) keeps the one of its resource
        expect($items[0]['slug'])->toBe('demo.solo')
            ->and($items[0]['id'])->toBe('h:9040')
            ->and($engine->item('simulators', 'h:9040', grid: false)['id'] ?? null)->toBe('h:9040')
            ->and($log[0]['url'])->toBe('https://grid.example.org/api/simulators?filter%5Bgrid%5D=demo')
            ->and($log[1]['url'])->toBe('https://grid.example.org/api/simulators/h%3A9040')
            ->and($engine->grid())->toBe('demo');
    });

    test('throws the problem the API answers', function () {
        $engine = fakeEngine([[403, problem(403, 'The name or the password is wrong.', 'sign-in-refused')], [503, problem(503, 'down')]]);

        expect(fn () => $engine->signIn('Ada Love', 'wrong'))->toThrow(function (EngineException $e) {
            expect($e->status)->toBe(403)
                ->and($e->problemCode)->toBe('sign-in-refused')
                ->and($e->getMessage())->toBe('The name or the password is wrong.');
        })->and(fn () => $engine->avatar('u1'))->toThrow(fn (EngineException $e) => expect($e->problemCode)->toBe('channel-unavailable'));
    });

    test('is not ready without an address or a key', function () {
        $engine = fakeEngine([], $log, key: '');

        expect($engine->ready())->toBeFalse()
            ->and(fn () => $engine->models())->toThrow(EngineException::class)
            ->and($log)->toBe([]);
    });

    test('a grid without models has none', function () {
        expect(fakeEngine([[404, problem(404, 'none')]])->models())->toBe([]);
    });

    test('asks the models another rule picks by its filters (the preview of the rule)', function () {
        $engine = fakeEngine([[200, ['data' => [['type' => 'avatar-models', 'id' => 'm1', 'attributes' => ['name' => 'Default Ruth']]]]]], $log, grid: 'demo');

        expect($engine->models(['match' => 'uuid', 'list' => 'm1,m2'])[0]['name'])->toBe('Default Ruth')
            ->and(urldecode($log[0]['url']))->toBe('https://grid.example.org/api/grids/demo/avatar-models?filter[match]=uuid&filter[list]=m1,m2');
    });

    test('asks the health of the grid checked now, or as it was last checked', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'health', 'id' => 'demo', 'attributes' => ['level' => 'ok', 'checkedAt' => 1795058484]]]], [200, ['data' => ['type' => 'health', 'id' => 'demo', 'attributes' => ['level' => 'ok', 'checkedAt' => 1795058400]]]]], $log, grid: 'demo');

        expect($engine->health(true)['checkedAt'])->toBe(1795058484)
            ->and($log[0]['url'])->toBe('https://grid.example.org/api/grids/demo/health?refresh=1')
            ->and($engine->health()['checkedAt'])->toBe(1795058400)
            ->and($log[1]['url'])->toBe('https://grid.example.org/api/grids/demo/health');
    });

    test('checks one line of the health now', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'health-checks', 'id' => 'l1', 'attributes' => ['label' => 'login', 'kind' => 'address', 'level' => 'ok', 'checkedAt' => 1795059606]]]]], $log, grid: 'demo');

        expect($engine->healthCheck('l1'))->toMatchArray(['id' => 'l1', 'label' => 'login', 'checkedAt' => 1795059606])
            ->and($log[0]['url'])->toBe('https://grid.example.org/api/grids/demo/health/l1');
    });

    test('a transport failure is a 503', function () {
        $engine = new W4OS\Grav\Engine('https://x', 'k', '', fn () => throw new RuntimeException('timeout'));

        expect(fn () => $engine->avatar('u1'))->toThrow(fn (EngineException $e) => expect($e->status)->toBe(503));
    });
});

describe('grid info', function () {
    test('the nick of the grid is the one the site asks, else its gridnick', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['elements' => ['gridnick' => 'demo']]]]]], $log);

        expect($engine->gridNick())->toBe('demo');
    });

    test('the name of the grid is its gridname', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['elements' => ['gridname' => 'Demo World']]]]]], $log);

        expect($engine->gridName())->toBe('Demo World')->and($log[0]['url'])->toEndWith('/api/info');
    });

    test('the login URI is the uri of the grid info, asked once', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['uri' => 'grid.example.org:8002', 'elements' => ['gridname' => 'Demo World']]]]]], $log);

        expect($engine->loginUri())->toBe('grid.example.org:8002')->and($engine->gridName())->toBe('Demo World')->and($log)->toHaveCount(1);
    });
});

describe('the address of a remote engine', function () {
    test('is its own setting, never the /api of the helpers', function () {
        expect(W4OS\Grav\Site::apiUrl(['url' => 'https://grid.example.org/helpers']))->toBe('')
            ->and(W4OS\Grav\Site::apiUrl(['url' => 'https://grid.example.org/helpers', 'api_url' => ' http://127.0.0.1:8780/api ']))->toBe('http://127.0.0.1:8780/api')
            ->and(W4OS\Grav\Site::apiUrl([]))->toBe('');
    });

    test('shows the pictures of the grid at the public address of its helpers, never at the API\'s', function () {
        expect(W4OS\Grav\Site::picturesUrl(['url' => 'https://grid.example.org/helpers/', 'api_url' => 'http://127.0.0.1:8780/api']))->toBe('https://grid.example.org/helpers/pictures')
            ->and(W4OS\Grav\Site::picturesUrl(['api_url' => 'https://grid.example.org/api']))->toBe('');
    });

    test('waits for the end of a change (an instance restarted), not for a read', function () {
        expect(W4OS\Grav\Site::timeout('GET'))->toBe(15)
            ->and(W4OS\Grav\Site::timeout('POST'))->toBeGreaterThanOrEqual(300)
            ->and(W4OS\Grav\Site::timeout('patch'))->toBe(W4OS\Grav\Site::timeout('POST'));
    });
});
