<?php

use W4OS\Grav\Site;

describe('the admins of the grid', function () {
    test('manage the site too: Grav\'s admin and its API (admin2), as its super user, from the level of the admin pages', function () {
        $admin = Site::gravAdmin(['site' => ['login' => true]], true);

        expect($admin)->toBe(['site' => ['login' => true], 'admin' => ['login' => true, 'super' => true], 'api' => ['login' => true, 'super' => true]])
            // The rights of an account that had them, taken back once its level drops below: the site alone
            ->and(Site::gravAdmin($admin, false))->toBe(['site' => ['login' => true]])
            ->and(Site::gravAdmin(['site' => ['login' => true], 'api' => ['key' => true]], false))->toBe(['site' => ['login' => true], 'api' => ['key' => true]]);
    });

    test('are given them at each sign-in, as their level is then', function () {
        $php = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');

        expect($php)->toContain('$manage = self::readsAdmin($granted[\'level\'], $this->config());', '$user->set(\'access\', self::gravAdmin($access, $manage));');
    });

    test('find Grav\'s admin from the admin of the plugin: its pages, at the route of the admin the site has', function () {
        expect(Site::gravAdminRoute(['admin2' => ['enabled' => true, 'route' => '/manage']]))->toBe('/manage')
            ->and(Site::gravAdminRoute(['admin2' => ['enabled' => true]]))->toBe('/admin')
            ->and(Site::gravAdminRoute(['admin' => ['enabled' => true, 'route' => '/admin']]))->toBe('/admin')
            ->and(Site::gravAdminRoute(['admin2' => ['enabled' => false], 'admin' => ['enabled' => false]]))->toBeNull()
            ->and(Site::gravAdminRoute([]))->toBeNull()
            // An entry of the menu of the plugin's admin, last, for whom has the rights only
            ->and((string) file_get_contents(dirname(__DIR__).'/classes/Site.php'))->toContain("\$user->authorize('api.super') || \$user->authorize('admin.super')", "'label' => \$this->translate('w4os.admin_grav_pages'), 'url' => rtrim(\$this->grav['uri']->rootUrl(false), '/').\$route.'/pages'");
    });
});
