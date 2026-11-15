<?php

use W4os\Grav\FormError;
use W4os\Grav\Forms;

function forms(array $answers, ?array &$log = null, array $config = []): Forms
{
    return new Forms(fakeEngine($answers, $log), $config, fn (string $type) => "https://site.example.org/$type");
}

function formError(callable $call): string
{
    try {
        $call();
    } catch (FormError $e) {
        return $e->key;
    }

    return 'none';
}

describe('register', function () {
    $fields = ['first_name' => 'Ada', 'last_name' => 'Love', 'email' => 'ada@example.org', 'password' => 'secret12', 'password_confirm' => 'secret12', 'model' => 'Default Ruth'];

    test('makes an inactive avatar that confirms its email', function () use ($fields) {
        $result = forms([[201, avatarDocument(['active' => false]) + ['meta' => ['emailConfirmation' => 'sent']]]], $log)->register($fields);

        expect($result['confirm'])->toBeTrue()
            ->and($result['sent'])->toBeTrue()
            ->and($log[0]['body']['data']['attributes'])->toBe([
                'firstName' => 'Ada', 'lastName' => 'Love', 'email' => 'ada@example.org', 'password' => 'secret12',
                'model' => 'Default Ruth', 'confirmEmail' => true, 'link' => 'https://site.example.org/confirm?token={token}',
            ]);
    });

    test('makes an active avatar when the email is not confirmed', function () use ($fields) {
        forms([[201, avatarDocument()]], $log, ['register' => ['confirm_email' => false]])->register($fields);

        expect($log[0]['body']['data']['attributes'])->toHaveKey('active', true)->not->toHaveKey('confirmEmail');
    });

    test('refuses two different passwords without asking the engine', function () use ($fields) {
        $forms = forms([], $log);
        expect(formError(fn () => $forms->register(['password_confirm' => 'other'] + $fields)))->toBe('PLUGIN_W4OS.ERROR_PASSWORD_MATCH')
            ->and($log)->toBe([]);
    });

    test('tells what the engine refuses', function () use ($fields) {
        try {
            forms([[400, problem(400, "an avatar called 'Ada Love' exists already")]])->register($fields);
        } catch (FormError $e) {
        }

        expect($e->key)->toBe('PLUGIN_W4OS.ERROR_INVALID')->and($e->detail)->toBe("an avatar called 'Ada Love' exists already");
    });
});

describe('sign-in and passwords', function () {
    test('an avatar not active yet is told so', function () {
        expect(formError(fn () => forms([[403, problem(403, 'The account is not active.')]])->signIn('Ada Love', 'x')))->toBe('PLUGIN_W4OS.ERROR_NOT_ACTIVE')
            ->and(formError(fn () => forms([[403, problem(403, 'The name or the password is wrong.')]])->signIn('Ada Love', 'x')))->toBe('PLUGIN_W4OS.ERROR_SIGN_IN')
            ->and(formError(fn () => forms([[503, problem(503, 'down')]])->signIn('Ada Love', 'x')))->toBe('PLUGIN_W4OS.ERROR_UNAVAILABLE');
    });

    test('the forgotten password mails a link to the reset page', function () {
        forms([[202, ['meta' => ['message' => 'sent']]]], $log)->forgot(['login' => 'ada@example.org']);

        expect($log[0]['body']['data']['attributes'])->toBe(['email' => 'ada@example.org', 'link' => 'https://site.example.org/reset?token={token}']);
    });

    test('a token refused is told so', function () {
        expect(formError(fn () => forms([[403, problem(403, 'expired')]])->reset(['token' => 't', 'password' => 'a', 'password_confirm' => 'a'])))->toBe('PLUGIN_W4OS.ERROR_TOKEN')
            ->and(formError(fn () => forms([[403, problem(403, 'expired')]])->confirm('t')))->toBe('PLUGIN_W4OS.ERROR_TOKEN');
    });

    test('a new password needs the current one', function () {
        $avatar = ['uuid' => 'u1', 'name' => 'Ada Love'];
        $forms = forms([[403, problem(403, 'wrong')]], $log);
        expect(formError(fn () => $forms->password($avatar, ['current' => 'bad', 'password' => 'n', 'password_confirm' => 'n'])))->toBe('PLUGIN_W4OS.ERROR_CURRENT_PASSWORD')
            ->and($log)->toHaveCount(1);

        forms([[200, avatarDocument()], [200, avatarDocument()]], $log)->password($avatar, ['current' => 'old', 'password' => 'n', 'password_confirm' => 'n']);
        expect($log[1]['method'])->toBe('PATCH')->and($log[1]['body']['data']['attributes'])->toBe(['password' => 'n']);
    });
});

describe('account', function () {
    $current = ['uuid' => 'u1', 'email' => 'ada@example.org', 'about' => 'Hi', 'languages' => ''];

    test('sends what changed only', function () use ($current) {
        forms([[200, avatarDocument()]], $log)->profile($current, ['email' => 'ada@example.org', 'about' => 'Hello', 'languages' => '']);

        expect($log[0]['url'])->toEndWith('/avatars/u1')->and($log[0]['body']['data']['attributes'])->toBe(['about' => 'Hello']);
    });

    test('asks nothing when nothing changed', function () use ($current) {
        forms([], $log)->profile($current, ['email' => 'ada@example.org', 'about' => 'Hi']);

        expect($log)->toBe([]);
    });

    test('an avatar without a profile yet is told so', function () use ($current) {
        expect(formError(fn () => forms([[503, problem(503, 'cannot change Ada: the avatar has no profile yet: the grid makes it')]])->profile($current, ['about' => 'x'])))->toBe('PLUGIN_W4OS.ERROR_NO_PROFILE');
    });

    test('the managers enable, disable, mail a password link and delete', function () {
        $forms = forms([[200, avatarDocument()], [200, avatarDocument()], [202, []], [204, null]], $log);
        foreach (['enable', 'disable', 'reset', 'delete'] as $action) {
            $forms->manage($action, ['uuid' => 'u1', 'name' => 'Ada Love']);
        }

        expect(array_map(fn ($r) => $r['method'].' '.parse_url($r['url'], PHP_URL_PATH), $log))->toBe([
            'PATCH /helpers/api/avatars/u1', 'PATCH /helpers/api/avatars/u1', 'POST /helpers/api/password-resets', 'DELETE /helpers/api/avatars/u1',
        ])->and($log[1]['body']['data']['attributes'])->toBe(['active' => false])
            ->and(formError(fn () => $forms->manage('promote', ['uuid' => 'u1'])))->toBe('PLUGIN_W4OS.ERROR_INVALID');
    });
});
