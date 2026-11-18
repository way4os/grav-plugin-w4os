<?php

use W4os\Grav\Site;

describe('the menu of the site', function () {
    $routes = ['search' => '/search', 'profile' => '/profile', 'account' => '/account', 'dashboard' => '/account/dashboard', 'settings' => '/account/settings'];
    $admin = [['type' => 'settings', 'route' => '/account/settings', 'label' => 'w4os.admin_site_settings']];
    $entry = fn (array $entries, string $type) => array_column($entries, null, 'type')[$type] ?? null;

    test('is one menu of the pages: the search, my avatar to the profile, alone for a visitor', function () use ($routes, $entry) {
        $menu = Site::menuEntries($routes, false, []);

        expect(array_column($menu, 'type'))->toBe(['search', 'profile'])
            ->and($entry($menu, 'profile'))->toBe(['type' => 'profile', 'route' => '/profile', 'label' => 'w4os.menu_account', 'children' => []]);
    });

    test('has under my avatar, once signed in, the edit of the profile and sign out', function () use ($routes, $entry) {
        $children = $entry(Site::menuEntries($routes, true, []), 'profile')['children'];

        expect(array_column($children, 'type'))->toBe(['account', 'logout'])
            ->and(array_column($children, 'label'))->toBe(['w4os.edit_profile', 'w4os.logout'])
            ->and($children[1]['route'])->toBe('/account/logout');
    });

    test('has the admin for a manager, its sections under it, the dashboard its own link', function () use ($routes, $admin, $entry) {
        $menu = Site::menuEntries($routes, true, $admin);

        expect(array_column($menu, 'type'))->toBe(['search', 'profile', 'admin'])
            ->and($entry($menu, 'admin'))->toMatchArray(['route' => '/account/dashboard', 'label' => 'w4os.menu_admin', 'children' => $admin]);
    });

    test('titles each entry by its label, for the themes that list the pages by their title (Apple Notes); the admin by its dashboard', function () use ($routes, $admin, $entry) {
        $menu = Site::menuEntries($routes, true, $admin);

        expect(Site::menuTitleKey($entry($menu, 'profile')))->toBe('w4os.menu_account')
            ->and(Site::menuTitleKey($entry($menu, 'search')))->toBe('w4os.search')
            ->and(Site::menuTitleKey($entry($menu, 'admin')))->toBe('w4os.dashboard_title')
            ->and(Site::menuTitleKey($entry($menu, 'profile')['children'][0]))->toBe('w4os.edit_profile');
    });
});
