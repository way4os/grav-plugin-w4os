<?php

use W4os\Grav\EngineException;

describe('engine', function () {
    test('signs in by name or email, with the key and a JSON:API body', function () {
        $engine = fakeEngine([[200, avatarDocument()], [200, avatarDocument()]], $log);

        expect($engine->signIn(' Ada Love ', 'secret')['uuid'])->toBe('u1');
        $engine->signIn('ada@example.org', 'secret');

        expect($log[0]['method'])->toBe('POST')
            ->and($log[0]['url'])->toBe('https://grid.example.org/helpers/api/sign-ins')
            ->and($log[0]['headers']['Authorization'])->toBe('Bearer osk_test')
            ->and($log[0]['headers']['Content-Type'])->toBe('application/vnd.api+json')
            ->and($log[0]['body'])->toBe(['data' => ['type' => 'sign-ins', 'attributes' => ['name' => 'Ada Love', 'password' => 'secret']]])
            ->and($log[1]['body']['data']['attributes'])->toHaveKey('email', 'ada@example.org');
    });

    test('asks under the grid when the API serves several', function () {
        $engine = fakeEngine([[200, ['data' => []]]], $log, 'demo');
        $engine->items('avatars', ['search' => 'ada', 'page[size]' => 10]);

        expect($log[0]['url'])->toBe('https://grid.example.org/helpers/api/grids/demo/avatars?search=ada&page%5Bsize%5D=10')
            ->and($engine->assetUrl('abc'))->toBe('https://grid.example.org/helpers/api/grids/demo/assets/abc.jpg');
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

    test('a transport failure is a 503', function () {
        $engine = new W4os\Grav\Engine('https://x', 'k', '', fn () => throw new RuntimeException('timeout'));

        expect(fn () => $engine->avatar('u1'))->toThrow(fn (EngineException $e) => expect($e->status)->toBe(503));
    });
});

describe('grid info', function () {
    test('the name of the grid is its gridname', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['elements' => ['gridname' => 'Demo World']]]]]], $log);

        expect($engine->gridName())->toBe('Demo World')->and($log[0]['url'])->toEndWith('/api/info');
    });

    test('the login URI is the uri of the grid info, asked once', function () {
        $engine = fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['uri' => 'grid.example.org:8002', 'elements' => ['gridname' => 'Demo World']]]]]], $log);

        expect($engine->loginUri())->toBe('grid.example.org:8002')->and($engine->gridName())->toBe('Demo World')->and($log)->toHaveCount(1);
    });
});
