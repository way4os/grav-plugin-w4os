<?php

use W4OS\Grav\BlockUnavailable;
use W4OS\Grav\Blocks;

describe('blocks', function () {
    test('reads a block of the helpers with its arguments', function () {
        $asked = [];
        $blocks = Blocks::remote('https://grid.example.org/helpers/', function (string $url) use (&$asked) {
            $asked[] = $url;

            return " <section>$url</section> ";
        });

        expect($blocks->html('profile', ['name' => 'Ada.Love', 'other' => 'x', 'q' => '']))->toBe('<section>https://grid.example.org/helpers/blocks/profile?name=Ada.Love</section>')
            ->and($blocks->html('popular-places'))->toContain('/blocks/popular_places')
            ->and($blocks->html('grid-status'))->toContain('/blocks/grid-status')
            ->and($asked)->toHaveCount(3);
    });

    test('gives the helpers the addresses of the site\'s pages, in its language, for their links', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args, JSON_UNESCAPED_SLASHES));
        $site = ['search_url' => 'https://site.example.org/fr/search', 'web_profile_url' => 'https://site.example.org/fr/profile/[AGENT_NAME]'];

        expect(json_decode($blocks->html('search', ['q' => 'Ada'] + $site), true))->toBe(['q' => 'Ada'] + $site);
    });

    test('asks a search for every parameter of its form the helpers list (q and topic before they list them), its ratings a list', function () {
        $listed = W4OS\Helpers\Block\SearchBlock::class.'::PARAMETERS';
        $parameters = Blocks::searchParameters();
        $page = ['rating' => ['general', 'moderate', '', ['deeper']], 'other' => 'y'] + array_fill_keys($parameters, 'x');
        $asked = json_decode((new Blocks(fn (string $name, array $args) => json_encode($args)))->html('search', $page), true);
        $url = '';
        Blocks::remote('https://grid.example.org/helpers', function (string $at) use (&$url) {
            return $url = $at;
        })->html('search', $page);

        expect($parameters)->toBe(defined($listed) ? array_values((array) constant($listed)) : ['q', 'topic'])
            ->and(array_keys($asked))->toEqualCanonicalizing($parameters)
            ->and($asked)->not->toHaveKey('other')
            // Read from the page by the plugin, the list of the helpers, never one of its own
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("foreach (\\W4OS\\Grav\\Blocks::searchParameters() as \$parameter)", "\$args[\$parameter] ??= \$uri->query(\$parameter);");
        if (in_array('rating', $parameters, true)) {
            expect($asked['rating'])->toBe(['general', 'moderate'])
                ->and(urldecode($url))->toContain('rating[0]=general&rating[1]=moderate');
        }
        // Another block takes none of them
        expect(json_decode((new Blocks(fn (string $name, array $args) => json_encode($args)))->html('popular_places', ['rating' => ['general'], 'when' => 'today']), true))->toBe([]);
    });

    test('asks the popular places for the options the page gives them, as w4os-wp has them', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('popular_places', ['count' => '3', 'include_hypergrid' => '1', 'include_landsales' => '0']), true))
            ->toBe(['count' => '3', 'include_hypergrid' => '1', 'include_landsales' => '0']);
    });

    test('takes the options of a shortcode as w4os-wp writes them: max, and include-hypergrid alone as a flag', function () {
        expect(Blocks::shortcodeArgs(['max' => '5', 'include-hypergrid' => null, 'include-landsales' => '0', 'title' => 'Places']))
            ->toBe(['title' => 'Places', 'count' => '5', 'include_hypergrid' => '1', 'include_landsales' => '0']);
    });

    test('takes of a shortcode the options of its block, nothing else', function () {
        expect(Blocks::shortcodeArgs(['block' => 'popular-places', 'count' => '3', 'include_hypergrid' => '1', 'title' => 'Places', 'onclick' => 'x', 'name' => null]))
            ->toBe(['title' => 'Places', 'count' => '3', 'include_hypergrid' => '1']);
    });

    test('asks the guide for the category the page shows', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('destination_guide', ['category' => 'Hang out']), true))->toBe(['category' => 'Hang out'])
            // Its categories open on the page of the guide, wherever the block is (the home)
            ->and(json_decode($blocks->html('destination_guide', ['guide_page' => '/fr/guide']), true))->toBe(['guide_page' => '/fr/guide'])
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("'guide_page' => \\W4OS\\Grav\\Blocks::name(\$name) === 'destination_guide' && isset(\$this->site()->routes()['guide']) ? \$this->site()->path('guide', true) : null,");
    });

    test('gives the helpers the class of the visitor (client), so the engine links for where the page is shown', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('popular_places', ['client' => 'viewer']), true))->toBe(['client' => 'viewer'])
            // Not its user agent: the site keeps one copy of a block per class, not per browser
            ->and(json_decode($blocks->html('popular_places', ['agent' => 'Mozilla/5.0 Chrome/131']), true))->toBe([]);
    });

    test('asks the helpers for the stream of a profile alone when the page asks it (feed_only)', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('profile', ['name' => 'Ada.Love', 'feed_only' => 'true']), true))->toBe(['name' => 'Ada.Love', 'feed_only' => 'true']);
    });

    test('asks a block without its title when the page shows its own (an empty title)', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('profile', ['name' => 'Ada.Love', 'title' => '']), true))->toBe(['name' => 'Ada.Love', 'title' => ''])
            ->and(json_decode($blocks->html('profile', ['name' => 'Ada.Love']), true))->toBe(['name' => 'Ada.Love']);
    });

    test('keeps every block a while but the search, whose results change as soon as a profile is published', function () {
        expect(Blocks::kept('search'))->toBeFalse()
            ->and(Blocks::kept('profile'))->toBeTrue()
            ->and(Blocks::kept('grid-info'))->toBeTrue();
    });

    test('a block that is not one, or not public, is never asked', function () {
        $blocks = Blocks::remote('https://grid.example.org/helpers', fn () => throw new LogicException('asked'));

        expect($blocks->html('health'))->toBe('')->and($blocks->html('../avatars'))->toBe('')
            ->and((new Blocks(fn (string $name, array $args) => "<p>$name ".json_encode($args).'</p>'))->html('profile', ['name' => 'Ada.Love']))->toBe('<p>profile {"name":"Ada.Love"}</p>');
    });

    test('that cannot be read say why: no address of the helpers, an error of the helpers, no answer', function () {
        expect(fn () => Blocks::remote('', fn () => 'x')->html('grid-info'))->toThrow(BlockUnavailable::class, 'no address of the helpers is set (setting url)')
            ->and(fn () => Blocks::remote('https://grid.example.org/helpers', fn () => throw new BlockUnavailable('Idle timeout reached'))->html('profile'))
            ->toThrow(BlockUnavailable::class, 'Idle timeout reached')
            ->and(fn () => Blocks::answer(502, '<p>x</p>', []))->toThrow(BlockUnavailable::class, 'HTTP 502')
            ->and(Blocks::reason('Failed to connect to 127.0.0.1 port 28698 after 0 ms: Could not connect to server for "http://127.0.0.1:28698/helpers/blocks/motd?lang=en".'))
            ->toBe('Failed to connect to 127.0.0.1 port 28698 after 0 ms: Could not connect to server')
            ->and(Blocks::reason('Idle timeout reached for "https://grid.example.org/helpers/blocks/profile?name=Ada.Love".'))->toBe('Idle timeout reached')
            ->and(Blocks::answer(200, '<p>x</p>', ['x-helpers-notices' => ['[{"level":"info","text":"Hi"}]']]))->toBe(['html' => '<p>x</p>', 'notices' => [['level' => 'info', 'text' => 'Hi']]]);
    });
});

