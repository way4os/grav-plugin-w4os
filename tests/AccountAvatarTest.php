<?php

use W4OS\Grav\Site;

describe('the picture of an account of the site', function () {
    test('is the picture of its avatar, a file of the accounts named by its picture: a new picture, a new file', function () {
        expect(Site::avatarFile('way-forest', 'cb2052ae-d161-43e9-b11b-c834217823cd'))->toBe('user/accounts/avatars/way-forest-cb2052ae.jpg')
            ->and(Site::avatarFile('way-forest', '11111111-d161-43e9-b11b-c834217823cd'))->not->toBe(Site::avatarFile('way-forest', 'cb2052ae-d161-43e9-b11b-c834217823cd'));
    });

    test('is Grav\'s avatar field of the account, the one its admins read (admin2), as a file field keeps a file', function () {
        expect(Site::avatarField('user/accounts/avatars/way-forest-cb2052ae.jpg', 2048))->toBe(['user/accounts/avatars/way-forest-cb2052ae.jpg' => [
            'name' => 'way-forest-cb2052ae.jpg', 'type' => 'image/jpeg', 'size' => 2048, 'path' => 'user/accounts/avatars/way-forest-cb2052ae.jpg',
        ]]);
    });

    test('is given at each sign-in, as the avatar is then: its picture, or none (its initials)', function () {
        $php = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');

        expect($php)->toContain("\$this->accountPicture(\$user, (string) (\$avatar['slug'] ?? \$avatar['uuid']), \$avatar['profileImage'] ?? null);");
    });
});
