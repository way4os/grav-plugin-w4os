<?php

/**
 * What the plugin leaves to the engine: every teleport link comes from the object it leads to (tpLink(), tpLinks(), given by the
 * API), never built here.
 */
describe('architecture', function () {
    test('builds no teleport link itself: no Destination::format(), no link of a grid, its regions or places made here', function () {
        $made = [];
        $files = [dirname(__DIR__).'/w4os.php'];
        foreach (['classes', 'templates'] as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)."/$folder", FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = (string) $file;
            }
        }
        foreach ($files as $file) {
            if (preg_match('/Destination::format\\s*\\(|->link\\s*\\(\\s*\\)|Destination::(AUTO|HOP|GRID|APPTP|MAP|HG|V3HG)\\b(?!.*TXT)/', (string) file_get_contents($file)) === 1) {
                $made[] = str_replace(dirname(__DIR__).'/', '', $file);
            }
        }

        expect($made)->toBe([]);
    });
});
