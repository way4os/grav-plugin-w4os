<?php

use W4OS\Grav\Site;
use W4OS\Views\Contract;
use W4OS\Views\Rows;

describe('a session that comes back by its remember-me token', function () {
    test('resumes an active avatar, as the engine has it now', function () {
        expect(Site::resumes(['uuid' => 'a1', 'status' => 'active', 'level' => 0]))->toBeTrue();
    });

    test('refuses a banned avatar (a hard ban)', function () {
        expect(Site::resumes(['uuid' => 'a1', 'status' => 'banned', 'level' => -1]))->toBeFalse();
    });

    test('refuses a disabled avatar, and one waiting for its email', function () {
        expect(Site::resumes(['uuid' => 'a1', 'status' => 'disabled', 'level' => 0]))->toBeFalse()
            ->and(Site::resumes(['uuid' => 'a1', 'status' => 'pending', 'level' => 0]))->toBeFalse();
    });

    test('refuses an avatar the engine no longer has (deleted)', function () {
        expect(Site::resumes(null))->toBeFalse();
    });

    test('takes the engine\'s level of now: a god demoted loses the admin pages and the actions of a god', function () {
        $avatars = (new Contract(W4OS\Engine\Api\Contract::document()))->listOf('avatars');
        $row = ['uuid' => 'b1', 'status' => 'active', 'estateBanned' => false];

        expect(Site::readsAdmin(200, []))->toBeTrue()
            ->and(array_column(Rows::actions($avatars, $row, 'me', 200), 'name'))->toContain('ban')
            ->and(Site::readsAdmin(0, []))->toBeFalse()
            ->and(Rows::actions($avatars, $row, 'me', 0))->toBe([]);
    });
});

describe('a password typed in a form of the site', function () {
    test('takes whatever OpenSim takes: no length nor pattern, required where it is asked for', function () {
        $fields = [];
        foreach (['register', 'account', 'edit', 'reset'] as $page) {
            $text = (string) file_get_contents(dirname(__DIR__)."/pages/$page.md");
            $header = Symfony\Component\Yaml\Yaml::parse(explode("---\n", $text)[1]);
            $find = function (array $node) use (&$find, &$fields, $page) {
                foreach ($node as $key => $value) {
                    if (is_array($value)) {
                        in_array($key, ['password', 'password_confirm'], true) && ($value['type'] ?? '') === 'password' && $fields["$page.$key"] = $value;
                        $find($value);
                    }
                }
            };
            $find($header);
        }

        expect(array_keys($fields))->toBe(['register.password', 'register.password_confirm', 'account.password', 'account.password_confirm', 'edit.password', 'edit.password_confirm', 'reset.password', 'reset.password_confirm']);
        foreach ($fields as $name => $field) {
            expect(array_intersect_key($field, ['minlength' => 1, 'maxlength' => 1, 'pattern' => 1]))->toBe([], $name)
                ->and(array_diff(array_keys((array) ($field['validate'] ?? [])), ['required']))->toBe([], $name);
        }
        expect($fields['register.password']['validate'])->toBe(['required' => true])
            ->and($fields['reset.password']['validate'])->toBe(['required' => true]);
    });
});

describe('a sign-in with the password', function () {
    test('of a banned, disabled or unconfirmed avatar stops there: no other account signs in with its name', function () {
        expect(Site::REFUSED)->toBe(['w4os.error_account_disabled', 'w4os.error_account_banned', 'w4os.error_email_unconfirmed']);
    });

    test('that succeeds clears the attempts the Login plugin counted under the name typed: five in a row never lock the avatar out', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');
        $reset = "\$this->grav['login']->resetLoginRateLimit(\$this->grav['login']->rateLimitName((string) (\$credentials['username'] ?? ''), \$event->getUser()));";

        // Under the name as typed, before the user becomes the avatar's account (its slug)
        expect($plugin)->toContain($reset."\n        \$event->setUser(\$this->site()->user(\$avatar));");
    });
});
