<?php

use W4OS\Grav\Site;

describe('a teleport link of the site', function () {
    test('asks the engine the links of its destination for a browser and for the viewer', function () {
        $place = ['tpUri' => 'speculoos.world:8002/Grand Place/128/128/21', 'browser' => [['format' => 8, 'link' => 'hop://speculoos.world:8002/Grand%20Place/128/128/21', 'label' => 'link_menu_hop']], 'viewer' => [['format' => 32, 'link' => 'secondlife:///app/teleport/speculoos.world:8002:Grand%20Place/128/128/21', 'label' => 'link_menu_apptp']]];
        $engine = fakeEngine([[200, ['data' => ['type' => 'place', 'id' => $place['tpUri'], 'attributes' => $place]]]], $log);

        expect($engine->place('speculoos.world:8002:Grand Place/128/128/21'))->toMatchArray($place)
            ->and(urldecode((string) $log[0]['url']))->toContain('/place?destination=speculoos.world:8002:Grand Place/128/128/21');
    });

    test('shows the name of its place: its region, a grid alone its address', function () {
        expect(Site::placeName('speculoos.world:8002/Grand Place/128/128/21'))->toBe('Grand Place')
            ->and(Site::placeName('hop://speculoos.world:8002/Grand%20Place/128/128/21'))->toBe('Grand Place')
            ->and(Site::placeName('speculoos.world:8002:Grand Place'))->toBe('Grand Place')
            ->and(Site::placeName('demo.w4os.org:3405'))->toBe('demo.w4os.org:3405');
    });

    test('is a shortcode and a Twig function of the site, its style and script from the views on every page of the site', function () {
        $php = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect(array_column(Site::SHORTCODES, 'code'))->toContain('[tp-link]')
            ->and($php)->toContain("\$handlers->add('tp-link'", "new \\Twig\\TwigFunction('w4os_tp_link'", "TeleportLink::ASSETS['js'] as \$file", "addJs('plugin://w4os/vendor/w4os/w4os-views/assets/'.\$file", "addCss('plugin://w4os/vendor/w4os/w4os-views/assets/'.\$file")
            // Its style and script the views' own (one source for every bridge), none left in the plugin
            ->and(dirname(__DIR__).'/vendor/w4os/w4os-views/assets/'.\W4OS\Views\TeleportLink::ASSETS['js'][0])->toBeFile()
            ->and(dirname(__DIR__).'/vendor/w4os/w4os-views/assets/'.\W4OS\Views\TeleportLink::ASSETS['css'][0])->toBeFile()
            ->and(dirname(__DIR__).'/js/tp-link.js')->not->toBeFile()
            ->and((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->not->toContain('.w4os-tp')
            // The list with its detail of a search (ListDetailView), its style and script the views' own too
            ->and($php)->toContain("ListDetailView::ASSETS['js'] as \$file", "ListDetailView::ASSETS['css'] as \$file")
            ->and(dirname(__DIR__).'/vendor/w4os/w4os-views/assets/'.\W4OS\Views\ListDetailView::ASSETS['js'][0])->toBeFile()
            ->and(dirname(__DIR__).'/vendor/w4os/w4os-views/assets/'.\W4OS\Views\ListDetailView::ASSETS['css'][0])->toBeFile()
            ->and(Site::viewerPattern())->toContain('Firestorm')->not->toStartWith('/');
    });
});
