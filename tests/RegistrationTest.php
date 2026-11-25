<?php

use W4OS\Grav\Site;

describe('registration', function () {
    test('closed, has no page nor link, its address says it is closed; the managers still create avatars', function () {
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($site)->toContain("isset(\$routes['register']) && \$routes['register_closed'] = \$routes['register'];\n            unset(\$routes['register'], \$routes['confirm']);")
            ->and(Site::titleKey('register_closed'))->toBe('w4os.site_register_closed_title')
            ->and((string) file_get_contents(dirname(__DIR__).'/pages/register_closed.md'))->toContain('w4os: register_closed', 'visible: false')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/register_closed.html.twig'))->toContain("'w4os.site_register_closed_text'|t")->not->toContain('<form', '<a ')
            // Its links follow the route (the menu, the login form, the [w4os-register] texts of the pages)
            ->and($plugin)->toContain("\$handlers->add('w4os-register', fn (\$shortcode) => isset(\$this->site()->routes()['register'])")
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/user-menu.html.twig'))->toContain("{% if w4os_route('register') %}")
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/login-links.html.twig'))->toContain("{% if w4os_route('register') %}")
            // The admin's own creation of avatars has a route of its own
            ->and(Site::ADMIN_ROUTES)->toHaveKey('new');
    });

    test('is switched where one looks for it, with the avatars\' settings, the site\'s own setting (no copy of it)', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($plugin)->toContain("if (\$service['group'] === 'avatars') {", "'register_open' => ['type' => 'checkbox', 'label' => 'w4os.admin_register_open'", "(bool) \$this->config->get('plugins.w4os.register.enabled', true)",
            "\$service['group'] === 'avatars' && \$site->saveSetting('plugins/w4os', 'register.enabled', (bool) (\$data['register_open'] ?? false));");
    });
});

describe('the map\'s page of the site', function () {
    test('is out of the menu and of its address while the grid has no map', function () {
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');

        expect($site)->toContain("if (\$route === null || \$this->hasMap()) {", "\$page->visible(false);\n            \$page->routable(false);")
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("\$this->site()->hideMapPage();");
    });
});
