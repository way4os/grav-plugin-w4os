<?php

/** The header of a page of the plugin (pages/<name>.md). */
function page_header(string $name): array
{
    return Symfony\Component\Yaml\Yaml::parse(explode('---', (string) file_get_contents(dirname(__DIR__)."/pages/$name.md"))[1]);
}

describe('the width of the pages of the plugin, for a theme that reads it (header width)', function () {
    test('the search full width, its list and its details side by side; the guide as wide as the menu bar', function () {
        expect(page_header('search')['width'] ?? null)->toBe('full')
            ->and(page_header('guide')['width'] ?? null)->toBe('wide');
    });

    test('the other pages, forms and texts, in the theme\'s column', function () {
        foreach (['account', 'confirm', 'forgot', 'profile', 'register', 'reset'] as $name) {
            expect(page_header($name))->not->toHaveKey('width');
        }
    });
});
