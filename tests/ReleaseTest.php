<?php

describe('the release zip', function () {
    test('built, carries nothing of macOS (._*, .DS_Store, __MACOSX)', function () {
        $zips = glob(dirname(__DIR__).'/dist/grav-plugin-w4os-*.zip') ?: [];
        usort($zips, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        if ($zips === []) {
            $this->markTestSkipped('no zip built (vendor/bin/build-tools build zip)');
        }
        $zip = new ZipArchive;
        expect($zip->open($zips[0]))->toBeTrue();
        $junk = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            preg_match('#(^|/)(\._[^/]*|\.DS_Store|__MACOSX)(/|$)#', $name) === 1 && $junk[] = $name;
        }
        $zip->close();

        expect($junk)->toBe([]);
    });
});
