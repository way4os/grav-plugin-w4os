<?php

use W4os\Grav\Blocks;

describe('blocks', function () {
    test('reads a block of the helpers with its arguments', function () {
        $asked = [];
        $blocks = new Blocks('https://grid.example.org/helpers/', function (string $url) use (&$asked) {
            $asked[] = $url;

            return " <section>$url</section> ";
        });

        expect($blocks->html('profile', ['name' => 'Ada.Love', 'other' => 'x', 'q' => '']))->toBe('<section>https://grid.example.org/helpers/blocks/profile?name=Ada.Love</section>')
            ->and($blocks->html('popular-places'))->toContain('/blocks/popular_places')
            ->and($blocks->html('grid-status'))->toContain('/blocks/grid-status')
            ->and($asked)->toHaveCount(3);
    });

    test('a block that is not one, or not public, is never asked', function () {
        $blocks = new Blocks('https://grid.example.org/helpers', fn () => throw new LogicException('asked'));

        expect($blocks->html('health'))->toBe('')->and($blocks->html('../avatars'))->toBe('')
            ->and((new Blocks('', fn () => 'x'))->html('grid-info'))->toBe('');
    });
});
