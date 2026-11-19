<?php

use W4os\Grav\Site;

describe('the menu of the site', function () {
    $routes = ['search' => '/search', 'profile' => '/profile', 'account' => '/account', 'register' => '/register', 'login' => '/login', 'dashboard' => '/w4os', 'settings' => '/w4os/settings'];
    $admin = [['type' => 'settings', 'route' => '/w4os/settings', 'label' => 'w4os.admin_site_settings']];
    $entry = fn (array $entries, string $type) => array_column($entries, null, 'type')[$type] ?? null;

    test('is one menu of the pages: the search, my avatar to the profile, signing in and creating an avatar under it for a visitor', function () use ($routes, $entry) {
        $menu = Site::menuEntries($routes, false, []);
        $children = $entry($menu, 'profile')['children'];

        expect(array_column($menu, 'type'))->toBe(['search', 'profile'])
            ->and($entry($menu, 'profile'))->toMatchArray(['type' => 'profile', 'route' => '/profile', 'label' => 'w4os.menu_account'])
            ->and(array_column($children, 'type'))->toBe(['login', 'register'])
            ->and(array_column($children, 'route'))->toBe(['/login', '/register'])
            ->and(array_column($children, 'label'))->toBe(['w4os.login_title', 'w4os.register_title'])
        // A site without the registration: the sign-in alone
            ->and(array_column($entry(Site::menuEntries(array_diff_key($routes, ['register' => 1]), false, []), 'profile')['children'], 'type'))->toBe(['login']);
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
            ->and($entry($menu, 'admin'))->toMatchArray(['route' => '/w4os', 'label' => 'w4os.menu_admin', 'children' => $admin]);
    });

    test('titles each entry by its label, for the themes that list the pages by their title (Apple Notes); the admin by its dashboard', function () use ($routes, $admin, $entry) {
        $menu = Site::menuEntries($routes, true, $admin);

        expect(Site::menuTitleKey($entry($menu, 'profile')))->toBe('w4os.menu_account')
            ->and(Site::menuTitleKey($entry($menu, 'search')))->toBe('w4os.search')
            ->and(Site::menuTitleKey($entry($menu, 'admin')))->toBe('w4os.dashboard_title')
            ->and(Site::menuTitleKey($entry($menu, 'profile')['children'][0]))->toBe('w4os.edit_profile')
            // A section of the admin is titled as its page, whatever its entry in the menu says (w4os-wp: Flux, Flux Posts)
            ->and(Site::menuTitleKey(['type' => 'flux', 'label' => 'w4os.admin_flux_menu']))->toBe('w4os.admin_flux_title')
            ->and(Site::menuTitleKey(['type' => 'settings_economy', 'label' => 'w4os.settings_menu_economy']))->toBe('w4os.settings_economy');
    });
});