describe('the page of a profile', function () {
    test('is a page that does not exist for an avatar the grid does not have, unavailable for now when the grid cannot be reached', function () {
        expect(W4OS\Grav\Site::profileStatus(new W4OS\Grav\FormError('w4os.error_not_found')))->toBe(404)
            ->and(W4OS\Grav\Site::profileStatus(new W4OS\Grav\FormError('w4os.error_channel_unavailable')))->toBe(503)
            ->and(W4OS\Grav\Site::profileStatus(null))->toBe(404);
    });
});

describe('the blocks that cannot be read', function () {
    $t = fn (string $key, array $vars = []) => "[$key".($vars ? ':'.implode('|', $vars) : '').']';

    test('are said to a manager, once for each reason, with the blocks it took and a link to the health of the grid', function () use ($t) {
        $notices = Blocks::notices(['profile' => 'Idle timeout reached', 'grid-info' => 'Idle timeout reached', 'motd' => 'HTTP 500 <b>'], true, 'profile', '/w4os/health', $t);

        expect($notices)->toBe([
            ['level' => 'error', 'text' => '[w4os.admin_blocks_unavailable:profile, grid-info|Idle timeout reached] <a href="/w4os/health">[w4os.admin_health_title]</a>'],
            ['level' => 'error', 'text' => '[w4os.admin_blocks_unavailable:motd|HTTP 500 &lt;b&gt;] <a href="/w4os/health">[w4os.admin_health_title]</a>'],
        ]);
    });

    test('say their reason in the language of the page, from a key of the catalog', function () use ($t) {
        $reason = function (Closure $failing) use ($t) {
            try {
                $failing();
            } catch (W4OS\Grav\BlockUnavailable $e) {
                return $e->reason($t);
            }
        };

        expect($reason(fn () => (Blocks::remote('', fn () => ''))->html('grid-info', [])))->toBe('[w4os.admin_blocks_no_url]')
            ->and($reason(fn () => Blocks::answer(500, '', [])))->toBe('[w4os.admin_blocks_http:500]')
            ->and($reason(fn () => throw new W4OS\Grav\BlockUnavailable('Idle timeout reached', 'admin_blocks_no_answer', ['Idle timeout reached'])))
            ->toBe('[w4os.admin_blocks_no_answer:Idle timeout reached]')
            // A reason the helpers said themselves (in the language they were asked): as it is
            ->and($reason(fn () => throw new W4OS\Grav\BlockUnavailable('La base de données est injoignable.')))->toBe('La base de données est injoignable.');
    });

    test('are said to a visitor only when the block is the whole page, without a reason', function () use ($t) {
        expect(Blocks::notices(['profile' => 'Idle timeout reached'], false, 'profile', null, $t))->toBe([['level' => 'warning', 'text' => '[w4os.error_channel_unavailable]']])
            ->and(Blocks::notices(['grid-info' => 'Idle timeout reached'], false, null, null, $t))->toBe([])
            ->and(Blocks::notices([], true, 'profile', '/w4os/health', $t))->toBe([]);
    });
});
