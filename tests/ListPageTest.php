<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Lists;
use W4OS\Views\Contract as OpenApi;

/** The list of a page of the admin, rendered by its template with the plugin's functions as plain stubs. */
function render_list(?array $list, array $items, array $meta, array $links = [], array $query = [], array $fixed = [], ?string $error = null): string
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new TwigFunction('w4os_list_view', fn (array $list, array $items, array $meta = [], array $query = [], array $links = [], array $fixed = []) => W4OS\Views\ListView::of($list, $items, $meta, $query, $links, fixed: $fixed)));
    $twig->addFunction(new TwigFunction('w4os_count', fn (string $key, int $n) => "$n ".($key === 'w4os.filters_active' ? 'filters' : 'items')));
    $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
    $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti ti-$icon"));
    $twig->addFunction(new TwigFunction('w4os_number', fn ($value, int $decimals = 2) => W4os\Grav\Site::number((float) $value, 'en', $decimals)));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
    $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
    $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => W4OS\Views\Dates::format($value, 'fr', $style)));
    $assets = new class {
        public function addCss(string $url): void {}
        public function addJs(string $url, array $options = []): void {}
    };

    return $twig->render('w4os/list.html.twig', ['list' => $list, 'items' => $items, 'meta' => $meta, 'query' => $query, 'fixed' => $fixed, 'error' => $error, 'links' => $links, 'assets' => $assets,
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
            // Each in it until its column shows: 2 from md, 3 from xl, 4 from xxl (Tabler's breakpoints)
            ->and($html)->toContain('<div class="w4os-subtitle text-secondary w4os-folded d-xl-none"><span class="d-xl-none">[w4os.label_value:[w4os.column_grid]|demo]</span></div>')
            // Its address under its name, at every width (the first of its subtitle attributes that has one)
            ->and($html)->toContain('<div class="w4os-subtitle text-secondary">h:9040</div>')
            ->and($html)->toContain('<th class="w4os-cell-text d-none d-xl-table-cell">', '<th class="w4os-cell-number d-none d-md-table-cell">')
            // The status (indicators) shows at every width
            ->and($html)->toContain('<td class="w4os-cell-indicators">');
    });

    test('folds the values of a group one by one under the name, each with its label, until its column shows', function () {
        $regions = (new OpenApi(Contract::document()))->listOf('regions');
        $html = render_list($regions, [['uuid' => 'r1', 'name' => 'Plaza', 'avatars' => 1, 'prims' => 2150, 'location' => '8015, 8012', 'size' => 256]], ['total' => 1]);

        expect($html)->toContain('<span class="d-md-none">[w4os.label_value:[w4os.column_presence]|1]</span><span class="d-md-none">[w4os.label_value:[w4os.column_prims]|2150]</span>')
            ->and($html)->toContain('<span class="d-xl-none">[w4os.label_value:[w4os.profile_location]|8015, 8012]</span><span class="d-xl-none">[w4os.label_value:[w4os.column_size]|[w4os.unit_dimensions:256|256]]</span>')
            ->and($html)->not->toContain('[w4os.column_usage]|');
    });

    test('shows a time relatively while it is recent, else as a short date with its time, its full date as its tip; an email or a role bare', function () {
        $avatars = (new OpenApi(Contract::document()))->listOf('avatars');
        $recent = time() - 3 * 86400;
        $old = 1794787200;
        $older = time() - 60 * 86400;
        $bart = ['uuid' => 'a1', 'name' => 'Bart Simpson', 'email' => 'bart@example.org', 'shownRole' => 'resident', 'created' => $older, 'lastSeen' => $recent, 'status' => 'active'];
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
            ->and($html)->toContain('<span class="d-md-none">[w4os.role_resident]</span><span class="d-md-none">[w4os.label_value:[w4os.last_seen]|il y a 3 jours]</span><span class="d-xl-none">bart@example.org</span><span class="d-xl-none">[w4os.label_value:[w4os.born]|'.$short.']</span></div>')
            ->and($html)->toMatch('~<time title="[^"]*'.date('Y', $older).'[^"]*">'.preg_quote($short, '~').'</time>~')
            ->and($html)->not->toContain(date('Y-m-d', $older));
    });

    test('shows each value of a filter with the number of rows it gives, when the engine counts them', function () {
        $avatars = (new OpenApi(Contract::document()))->listOf('avatars');
        $html = render_list($avatars, [], ['total' => 5, 'counts' => ['status' => ['active' => 4, 'banned' => 1]]]);

        expect($html)->toContain('<option value="active" data-label="&#x5B;w4os.status_active&#x5D;">[w4os.status_active] (4)</option>')
            ->and($html)->toContain('<option value="disabled" data-label="&#x5B;w4os.status_disabled&#x5D;">[w4os.status_disabled] (0)</option>')
            ->and($html)->toContain('<option value="resident">[w4os.role_resident]</option>');
    });

    test('shows its views above it, as w4os-wp does: All, then the values of the filters the engine shows as views, with their counts, not in the toolbar', function () use ($simulators, $solo) {
        $avatars = (new OpenApi(Contract::document()))->listOf('avatars');
        $html = render_list($avatars, [], ['total' => 7, 'counts' => ['type' => ['user' => 5, 'model' => 2, 'service' => 0], 'active' => ['yes' => 4, 'no' => 1], 'online' => ['online' => 1, 'offline' => 4]]], query: ['type' => 'model', 'search' => 'de']);

        expect($html)->toMatch('~<ul class="list-inline w4os-views mb-2">\s*<li class="list-inline-item"><a href="\?search=de">\[w4os.all\] <span class="text-secondary">\(7\)</span></a></li>~')
            ->and($html)->toContain('<a href="?search=de&amp;type=user">[w4os.avatar_type_user] <span class="text-secondary">(5)</span></a>')
            ->and($html)->toContain('<a href="?search=de&amp;type=model" class="fw-bold text-reset" aria-current="page">[w4os.avatar_type_model] <span class="text-secondary">(2)</span></a>')
            ->and($html)->toContain('<a href="?search=de&amp;active=yes">[w4os.view_active] <span class="text-secondary">(4)</span></a>')
            ->and($html)->not->toContain('type=service')
            ->and($html)->not->toContain('name="online"', 'name="type"')
            ->and(render_list($simulators, [$solo], ['total' => 1]))->not->toContain('w4os-views');
    });

    test('leaves out the column of a filter the page fixes (the grid of the site)', function () use ($simulators, $solo) {
        expect(render_list($simulators, [$solo], ['total' => 1]))->toContain('[w4os.column_grid]')
            ->and(render_list($simulators, [$solo], ['total' => 1], [], [], ['grid' => 'demo']))->not->toContain('[w4os.column_grid]');
    });

    test('its filters drawn from their views: the value the page asks selected, a filter the page fixes not in the toolbar', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1], [], ['enabled' => 'yes', 'server' => 'h1']);

        expect($html)->toMatch('#<select[^>]*name="enabled"[^>]*>.*?<option value="yes"[^>]*selected#s')
            ->and($html)->toMatch('#<input type="text"[^>]*name="server"[^>]*value="h1"#')
            ->and(render_list($simulators, [$solo], ['total' => 1]))->toContain('name="grid"')
            ->and(render_list($simulators, [$solo], ['total' => 1], [], [], ['grid' => 'demo']))->not->toContain('name="grid"');
    });

    test('a list the engine does not describe (it does not answer): its error alone, no filters', function () {
        $html = render_list(null, [], [], [], [], [], 'The engine does not answer');

        expect($html)->toContain('class="alert alert-danger"', 'The engine does not answer')->and($html)->not->toContain('<select');
    });

    test('counts the filters chosen, with a link that removes them and keeps the search and the sort', function () use ($simulators, $solo) {
        $html = render_list($simulators, [], ['total' => 0], [], ['search' => 'solo', 'enabled' => 'yes', 'sort' => 'name', 'page' => 2]);

        expect($html)->toContain('0 items', ' · 1 filters', 'href="?search=solo&amp;sort=name"', 'role="group" aria-label="&#x5B;w4os.filters&#x5D;"');
    });

    test('has its view and edit links as icons (eye, pencil), a "…" menu of its other actions, the ones the engine asks to confirm last and marked', function () use ($simulators, $solo) {
        $html = render_list($simulators, [$solo], ['total' => 1], [['key' => 'action_view_item', 'url' => '/sim?id={uuid}']]);
        $menu = substr($html, strpos($html, 'class="dropdown w4os-menu"'));

        expect($html)->toMatch('~<a class="btn btn-ghost-secondary btn-icon btn-sm" href="&#x2F;sim&#x3F;id&#x3D;[^"]*" title="&#x5B;w4os.action_view_item&#x5D;"[^>]*><i class="ti ti-eye"~')
            ->and($menu)->toContain('ti ti-dots', 'data-bs-toggle="dropdown"')->not->toContain('class="dropdown-item" href=')
            // Nothing above the asked ones here (the view is an icon): no divider before them
            ->and($menu)->toMatch('~value="restart"[^>]*class="dropdown-item text-danger"~s')->not->toContain('dropdown-divider');
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
