<?php

use W4OS\Grav\Site;

/** A file of Grav's file fields (the settings of the plugin, of a theme), by its path. */
function brand_file(string $path): array
{
    return [$path => ['name' => basename($path), 'type' => 'image/png', 'size' => 100, 'path' => $path]];
}

describe('the brand of the site in Grav\'s admins', function () {
    test('is the logo and the icon of the site: its own settings, else the ones of its theme', function () {
        $brand = ['logo' => brand_file('user/data/theme/images/logo/ours.png')];
        $theme = ['custom_logo' => brand_file('user/themes/w4os-org/images/logo/w4os.png'), 'custom_favicon' => brand_file('user/themes/w4os-org/images/favicon/w4os.png')];

        expect(Site::effectiveBrand($brand, $theme))->toBe(['logo' => 'user/data/theme/images/logo/ours.png', 'favicon' => 'user/themes/w4os-org/images/favicon/w4os.png'])
            ->and(Site::effectiveBrand([], $theme))->toBe(['logo' => 'user/themes/w4os-org/images/logo/w4os.png', 'favicon' => 'user/themes/w4os-org/images/favicon/w4os.png'])
            // A theme's favicon field of another name (Hypertext's favicon)
            ->and(Site::effectiveBrand([], ['favicon' => brand_file('user/themes/x/fav.ico')])['favicon'])->toBe('user/themes/x/fav.ico')
            ->and(Site::effectiveBrand([], []))->toBe(['logo' => null, 'favicon' => null])
            // Never a path out of the site
            ->and(Site::effectiveBrand(['logo' => brand_file('../etc/passwd')], [])['logo'])->toBeNull();
    });

    test('is admin2\'s logo, light and dark, and its icon, where it reads them (admin-next.ui.branding)', function () {
        $branding = Site::adminNextBranding(['mode' => 'default', 'title' => 'My grid'], 'w4os-logo-1a2b3c4d.png', 'w4os-favicon-5e6f7a8b.png');

        expect($branding)->toBe(['mode' => 'custom', 'title' => 'My grid', 'logoLight' => 'w4os-logo-1a2b3c4d.png', 'logoDark' => 'w4os-logo-1a2b3c4d.png', 'favicon' => 'w4os-favicon-5e6f7a8b.png'])
            // Its own again, as the site's changed
            ->and(Site::adminNextBranding($branding, 'w4os-logo-99999999.png', null))->toMatchArray(['mode' => 'custom', 'logoLight' => 'w4os-logo-99999999.png', 'favicon' => ''])
            // The site without a logo: admin2's own again
            ->and(Site::adminNextBranding($branding, null, null))->toMatchArray(['mode' => 'default', 'logoLight' => '', 'logoDark' => '', 'favicon' => '']);
    });

    test('leaves a choice made in admin2 itself as it is: its own logo or its text, its own icon', function () {
        $own = ['mode' => 'custom', 'logoLight' => 'logo-light-0123456789.png', 'logoDark' => '', 'favicon' => 'favicon-favicon-0123456789.png'];

        expect(Site::adminNextBranding($own, 'w4os-logo-1a2b3c4d.png', 'w4os-favicon-5e6f7a8b.png'))->toBe($own)
            ->and(Site::adminNextBranding(['mode' => 'text', 'text' => 'My grid'], 'w4os-logo-1a2b3c4d.png', null))->toBe(['mode' => 'text', 'text' => 'My grid', 'favicon' => ''])
            ->and(Site::adminNextBranding(['favicon' => 'favicon-favicon-0123456789.png'], 'w4os-logo-1a2b3c4d.png', 'w4os-favicon-5e6f7a8b.png')['favicon'])->toBe('favicon-favicon-0123456789.png');
    });

    test('is the logo of the classic admin, on its sign-in screen and in its menu (no setting of its icon), unless it has its own', function () {
        $logo = 'user/media/admin-next/w4os-logo-1a2b3c4d.png';
        $whitelabel = Site::adminWhitelabel([], $logo);

        expect(array_keys($whitelabel))->toBe(['logo_login', 'logo_custom'])
            ->and($whitelabel['logo_login'])->toBe([$logo => ['name' => 'w4os-logo-1a2b3c4d.png', 'type' => 'image/png', 'size' => 0, 'path' => $logo]])
            ->and(Site::adminWhitelabel($whitelabel, null))->toBe(['logo_login' => [], 'logo_custom' => []])
            ->and(Site::adminWhitelabel(['logo_login' => brand_file('user/assets/theirs.png')], $logo)['logo_login'])->toBe(brand_file('user/assets/theirs.png'));
    });

    test('names its copies by the file they come from: a new name, a new copy; never a write when nothing changed', function () {
        expect(Site::brandCopyName('logo', 'user/themes/w4os-org/images/logo/w4os.png', 1234, 1700000000))->toMatch('/^w4os-logo-[0-9a-f]{8}\.png$/')
            ->and(Site::brandCopyName('logo', 'user/x.png', 1234, 1700000000))->toBe(Site::brandCopyName('logo', 'user/x.png', 1234, 1700000000))
            ->and(Site::brandCopyName('logo', 'user/x.png', 1234, 1700000001))->not->toBe(Site::brandCopyName('logo', 'user/x.png', 1234, 1700000000))
            ->and(Site::brandCopyName('favicon', 'user/x.ICO', 1, 1))->toEndWith('.ico');
    });

    test('is given them on each request of the site, once the theme is there, a failure never stopping the page', function () {
        expect((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("\$this->site()->applyBrand();\n        try {\n            \$this->site()->syncAdminBrand();")
            ->and((string) file_get_contents(dirname(__DIR__).'/classes/Site.php'))->toContain("if (\$newBranding === \$branding && \$newWhitelabel === \$whitelabel && \$missing === []) {\n            return;");
    });
});
