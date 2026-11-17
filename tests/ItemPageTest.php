<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Lists;
use W4os\Grav\OpenApi;
use W4os\Grav\Site;

/** The templates of the admin with the plugin's functions as plain stubs; the item of the page as `w4os_item()` gives it. */
function item_twig(array $page = []): Environment
{
    $api = new OpenApi(Contract::document());
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new TwigFunction('w4os_item', fn () => $page));
    $twig->addFunction(new TwigFunction('w4os_list_of', fn (string $type) => $api->listOf($type)));
    $twig->addFunction(new TwigFunction('w4os_list_actions', fn (array $list, array $item) => Lists::actions($list, $item)));
    $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
    $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti ti-$icon"));
    $twig->addFunction(new TwigFunction('w4os_route', fn (string $type) => "/$type"));
    $twig->addFunction(new TwigFunction('nonce_field', fn () => '<input type="hidden" name="nonce">'));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
    $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
    $twig->addFilter(new TwigFilter('w4os_date', fn ($value) => Site::date($value, 'fr')));

    return $twig;
}

describe('the page of an item in the admin', function () {
    $api = new OpenApi(Contract::document());
    $region = ['uuid' => 'r1', 'name' => 'Test East', 'mapImage' => 'm1', 'location' => '8015,8012', 'enabled' => true, 'online' => true, 'local' => true, 'hyperlink' => false];
    $page = ['type' => 'regions', 'list' => 'regions', 'form' => $api->itemOf('regions'), 'item' => $region, 'roles' => ['manager'], 'error' => null,
        'groups' => [['label' => 'details', 'fields' => [['name' => 'name', 'type' => 'text', 'label' => 'column_region'], ['name' => 'mapImage', 'type' => 'map', 'label' => 'column_map'], ['name' => 'location', 'type' => 'text', 'label' => 'profile_location'], ['name' => 'enabled', 'type' => 'boolean', 'label' => 'column_enabled']]]],
        'changeable' => true, 'edit' => false];

    test('has a header: back to its list, its picture and name, its state as badges in their tone, its actions, the dangerous ones last and marked', function () use ($page) {
        $html = item_twig($page)->render('w4os/item-header.html.twig', ['base_url_relative' => '']);

        expect($html)->toContain('class="page-header d-print-none w4os-item-header"', '<div class="page-pretitle"><a href="&#x2F;regions">', '[w4os.regions_title]',
            '<h2 class="page-title">Test East</h2>', 'src="&#x2F;pictures&#x2F;m1.jpg"', 'class="badge bg-green-lt"')
            ->and($html)->toContain('<span class="badge bg-green-lt"><i class="ti&#x20;ti-power me-1" aria-hidden="true"></i>[w4os.state_enabled]</span>')
            ->and($html)->toContain('name="action" value="disable"');
    });

    test('has the links its page gives, to the addresses of the item (its profile page)', function () use ($page) {
        $html = item_twig(['item' => $page['item'] + ['profilePage' => '/profile/Test.East']] + $page)->render('w4os/item-header.html.twig',
            ['base_url_relative' => '', 'page' => ['header' => ['w4os_item' => ['links' => [['key' => 'action_view', 'attribute' => 'profilePage', 'id' => 'w4os-view-profile'], ['key' => 'none', 'attribute' => 'missing']]]]]]);

        expect($html)->toContain('<a class="btn" id="w4os-view-profile" href="&#x2F;profile&#x2F;Test.East">[w4os.action_view]</a>')->not->toContain('[w4os.none]');
    });

    test('shows its views as tabs of a card, its details in a grid, without its name, picture and state (its header has them)', function () use ($page) {
        $html = item_twig($page)->render('w4os/item.html.twig', []);

        expect($html)->toContain('<ul class="nav nav-tabs card-header-tabs">', 'class="nav-link active" href="&#x3F;id&#x3D;r1" aria-current="page">[w4os.tab_overview]', 'href="&#x3F;id&#x3D;r1&amp;edit&#x3D;1">[w4os.action_edit]',
            '<div class="datagrid">', '<div class="datagrid-title">[w4os.profile_location]</div>', '8015,8012')
            ->and($html)->not->toContain('[w4os.column_region]', '[w4os.column_map]', '[w4os.column_enabled]');
    });

    test('has no tab to change it when the visitor may not', function () use ($page) {
        expect(item_twig(['changeable' => false] + $page)->render('w4os/item.html.twig', []))->not->toContain('nav-tabs', 'edit=1');
    });

    test('says so when there is no such item, under the title of its page', function () use ($page) {
        $twig = item_twig(['item' => null] + $page);

        expect($twig->render('w4os/item.html.twig', []))->toContain('class="alert alert-danger"', '[w4os.error_not_found]')
            ->and($twig->render('w4os/item-header.html.twig', ['page' => ['title' => 'Region']]))->toContain('<h2 class="page-title">Region</h2>');
    });

    test('takes the styles of the admin, not the site\'s', function () {
        expect(Site::siteStyles('w4os-admin', 'item'))->toBeFalse()
            ->and(Site::siteStyles('w4os-admin', 'manage'))->toBeFalse()
            ->and(Site::siteStyles('w4os-admin', 'dashboard'))->toBeFalse()
            ->and(Site::siteStyles('default', null))->toBeTrue();
    });
});
