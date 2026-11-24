<?php

describe('the boxes of the site (css/w4os.css)', function () {
    $css = fn () => (string) file_get_contents(dirname(__DIR__).'/css/w4os.css');

    test('what the grid is in boxes: its info and its status, as the steps of the Connect block; the message of the day as a text', function () use ($css) {
        expect($css())->toContain('.w4os-home .block-grid-info, .w4os-home .block-grid-status {')
            ->and($css())->not->toContain('.block-motd {');
    });

    test('a box on the solid surface of the theme (its cards, Pico\'s), Canvas without one, over a picture behind the page too', function () use ($css) {
        expect($css())->toContain(':root { --w4os-surface: var(--pico-card-background-color, Canvas); }')
            ->and($css())->toContain('.w4os-home .block-grid-info, .w4os-home .block-grid-status, .w4os-start-step, .block-search.client-browser .search-box { background: var(--w4os-surface); }')
            // The tables in them on the box itself, never on cells of their own
            ->and($css())->toContain('.w4os-home :is(.block-grid-info, .block-grid-status) .block-table :is(th, td) { background: transparent; }')
            // A step done or to do dimmed in its content, its box as solid as the others
            ->and($css())->not->toContain('.w4os-start-step.is-done, .w4os-start-step.is-todo { opacity')
            ->and($css())->toContain('.w4os-start-step:is(.is-done, .is-todo) > *, .w4os-start-step:is(.is-done, .is-todo)::before { opacity: .7; }');
    });
});
