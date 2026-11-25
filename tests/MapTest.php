<?php

use W4OS\Grav\Site;

describe('the map of the grid', function () {
    test('is a block of the helpers, its shortcode [grid-map], its page /map unless the site has one', function () {
        $config = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml');
        $page = Symfony\Component\Yaml\Yaml::parse(explode('---', (string) file_get_contents(dirname(__DIR__).'/pages/map.md'))[1]);

        expect(W4OS\Grav\Blocks::NAMES)->toContain('map')
            ->and(Site::BLOCK_SHORTCODES['grid-map'])->toBe('map')
            ->and(array_column(Site::SHORTCODES, 'block', 'code')['[grid-map]'])->toBe('map')
            ->and($config['routes'])->toMatchArray(['map' => '/map', 'map_tiles' => '/grid-map'])
            ->and($page)->toMatchArray(['template' => 'default', 'w4os' => 'map', 'w4os_block' => 'map'])
            ->and(Site::titleKey('map'))->toBe('w4os.map_title')
            ->and(Site::typeAt('/map', $config['routes']))->toBe(['map', '']);
    });

    test('has its tiles on the site, by their zoom (1 to 8) and the region of their corner, answered by the engine', function () {
        expect(Site::tileAsked('/grid-map', '/grid-map/1/1000/1000.jpg'))->toBe([1, 1000, 1000])
            ->and(Site::tileAsked('/grid-map/', '/grid-map/8/992/-8.jpg'))->toBe([8, 992, -8])
            ->and(Site::tileAsked('/grid-map', '/grid-map/9/1000/1000.jpg'))->toBeNull()
            ->and(Site::tileAsked('/grid-map', '/grid-map/1/1000/1000.png'))->toBeNull()
            ->and(Site::tileAsked('/grid-map', '/grid-assets/1/1000/1000.jpg'))->toBeNull()
            ->and(Site::tileAsked('', '/1/1000/1000.jpg'))->toBeNull();
        $php = (string) file_get_contents(dirname(__DIR__).'/classes/Site.php');
        // The bundled engine locked, as the pictures of the grid: its open operation only
        expect($php)->toContain('->publicRoute(\'GET\', \'/grids/\'.rawurlencode($bundled->nick())."/map/$zoom/$x/$y.jpg")', '$this->engine()->mapTile($zoom, $x, $y)')
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("'map_tiles' => \\W4OS\\Grav\\Blocks::name(\$name) === 'map' ? \$this->site()->path('map_tiles') : null,");
    });

    test('draws on Leaflet from the vendor of the plugin, pinned and pruned as Tabler, for a browser alone', function () {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);
        $package = array_values(array_filter(array_column($composer['repositories'], 'package'), fn ($p) => ($p['name'] ?? '') === 'npm-asset/leaflet'))[0] ?? [];

        expect($package['version'] ?? null)->toBe('1.9.4')
            ->and($package['dist']['shasum'] ?? null)->toBe('23fae724e282fa25745aff82ca4d394748db7d8d')
            ->and(W4OS\Grav\Assets::KEEP['npm-asset/leaflet'])->toBe(['LICENSE', 'dist/leaflet.js', 'dist/leaflet.css', 'dist/images/*.png'])
            ->and(Site::helpersAsset('lib/leaflet/leaflet.js'))->toBe('plugin://w4os/vendor/npm-asset/leaflet/dist/leaflet.js')
            ->and(Site::helpersAsset('css/map.css'))->toBe('plugin://w4os/vendor/w4os/w4os-helpers/assets/css/map.css')
            ->and(is_file(dirname(__DIR__).'/vendor/npm-asset/leaflet/dist/leaflet.js'))->toBeTrue()
            ->and(W4OS\Helpers\View\View::blockFiles('map', 'viewer')['js'])->toBe([]);
    });
});
