<?php

use W4os\Grav\Site;
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
        $avatars = (new Contract(OpenSim\Api\Contract::document()))->listOf('avatars');
        $row = ['uuid' => 'b1', 'status' => 'active', 'estateBanned' => false];

        expect(Site::readsAdmin(200, []))->toBeTrue()
            ->and(array_column(Rows::actions($avatars, $row, 'me', 200), 'name'))->toContain('ban')
            ->and(Site::readsAdmin(0, []))->toBeFalse()
            ->and(Rows::actions($avatars, $row, 'me', 0))->toBe([]);
    });
});

describe('a sign-in with the password', function () {
    test('of a banned, disabled or unconfirmed avatar stops there: no other account signs in with its name', function () {
        expect(Site::REFUSED)->toBe(['w4os.error_account_disabled', 'w4os.error_account_banned', 'w4os.error_email_unconfirmed']);
    });
});
