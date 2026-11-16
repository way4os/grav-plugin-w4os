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
        expect(formError(fn () => $forms->register(['password_confirm' => 'other'] + $fields)))->toBe('w4os.error_password_match')
            ->and($log)->toBe([]);
    });

    test('tells what the engine refuses by its code, in the language of the site', function () use ($fields) {
        $refused = fn (string $code) => formError(fn () => forms([[400, problem(400, 'refused', $code)]])->register($fields));

        expect($refused('name-taken'))->toBe('w4os.error_name_taken')
            ->and($refused('name-reserved'))->toBe('w4os.error_name_reserved')
            ->and($refused('password-invalid'))->toBe('w4os.error_password_invalid')
            ->and($refused('model-unknown'))->toBe('w4os.error_model_unknown');
    });

    test('keeps what the engine says, for the log', function () use ($fields) {
        try {
            forms([[400, problem(400, "'x' is not a UUID", 'invalid-request')]])->register($fields);
        } catch (FormError $e) {
        }

        expect($e->key)->toBe('w4os.error_invalid_request')->and($e->detail)->toBe("'x' is not a UUID");
    });

    test('on a test site, mails nothing and gives the link', function () use ($fields) {
        $answer = avatarDocument(['active' => false]) + ['meta' => ['emailConfirmation' => 'given', 'confirmation' => ['token' => 't', 'link' => 'https://site.example.org/confirm?token=t']]];
        $result = forms([[201, $answer]], $log, ['send_mail' => false])->register($fields);

        expect($log[0]['body']['data']['attributes'])->toHaveKey('send', false)
            ->and($result['sent'])->toBeFalse()
            ->and($result['link'])->toBe('https://site.example.org/confirm?token=t');
    });
});

describe('sign-in and passwords', function () {
    test('a refused sign-in says why', function () {
        $refused = fn (int $status, ?string $code) => formError(fn () => forms([[$status, problem($status, 'refused', $code)]])->signIn('Ada Love', 'x'));

        expect($refused(403, 'email-unconfirmed'))->toBe('w4os.error_email_unconfirmed')
            ->and($refused(403, 'account-disabled'))->toBe('w4os.error_account_disabled')
            ->and($refused(403, 'sign-in-refused'))->toBe('w4os.error_sign_in_refused')
            ->and($refused(503, null))->toBe('w4os.error_channel_unavailable');
    });

    test('the forgotten password mails a link to the reset page', function () {
        forms([[202, ['meta' => ['message' => 'sent']]]], $log)->forgot(['login' => 'ada@example.org']);

        expect($log[0]['body']['data']['attributes'])->toBe(['email' => 'ada@example.org', 'link' => 'https://site.example.org/reset?token={token}']);
    });

    test('on a test site, the forgotten password mails nothing and shows nothing', function () {
        forms([[200, ['data' => [['attributes' => ['token' => 't']]]]], [404, problem(404, 'nobody', 'not-found')]], $log, ['send_mail' => false])->forgot(['login' => 'Ada Love']);

        expect($log[0]['body']['data']['attributes'])->toHaveKey('send', false)
            ->and(formError(fn () => forms([[404, problem(404, 'nobody', 'not-found')]], config: ['send_mail' => false])->forgot(['login' => 'Nobody'])))->toBe('none');
    });

    test('a token refused is told so', function () {
        expect(formError(fn () => forms([[403, problem(403, 'expired', 'token-refused')]])->reset(['token' => 't', 'password' => 'a', 'password_confirm' => 'a'])))->toBe('w4os.error_token_refused')
            ->and(formError(fn () => forms([[403, problem(403, 'expired', 'token-refused')]])->confirm('t')))->toBe('w4os.error_token_refused');
    });

    test('a new password needs the current one', function () {
        $avatar = ['uuid' => 'u1', 'name' => 'Ada Love'];
        $forms = forms([[403, problem(403, 'wrong', 'sign-in-refused')]], $log);
        expect(formError(fn () => $forms->password($avatar, ['current' => 'bad', 'password' => 'n', 'password_confirm' => 'n'])))->toBe('w4os.error_current_password')
            ->and($log)->toHaveCount(1);

        forms([[200, avatarDocument()], [200, avatarDocument()]], $log)->password($avatar, ['current' => 'old', 'password' => 'n', 'password_confirm' => 'n']);
        expect($log[1]['method'])->toBe('PATCH')->and($log[1]['body']['data']['attributes'])->toBe(['password' => 'n']);
    });
});

describe('account', function () {
    $current = ['uuid' => 'u1', 'email' => 'ada@example.org', 'about' => 'Hi', 'languages' => ''];

    test('sends what changed only', function () use ($current) {
        $result = forms([[200, avatarDocument()]], $log)->profile($current, ['email' => 'ada@example.org', 'about' => 'Hello', 'languages' => '']);

        expect($log[0]['url'])->toEndWith('/avatars/u1')
            ->and($log[0]['body']['data']['attributes'])->toBe(['about' => 'Hello'])
            ->and($result['email'])->toBeNull();
    });

    test('a new email waits for the link mailed to it', function () use ($current) {
        $result = forms([[200, avatarDocument() + ['meta' => ['emailConfirmation' => 'sent']]]], $log)->profile($current, ['email' => 'new@example.org', 'about' => 'Hi']);

        expect($log[0]['body']['data']['attributes'])->toBe(['email' => 'new@example.org', 'confirmEmail' => true, 'link' => 'https://site.example.org/confirm?change=1&token={token}'])
            ->and($result['email'])->toBe('new@example.org')
            ->and($result['link'])->toBeNull();
    });

    test('asks nothing when nothing changed', function () use ($current) {
        forms([], $log)->profile($current, ['email' => 'ada@example.org', 'about' => 'Hi']);

        expect($log)->toBe([]);
    });

    test('the managers enable, disable and mail a password link', function () {
        $forms = forms([[200, avatarDocument()], [200, avatarDocument()], [202, ['meta' => []]]], $log);
        foreach (['enable', 'disable', 'reset'] as $action) {
            $forms->manage($action, ['uuid' => 'u1', 'name' => 'Ada Love']);
        }

        expect(array_map(fn ($r) => $r['method'].' '.parse_url($r['url'], PHP_URL_PATH), $log))->toBe([
            'PATCH /helpers/api/avatars/u1', 'PATCH /helpers/api/avatars/u1', 'POST /helpers/api/password-resets',
        ])->and($log[1]['body']['data']['attributes'])->toBe(['active' => false])
            ->and(formError(fn () => $forms->manage('delete', ['uuid' => 'u1'])))->toBe('w4os.error_invalid_request');
    });

    test('the status of an avatar gives the entries of its menu', function () {
        expect(Forms::status(['status' => 'pending', 'active' => false]))->toBe('pending')
            ->and(Forms::status(['active' => true]))->toBe('active')
            ->and(Forms::status(['active' => false]))->toBe('disabled')
            ->and(Forms::ACTIONS[Forms::status(['active' => true])])->toBe(['disable', 'reset'])
            ->and(Forms::ACTIONS['pending'])->not->toContain('reset')
            ->and(Forms::actions(['uuid' => 'u1', 'active' => true], 'u1'))->toBe(['reset']);
    });

    test('a manager never disables their own avatar', function () {
        $forms = forms([], $log);
        expect(formError(fn () => $forms->manage('disable', ['uuid' => 'u1', 'name' => 'Mona'], 'u1')))->toBe('w4os.error_invalid_request')
            ->and($log)->toBe([]);
    });
});
