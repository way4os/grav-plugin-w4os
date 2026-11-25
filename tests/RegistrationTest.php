<?php

use W4OS\Grav\Site;

describe('registration', function () {
    test('closed, has no page nor link, its address says it is closed; the managers still create avatars', function () {
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($site)->toContain("if (! \$this->registrationOpen()) {\n            isset(\$routes['register']) && \$routes['register_closed'] = \$routes['register'];\n            unset(\$routes['register'], \$routes['confirm']);")
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

    test('is the engine\'s setting of the grid (registration, with the avatars\' settings), read again once they are saved; none of the site\'s own', function () {
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($site)->toContain("\$kept = (string) (\$this->engine()->settings('avatars')['registration'] ?? 'open');", "return \$kept !== 'closed';")
            ->and($plugin)->toContain("\$service['group'] === 'avatars' && \$site->forgetRegistration();", "null, \$this->site()->registrationOpen());")
            ->and($plugin)->not->toContain('register.enabled', 'register_open')
            ->and((string) file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->not->toContain('register.enabled')
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.yaml'))->not->toMatch('/^register:\n(  #[^\n]*\n)*  enabled:/m');
    });
});

describe('the map\'s page of the site', function () {
    test('is out of the menu and of its address while the grid has no map', function () {
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');

        expect($site)->toContain("if (\$route === null || \$this->hasMap()) {", "\$page->visible(false);\n            \$page->routable(false);")
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("\$this->site()->hideMapPage();");
    });
});

describe('the about page a grid announces', function () {
    test('leads to the home, for good and in its language, where the site has no page of it', function () {
        expect(Site::ABOUT_ROUTE)->toBe('/about')
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("if (\$this->grav['uri']->path() === Site::ABOUT_ROUTE && \$this->grav['pages']->find(Site::ABOUT_ROUTE) === null) {\n            \$this->grav->redirectLangSafe('/', 301);");
    });
});
