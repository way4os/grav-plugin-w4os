<?php

use W4os\Grav\Blocks;
use W4os\Grav\Format;

describe('numbers', function () {
    test('are written as the language writes them', function () {
        expect(Format::number(1234, 'en'))->toBe('1,234')
            ->and(Format::number(1234, 'fr'))->toBe("1\u{202f}234");
    });

    test('an area has its unit, and two decimals under 100 km²', function () {
        expect(Format::area(0.065536, 'fr'))->toBe("0,07\u{a0}km²")
            ->and(Format::area(1.638, 'en'))->toBe("1.64\u{a0}km²")
            ->and(Format::area(1234.5, 'en'))->toBe("1,235\u{a0}km²");
    });
});

describe('block data', function () {
    test('is the JSON of the block', function () {
        $blocks = new Blocks('https://grid.example.org/helpers', fn (string $url) => $url === 'https://grid.example.org/helpers/blocks/grid-status?format=json' ? '{"monitor":{"level":"ok"}}' : null);

        expect($blocks->data('grid-status'))->toBe(['monitor' => ['level' => 'ok']])
            ->and((new Blocks('https://grid.example.org/helpers', fn () => 'not json'))->data('grid-status'))->toBeNull();
    });
});
