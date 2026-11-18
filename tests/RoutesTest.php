<?php

use W4os\Grav\Site;

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
