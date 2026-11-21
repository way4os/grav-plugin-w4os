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

    test('is a shortcode and a Twig function of the site, its script on every page of the site, the links of the other client hidden', function () {
        $php = (string) file_get_contents(dirname(__DIR__).'/w4os.php');
        $css = (string) file_get_contents(dirname(__DIR__).'/css/w4os.css');
        $js = (string) file_get_contents(dirname(__DIR__).'/js/tp-link.js');

        expect(array_column(Site::SHORTCODES, 'code'))->toContain('[tp-link]')
            ->and($php)->toContain("\$handlers->add('tp-link'", "new \\Twig\\TwigFunction('w4os_tp_link'", "addJs('plugin://w4os/js/tp-link.js'")
            ->and($css)->toContain('.w4os-tp[data-client="browser"] [data-for="viewer"], .w4os-tp[data-client="viewer"] [data-for="browser"] { display: none; }', '.w4os-tp-menu[hidden] { display: none; }')
            // The client told by the page's own user agent, Escape and a click outside close it, the address selected without a clipboard
            ->and($js)->toContain("new RegExp(tp.dataset.viewerPattern, 'i').test(navigator.userAgent)", "event.key === 'Escape'", 'tp.contains(event.target) || close(tp)', 'navigator.clipboard ?', 'input.select()', "main.removeAttribute('href')")
            ->and(Site::viewerPattern())->toContain('Firestorm')->not->toStartWith('/');
    });
});
