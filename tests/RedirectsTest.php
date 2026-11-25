<?php

use W4OS\Grav\Site;

/** The pairs the engine keeps of a grid's previous addresses (GET /grids/{grid}/addresses: previous), as dev.w4os.org's demo grid has them, plus some on the site's host. */
function previous_pairs(): array
{
    return [
        ['service' => 'about', 'old' => 'https://w4os.org/about/', 'new' => 'https://dev.w4os.org/about', 'date' => null, 'source' => 'import'],
        ['service' => 'register', 'old' => 'https://w4os.org/wp-login.php?action=register', 'new' => 'https://dev.w4os.org/register', 'date' => null, 'source' => 'import'],
        ['service' => 'message', 'old' => 'https://demo.w4os.org/helpers/offline.php', 'new' => 'https://dev.w4os.org/helpers/offline.php', 'date' => null, 'source' => 'import'],
        ['service' => 'web_profile_url', 'old' => 'https://w4os.org/profile/?name=[AGENT_NAME]&session_id=[SESSION_ID]', 'new' => 'https://dev.w4os.org/profile/[AGENT_NAME]?session=[SESSION_ID]', 'date' => null, 'source' => 'import'],
        ['service' => 'login', 'old' => 'http://demo.w4os.org:3405', 'new' => 'http://dev-demo.w4os.org:3405', 'date' => '2026-11-21T22:00:00Z', 'source' => 'change'],
        ['service' => 'help', 'old' => 'https://w4os.org/help', 'new' => 'https://elsewhere.example.org/help', 'date' => null, 'source' => 'import'],
    ];
}

/** The page a Grav redirect gives a request, as Pages::dispatch() applies them (first match), its code after it. */
function redirected(array $redirects, string $request): ?string
{
    foreach ($redirects as $pattern => $replace) {
        $found = preg_replace('#^'.str_replace('/', '\/', ltrim((string) $pattern, '^')).'#', (string) $replace, $request);
        if ($found !== $request) {
            return $found;
        }
    }

    return null;
}

describe('the previous addresses of the grid', function () {
    test('on the site\'s own host become Grav redirects (301) to the new ones, the others are left to the web server', function () {
        $redirects = Site::redirectsFrom(previous_pairs(), 'w4os.org', []);

        expect(redirected($redirects, '/about/'))->toBe('https://dev.w4os.org/about[301]')
            ->and(redirected($redirects, '/about'))->toBe('https://dev.w4os.org/about[301]')
            ->and(redirected($redirects, '/wp-login.php?action=register'))->toBe('https://dev.w4os.org/register[301]')
            // A profile: its placeholders carried to the new address
            ->and(redirected($redirects, '/profile/?name=Way.Forest&session_id=abc-123'))->toBe('https://dev.w4os.org/profile/Way.Forest?session=abc-123[301]')
            ->and(redirected($redirects, '/help'))->toBe('https://elsewhere.example.org/help[301]')
            // Exactly the old address: not a longer one, not another query
            ->and(redirected($redirects, '/about/us'))->toBeNull()
            ->and(redirected($redirects, '/wp-login.php?action=lostpassword'))->toBeNull()
            // demo.w4os.org (another host, with or without a port): none
            ->and(array_filter(array_keys($redirects), fn ($key) => str_contains($key, 'offline') || str_contains($key, '3405')))->toBe([]);
    });

    test('point to a path when the new address is on the site\'s host, and leave out an address that did not change', function () {
        $redirects = Site::redirectsFrom([
            ['service' => 'search', 'old' => 'https://dev.w4os.org/search/', 'new' => 'https://dev.w4os.org/fr/recherche?x=1', 'date' => null, 'source' => 'change'],
            ['service' => 'welcome', 'old' => 'https://dev.w4os.org/splash', 'new' => 'https://dev.w4os.org/splash', 'date' => null, 'source' => 'change'],
        ], 'DEV.w4os.org', []);

        expect(redirected($redirects, '/search/'))->toBe('/fr/recherche?x=1[301]')
            ->and($redirects)->toHaveCount(1);
    });

    test('come after the site\'s own list, which wins on the same address; none, the site\'s list as it is', function () {
        $manual = ['(?i)/about/?$' => '/features[301]', '/old-news' => '/news'];
        $redirects = Site::redirectsFrom(previous_pairs(), 'w4os.org', $manual);

        expect(array_slice($redirects, 0, 2, true))->toBe($manual)
            ->and(redirected($redirects, '/about/'))->toBe('/features[301]')
            ->and(redirected($redirects, '/wp-login.php?action=register'))->toBe('https://dev.w4os.org/register[301]')
            ->and(Site::redirectsFrom([], 'w4os.org', $manual))->toBe($manual)
            ->and(Site::redirectsFrom([['old' => 'not an address'], 'nothing'], 'w4os.org', $manual))->toBe($manual);
    });

    test('are added to Grav\'s redirects before it looks for the page, from the engine, kept a while', function () {
        $php = (string) file_get_contents(dirname(__DIR__).'/w4os.php');
        $site = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');

        expect($php)->toContain("Site::redirectsFrom(\$this->site()->previousAddresses(), (string) \$this->grav['uri']->host(), (array) \$config->get('site.redirects', []))")
            ->and($site)->toContain("\$this->cached('grid addresses'", '->addresses()', "\$this->gridAddresses()['previous']");
    });
});
