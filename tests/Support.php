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

    return new Engine('https://grid.example.org/api/', $key, $grid, function (string $method, string $url, array $headers, ?string $body) use (&$answers, &$log) {
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

/** The JSON:API document of a registration: a new avatar whose email is to be confirmed, not made yet (202). */
function registrationDocument(array $meta = []): array
{
    return ['data' => ['type' => 'registrations', 'id' => 'u1', 'attributes' => [
        'avatar' => 'u1', 'name' => 'Ada Love', 'firstName' => 'Ada', 'lastName' => 'Love', 'email' => 'ada@example.org', 'model' => 'Default Ruth',
        'expires' => 1900000000,
    ]], 'meta' => $meta];
}

/** A problem of the engine, with its code (Problem::CODES); none, the engine's code of the status is used. */
function problem(int $status, string $detail, ?string $code = null): array
{
    return ['type' => 'urn:opensim:problem:test', 'title' => 'Problem', 'status' => $status, 'detail' => $detail] + ($code === null ? [] : ['code' => $code]);
}
