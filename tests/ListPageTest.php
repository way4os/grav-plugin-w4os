<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Lists;
use W4os\Grav\OpenApi;

/** The list of a page of the admin, rendered by its template with the plugin's functions as plain stubs. */
function render_list(array $list, array $items, array $meta, array $links = [], array $query = []): string
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new TwigFunction('w4os_list_actions', fn (array $list, array $item) => Lists::actions($list, $item)));
    $twig->addFunction(new TwigFunction('w4os_count', fn (string $key, int $n) => "$n items"));
    $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
    $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti ti-$icon"));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
    $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
    $assets = new class {
        public function addCss(string $url): void {}
        public function addJs(string $url, array $options = []): void {}
    };

    return $twig->render('w4os/list.html.twig', ['list' => $list, 'items' => $items, 'meta' => $meta, 'query' => $query, 'error' => null, 'links' => $links, 'assets' => $assets,
        'texts' => ['search' => 'w4os.search_simulators', 'empty' => 'w4os.no_simulators', 'count' => 'w4os.simulators_count', 'title' => 'w4os.simulators_title']]);
}

describe('the list of a page of the admin', function () {
    $simulators = (new OpenApi(Contract::document()))->listOf('simulators');
    $solo = ['id' => 'h:9040', 'name' => 'Test Solo', 'slug' => 'demo.solo', 'grid' => 'demo', 'uri' => 'h:9040', 'httpPort' => 9040, 'enabled' => true, 'running' => true, 'connections' => ['console' => 'configured']];

    test('is a card of Tabler: its search in a toolbar, its rows in a table that keeps in its width', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1]);

        expect($html)->toContain('class="card w4os-list-card"', 'class="table table-vcenter card-table', 'class="input-icon w4os-search"', 'ti ti-search', '1 items')
            ->and($html)->not->toContain('<button type="submit">');
    });

    test('shows its first column with the others under it on a narrow screen, in grey; the others in their column on a wide one', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1], [['key' => 'action_view_item', 'url' => '/sim?id={uuid}']]);

        // The name is the link of the row
        expect($html)->toContain('href="&#x2F;sim&#x3F;id&#x3D;h&#x25;3A9040"')
            ->and($html)->toMatch('~<div class="w4os-subtitle text-secondary d-lg-none">.*demo\.solo.*demo.*h:9040~s')
            ->and($html)->toContain('<th class="w4os-cell-text d-none d-lg-table-cell">')
            // The status (indicators) shows at every width
            ->and($html)->toContain('<td class="w4os-cell-indicators">');
    });

    test('has a "…" menu of its other actions, the ones the engine asks to confirm last and marked', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1], [['key' => 'action_view_item', 'url' => '/sim?id={uuid}']]);
        $menu = substr($html, strpos($html, 'class="dropdown w4os-menu"'));

        expect($menu)->toContain('ti ti-dots', 'data-bs-toggle="dropdown"', 'class="dropdown-item" href=', '<div class="dropdown-divider"></div>')
            ->and($menu)->toMatch('~dropdown-divider.*value="restart"[^>]*class="dropdown-item text-danger"~s');
    });

    test('says so in one sentence when it has no row', function () use ($simulators) {
        expect(render_list($simulators, [], ['total' => 0]))->toContain('<div class="empty">', '<p class="empty-title">[w4os.no_simulators]</p>')
            ->not->toContain('<table');
    });

    test('pages its rows with the pagination of Tabler', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 120, 'page' => 2, 'perPage' => 50]);

        expect($html)->toContain('<ul class="pagination', 'class="page-item active"', 'class="page-link" href="?page=3"');
    });
});
