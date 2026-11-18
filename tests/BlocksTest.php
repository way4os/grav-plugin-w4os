<?php

use W4os\Grav\Blocks;

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

    test('asks the guide for the category the page shows', function () {
        $blocks = new Blocks(fn (string $name, array $args) => json_encode($args));

        expect(json_decode($blocks->html('destination_guide', ['category' => 'Hang out']), true))->toBe(['category' => 'Hang out']);
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

    test('keeps every block a while but the search, whose results change as soon as a profile is published', function () {
        expect(Blocks::kept('search'))->toBeFalse()
            ->and(Blocks::kept('profile'))->toBeTrue()
            ->and(Blocks::kept('grid-info'))->toBeTrue();
    });

    test('a block that is not one, or not public, is never asked', function () {
        $blocks = Blocks::remote('https://grid.example.org/helpers', fn () => throw new LogicException('asked'));

        expect($blocks->html('health'))->toBe('')->and($blocks->html('../avatars'))->toBe('')
            ->and(Blocks::remote('', fn () => 'x')->html('grid-info'))->toBe('')
            ->and((new Blocks(fn (string $name, array $args) => "<p>$name ".json_encode($args).'</p>'))->html('profile', ['name' => 'Ada.Love']))->toBe('<p>profile {"name":"Ada.Love"}</p>');
    });
});
