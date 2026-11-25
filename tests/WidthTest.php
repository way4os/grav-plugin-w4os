<?php

/** The header of a page of the plugin (pages/<name>.md). */
function page_header(string $name): array
{
    return Symfony\Component\Yaml\Yaml::parse(explode('---', (string) file_get_contents(dirname(__DIR__)."/pages/$name.md"))[1]);
}

describe('the width of the pages of the plugin', function () {
    test('is the theme\'s, for the page of each type (w4os-search, w4os-map...): no page of the plugin says its own', function () {
        foreach (glob(dirname(__DIR__).'/pages/*.md') as $file) {
            expect(page_header(basename($file, '.md')))->not->toHaveKey('width', basename($file));
        }
    });
});
