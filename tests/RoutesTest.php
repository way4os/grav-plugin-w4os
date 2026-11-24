<?php

use W4OS\Grav\Site;

describe('the admin pages', function () {
    test('live under the admin route of the plugin (/w4os), each section in it, unless the site names one itself', function () {
        $routes = ['account' => '/account', 'admin' => '/w4os'];

        expect(Site::adminRoutes($routes, []))->toMatchArray([
            'account' => '/account', 'dashboard' => '/w4os', 'manage' => '/w4os/avatars', 'edit' => '/w4os/avatars/edit',
            'regions' => '/w4os/regions', 'region' => '/w4os/regions/region', 'simulators' => '/w4os/simulators', 'settings' => '/w4os/settings',
        ])
            ->and(Site::adminRoutes(['admin' => '/grid-admin', 'regions' => '/our-regions'], []))->toMatchArray(['dashboard' => '/grid-admin', 'regions' => '/our-regions', 'region' => '/grid-admin/regions/region'])
            // A site that kept the routes of before (under /account): the admin route
            ->and(Site::adminRoutes(['admin' => '/w4os', 'regions' => '/account/regions', 'dashboard' => '/account/dashboard'], []))->toMatchArray(['dashboard' => '/w4os', 'regions' => '/w4os/regions'])
            // The base no route of its own: the dashboard is the page there
            ->and(Site::adminRoutes($routes, []))->not->toHaveKey('admin');
    });

    test('never under the route of Grav\'s admin (admin, admin2): the default route then', function () {
        expect(Site::adminRoutes(['admin' => '/admin/w4os'], ['/admin'])['dashboard'])->toBe('/w4os')
            ->and(Site::adminRoutes(['admin' => '/admin'], ['/admin'])['settings'])->toBe('/w4os/settings')
            ->and(Site::adminRoutes(['admin' => '/administration'], ['/admin'])['dashboard'])->toBe('/administration');
    });

    test('moved from /account: the old addresses lead to the new ones', function () {
        $routes = Site::adminRoutes(['account' => '/account', 'admin' => '/w4os'], []);

        expect(Site::movedRoute('/account/dashboard', $routes))->toBe('/w4os')
            ->and(Site::movedRoute('/account/regions/region', $routes))->toBe('/w4os/regions/region')
            ->and(Site::movedRoute('/account/settings', $routes))->toBe('/w4os/settings')
            ->and(Site::movedRoute('/account/avatars/edit', $routes))->toBe('/w4os/avatars/edit')
            ->and(Site::movedRoute('/account', $routes))->toBeNull()
            ->and(Site::movedRoute('/account/logout', $routes))->toBeNull()
            ->and(Site::movedRoute('/w4os/regions', $routes))->toBeNull();
    });
});

describe('the pages a grid names in its grid info', function () {
    test('welcome, the page a viewer shows before the login, and password, the page of a lost password, have routes of their own', function () {
        $routes = ['welcome' => '/welcome', 'password' => '/password', 'forgot' => '/forgot_password'];

        expect(Site::typeAt('/welcome', $routes))->toBe(['welcome', ''])
            // The page of a lost password at both its addresses: the site's own, and the one grids name
            ->and(Site::typeAt('/password', $routes))->toBe(['forgot', ''])
            ->and(Site::typeAt('/forgot_password', $routes))->toBe(['forgot', ''])
            ->and(Site::typeAt('/nothing', $routes))->toBe([false, '']);
    });

    test('are routes of the plugin by default, in its settings', function () {
        $config = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml');

        expect($config['routes'])->toMatchArray(['welcome' => '/welcome', 'password' => '/password'])
            ->and(is_file(dirname(__DIR__).'/pages/welcome.md'))->toBeTrue()
            ->and(is_file(dirname(__DIR__).'/templates/w4os/welcome.html.twig'))->toBeTrue()
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os-welcome.html.twig'))->toContain('class="w4os-viewer viewer w4os-welcome"');
    });
});
