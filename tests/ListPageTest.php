<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Lists;
use W4OS\Views\Contract as OpenApi;

/** The list of a page of the admin, rendered by its template with the plugin's functions as plain stubs. */
function render_list(array $list, array $items, array $meta, array $links = [], array $query = [], array $fixed = []): string
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new TwigFunction('w4os_list_view', fn (array $list, array $items, array $meta = [], array $query = [], array $links = [], array $fixed = []) => W4OS\Views\ListView::of($list, $items, $meta, $query, $links, fixed: $fixed)));
    $twig->addFunction(new TwigFunction('w4os_list_actions', fn (array $list, array $item) => W4OS\Views\Rows::actions($list, $item)));
    $twig->addFunction(new TwigFunction('w4os_count', fn (string $key, int $n) => "$n ".($key === 'w4os.filters_active' ? 'filters' : 'items')));
    $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
    $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti ti-$icon"));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
    $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
    $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => W4OS\Views\Dates::format($value, 'fr', $style)));
    $assets = new class {
        public function addCss(string $url): void {}
        public function addJs(string $url, array $options = []): void {}
    };

    return $twig->render('w4os/list.html.twig', ['list' => $list, 'items' => $items, 'meta' => $meta, 'query' => $query, 'fixed' => $fixed, 'error' => null, 'links' => $links, 'assets' => $assets,
        'texts' => ['search' => 'w4os.search_simulators', 'empty' => 'w4os.no_simulators', 'count' => 'w4os.simulators_count', 'title' => 'w4os.simulators_title']]);
}

describe('the list of a page of the admin', function () {
    $simulators = (new OpenApi(Contract::document()))->listOf('simulators');
    $solo = ['id' => 'h:9040', 'name' => 'Test Solo', 'slug' => 'demo.solo', 'grid' => 'demo', 'uri' => 'h:9040', 'httpPort' => 9040, 'enabled' => true, 'running' => true, 'connections' => ['console' => 'configured']];

    test('is a card of Tabler: its search in a toolbar, its rows in a table that keeps in its width', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1]);

        expect($html)->toContain('class="w4os-filters d-flex flex-wrap align-items-center gap-2 mb-3"', 'class="card w4os-list-card"', 'class="table table-vcenter card-table', 'class="input-icon w4os-search"', 'ti ti-search', '1 items')
            ->and($html)->not->toContain('<button type="submit">');
    });

    test('shows its first column with the others under it on a narrow screen, in grey; the others in their column on a wide one', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1], [['key' => 'action_view_item', 'url' => '/sim?id={uuid}']]);

        // The name is the link of the row
        expect($html)->toContain('href="&#x2F;sim&#x3F;id&#x3D;h&#x25;3A9040"')
            // A value that does not say what it is after the label of its column
            ->and($html)->toContain('<div class="w4os-subtitle text-secondary d-xl-none">[w4os.label_value:[w4os.column_grid]|demo] · [w4os.label_value:[w4os.column_port]|9040]</div>')
            // Its address under its name, at every width (the first of its subtitle attributes that has one)
            ->and($html)->toContain('<div class="w4os-subtitle text-secondary">h:9040</div>')
            ->and($html)->toContain('<th class="w4os-cell-text d-none d-xl-table-cell">')
            // The status (indicators) shows at every width
            ->and($html)->toContain('<td class="w4os-cell-indicators">');
    });

    test('shows a time relatively while it is recent, else as a short date with its time, its full date as its tip; an email or a role bare', function () {
        $avatars = (new OpenApi(Contract::document()))->listOf('avatars');
        $recent = time() - 3 * 86400;
        $old = 1794787200;
        $older = time() - 60 * 86400;
        $bart = ['uuid' => 'a1', 'name' => 'Bart Simpson', 'email' => 'bart@example.org', 'role' => 'resident', 'created' => $older, 'lastSeen' => $recent, 'status' => 'active'];
        $html = render_list($avatars, [$bart], ['total' => 1]);
        $short = W4OS\Views\Dates::format($older, 'fr', 'list');

        $later = $old + 15 * 3600;
        expect(W4OS\Views\Dates::format($old, 'fr', 'list', $later))->toBe('il y a 15 heures')
            ->and(W4OS\Views\Dates::format($old, 'en', 'list', $later))->toBe('15 hours ago')
            ->and(W4OS\Views\Dates::format($old, 'pt-BR', 'list', $later))->toBe('há 15 horas')
            ->and(W4OS\Views\Dates::format($old, 'de', 'list', $old + 3 * 86400))->toBe('vor 3 Tagen')
            ->and(W4OS\Views\Dates::format($old, 'en', 'list', $old + 8 * 86400))->toMatch('~^11/16/26, \\d{1,2}:\\d{2}\\s?[AP]M$~u')
            ->and(W4OS\Views\Dates::format($old, 'fr', 'long'))->toMatch('~^16 nov\\. 2026, \\d{2}:\\d{2}$~')
            ->and(W4OS\Views\Dates::format($old, 'en'))->toBe('Nov 16, 2026')
            ->and(W4OS\Views\Dates::format(null, 'fr'))->toBe('')
            ->and($html)->toContain('>bart@example.org · [w4os.role_resident] · [w4os.label_value:[w4os.born]|'.$short.'] · [w4os.label_value:[w4os.last_seen]|il y a 3 jours]</div>')
            ->and($html)->toMatch('~<time title="[^"]*'.date('Y', $older).'[^"]*">'.preg_quote($short, '~').'</time>~')
            ->and($html)->not->toContain(date('Y-m-d', $older));
    });

    test('leaves out the column of a filter the page fixes (the grid of the site)', function () use ($simulators, $solo) {
        expect(render_list($simulators, [$solo], ['total' => 1]))->toContain('[w4os.column_grid]')
            ->and(render_list($simulators, [$solo], ['total' => 1], [], [], ['grid' => 'demo']))->not->toContain('[w4os.column_grid]');
    });

    test('counts the filters chosen, with a link that removes them and keeps the search and the sort', function () use ($simulators, $solo) {
        $html = render_list($simulators, [], ['total' => 0], [], ['search' => 'solo', 'enabled' => 'yes', 'sort' => 'name', 'page' => 2]);

        expect($html)->toContain('0 items', ' · 1 filters', 'href="?search=solo&amp;sort=name"', 'role="group" aria-label="&#x5B;w4os.filters&#x5D;"');
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
