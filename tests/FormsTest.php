<?php

use W4OS\Grav\FormError;
use W4OS\Grav\Forms;

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

    test('asks for a registration that confirms its email, nothing made until then', function () use ($fields) {
        $result = forms([[202, registrationDocument(['emailConfirmation' => 'sent'])]], $log)->register($fields);

        expect($result['confirm'])->toBeTrue()
            ->and($result['avatar']['name'])->toBe('Ada Love')
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

    test('gives what the engine says it did (its notice), none when it says nothing', function () use ($fields) {
        $notice = ['code' => 'registered-confirm', 'params' => ['email' => 'ada@example.org'], 'detail' => 'A link is on its way.'];

        expect(forms([[202, registrationDocument(['emailConfirmation' => 'sent', 'notice' => $notice])]])->register($fields)['notice'])->toBe($notice)
            ->and(forms([[202, registrationDocument(['emailConfirmation' => 'sent'])]])->register($fields)['notice'])->toBeNull();
    });

    test('on a test site, mails nothing and gives the link', function () use ($fields) {
        $answer = registrationDocument(['emailConfirmation' => 'given', 'confirmation' => ['token' => 't', 'link' => 'https://site.example.org/confirm?token=t']]);
        $result = forms([[202, $answer]], $log, ['send_mail' => false])->register($fields);

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
            ->and($refused(403, 'account-banned'))->toBe('w4os.error_account_banned')
            ->and($refused(403, 'sign-in-refused'))->toBe('w4os.error_sign_in_refused')
            ->and($refused(503, null))->toBe('w4os.error_channel_unavailable');
    });

    test('the forgotten password mails a link to the reset page, and says what the engine says of it', function () {
        $notice = ['code' => 'mail-sent-if-known', 'params' => [], 'detail' => 'If an avatar has this name or email, a link is on its way.'];
        $said = forms([[202, ['meta' => ['message' => $notice['detail'], 'notice' => $notice]]]], $log)->forgot(['login' => 'ada@example.org']);
        $older = forms([[202, ['meta' => ['message' => 'sent']]]])->forgot(['login' => 'ada@example.org']);

        expect($log[0]['body']['data']['attributes'])->toBe(['email' => 'ada@example.org', 'link' => 'https://site.example.org/reset?token={token}'])
            ->and($said)->toBe($notice)
            ->and($older)->toBeNull();
    });

    test('a new confirmation link is mailed, and says what the engine says of it; on a test site, the link', function () {
        $notice = ['code' => 'mail-sent', 'params' => [], 'detail' => 'The mail is sent.'];

        expect(forms([[202, ['meta' => ['message' => $notice['detail'], 'notice' => $notice]]]])->resendConfirmation('Ada Love'))->toBe(['link' => null, 'notice' => $notice])
            ->and(forms([[200, ['data' => [['attributes' => ['link' => 'https://site.example.org/confirm?token=t']]]]]], config: ['send_mail' => false])->resendConfirmation('Ada Love'))
            ->toBe(['link' => 'https://site.example.org/confirm?token=t', 'notice' => null]);
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

    test('a registration whose name was taken before its confirmation is told so', function () {
        expect(formError(fn () => forms([[400, problem(400, 'taken meanwhile', 'name-taken')]])->confirm('t')))->toBe('w4os.error_name_taken');
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

    test('sends the check boxes as lists of names when they changed, none to clear them', function () use ($current) {
        $current += ['wantsList' => ['meet', 'build'], 'skillsList' => ['scripting']];
        $forms = forms([[200, avatarDocument()]], $log);
        $forms->profile($current, ['email' => 'ada@example.org', 'wantsList' => ['build', 'meet'], 'skillsList' => []]);
        $forms->profile($current, ['email' => 'ada@example.org', 'wantsList' => ['wantslist-build' => 'build', 'wantslist-meet' => 'meet'], 'skillsList' => ['scripting']]);

        expect($log)->toHaveCount(1)
            ->and($log[0]['body']['data']['attributes'])->toBe(['skillsList' => []]);
    });

    test('sends "show in search" when it changed', function () use ($current) {
        $forms = forms([[200, avatarDocument()]], $log);
        $forms->profile($current + ['published' => false], ['email' => 'ada@example.org', 'published' => true]);
        $forms->profile($current + ['published' => true], ['email' => 'ada@example.org', 'published' => true]);

        expect($log)->toHaveCount(1)->and($log[0]['body']['data']['attributes'])->toBe(['published' => true]);
    });

    test('keeps the languages as the viewer does, whatever the separators typed', function () use ($current) {
        forms([[200, avatarDocument()]], $log)->profile($current, ['email' => 'ada@example.org', 'languages' => 'Français,English ; Deutsch,']);

        expect($log[0]['body']['data']['attributes'])->toBe(['languages' => 'Français, English, Deutsch']);
    });

    test('asks nothing when nothing changed', function () use ($current) {
        forms([], $log)->profile($current, ['email' => 'ada@example.org', 'about' => 'Hi']);

        expect($log)->toBe([]);
    });

    test('a request that mails a token gets the page that takes it, and mails nothing on a test site', function () {
        expect(forms([])->tokenAttributes('reset'))->toBe(['link' => 'https://site.example.org/reset?token={token}'])
            ->and(forms([], config: ['send_mail' => false])->tokenAttributes('reset'))->toBe(['link' => 'https://site.example.org/reset?token={token}', 'send' => false]);
    });

    test('a manager changes the profile, the email at once (cleared too), and the role only when another one is picked (the engine knows the roles)', function () {
        $edited = ['uuid' => 'u1', 'name' => 'Ada Love', 'email' => 'ada@example.org', 'about' => '', 'role' => 'resident', 'level' => 5];
        forms([[200, avatarDocument()]], $log)->edit($edited, ['role' => 'resident', 'email' => 'new@example.org', 'about' => 'Hi']);
        forms([[200, avatarDocument()]], $log2)->edit($edited, ['role' => 'liaison', 'email' => 'ada@example.org']);
        $forms3 = forms([[422, problem(422, 'unknown role', 'role-unknown')]], $log3);
        $emperor = formError(fn () => $forms3->edit($edited, ['role' => 'emperor', 'email' => 'ada@example.org']));

        $model = ['uuid' => 'm1', 'name' => 'Default Ruth', 'email' => '', 'role' => 'resident'];
        forms([[200, avatarDocument()]], $log6)->edit($model, ['role' => 'resident', 'email' => '', 'about' => 'A model']);
        forms([[200, avatarDocument()]], $log4)->edit($edited, ['role' => 'resident', 'email' => 'ada@example.org', 'password' => 'secret12', 'password_confirm' => 'secret12']);
        forms([[200, avatarDocument()]], $log7)->edit($edited, ['role' => 'resident', 'email' => '']);
        $forms5 = forms([], $log5);

        expect($log[0]['body']['data']['attributes'])->toBe(['about' => 'Hi', 'email' => 'new@example.org'])
            ->and($log4[0]['body']['data']['attributes'])->toBe(['password' => 'secret12'])
            ->and($log6[0]['body']['data']['attributes'])->toBe(['about' => 'A model'])
            ->and($log7[0]['body']['data']['attributes'])->toBe(['email' => ''])
            ->and(formError(fn () => $forms5->edit($edited, ['email' => 'ada@example.org', 'password' => 'a12345', 'password_confirm' => 'b12345'])))->toBe('w4os.error_password_match')
            ->and($log2[0]['body']['data']['attributes'])->toBe(['role' => 'liaison'])
            ->and($log3[0]['body']['data']['attributes'])->toBe(['role' => 'emperor'])
            ->and($emperor)->toBe('w4os.error_role_unknown');
    });
});
