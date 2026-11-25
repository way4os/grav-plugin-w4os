<?php

describe('the boxes of the site (css/w4os.web.css)', function () {
    $css = fn () => (string) file_get_contents(dirname(__DIR__).'/css/w4os.web.css');

    test('what the grid is in boxes: its info and its status, as the steps of the Connect block; the message of the day as a text', function () use ($css) {
        expect($css())->toContain('.w4os-home .block-grid-info, .w4os-home .block-grid-status {')
            ->and($css())->not->toContain('.block-motd {');
    });

    test('a box on the solid surface a theme gives (--w4os-surface), Canvas without one, over a picture behind the page too', function () use ($css) {
        expect($css())->toContain('.w4os-home .block-grid-info, .w4os-home .block-grid-status, .w4os-start-step, .block-search .search-box { background: var(--w4os-surface, Canvas); }')
            // The tables in them on the box itself, never on cells of their own
            ->and($css())->toContain('.w4os-home :is(.block-grid-info, .block-grid-status) .block-table :is(th, td) { background: transparent; }')
            // A step done or to do dimmed in its content, its box as solid as the others
            ->and($css())->not->toContain('.w4os-start-step.is-done, .w4os-start-step.is-todo { opacity')
            ->and((string) file_get_contents(dirname(__DIR__).'/css/w4os.css'))->toContain('.w4os-start-step:is(.is-done, .is-todo) > *, .w4os-start-step:is(.is-done, .is-todo)::before { opacity: .7; }');
    });

    test('in a viewer too on the splash page of the grid, over the backdrop: what the grid is in boxes on the solid surface', function () {
        // The plugin's own splash page too (body.w4os-welcome), its boxes never left without a surface over the backdrop
        expect((string) file_get_contents(dirname(__DIR__).'/css/w4os.viewer.css'))->toContain(':is(.w4os-home, .w4os-welcome) :is(.block-grid-info, .block-grid-status) { background: var(--w4os-surface, var(--card, Canvas)); }')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os-welcome.html.twig'))->toContain('<body class="w4os-viewer viewer w4os-welcome">');
    });

    test('a callout on the solid surface too, tinted with the color of its text, never see-through', function () use ($css) {
        expect($css())->toContain('border-radius: .5rem; background: color-mix(in srgb, currentColor 4%, var(--w4os-surface, Canvas)); }')
            ->and($css())->not->toContain('background: color-mix(in srgb, currentColor 4%, transparent); }');
    });
});
