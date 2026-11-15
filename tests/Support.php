<?php

use W4os\Grav\Engine;

/**
 * A fake engine API: answers given in order, each [status, document]; the requests are kept in $log.
 *
 * @param list<array{int, array<string,mixed>|null}> $answers
 * @param list<array{method: string, url: string, headers: array<string,string>, body: ?array}> $log
 */
function fakeEngine(array $answers, ?array &$log = null, string $grid = '', string $key = 'osk_test'): Engine
{
    $log = [];

    return new Engine('https://grid.example.org/helpers/api/', $key, $grid, function (string $method, string $url, array $headers, ?string $body) use (&$answers, &$log) {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        [$status, $document] = array_shift($answers) ?? [500, null];

        return [$status, $document === null ? '' : json_encode($document)];
    });
}

/** A JSON:API document of one avatar. */
function avatarDocument(array $attributes = []): array
{
    return ['data' => ['type' => 'avatars', 'id' => 'u1', 'attributes' => $attributes + ['uuid' => 'u1', 'name' => 'Ada Love', 'email' => 'ada@example.org', 'level' => 0]]];
}

function problem(int $status, string $detail, string $type = 'urn:opensim:problem:test'): array
{
    return ['type' => $type, 'title' => 'Problem', 'status' => $status, 'detail' => $detail];
}
