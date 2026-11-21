<?php

use Symfony\Component\Yaml\Yaml;
use W4OS\Grav\Site;

test('a notice of the engine is told in the language of the page, else in English, an old one as it is', function () {
    $french = Yaml::parseFile(dirname(__DIR__).'/languages/fr.yaml')['w4os'];
    $translate = fn (string $key) => $french[substr($key, 5)] ?? $key;
    $notice = ['code' => 'robust-config-line', 'params' => ['file' => 'Robust.ini', 'line' => 'Region_A = "FallbackRegion"', 'section' => 'GridService', 'restart' => 'opensim restart demo'], 'detail' => 'Robust.ini cannot be written'];

    expect(Site::noticeText($notice, $translate))->toStartWith('Robust.ini ne peut pas être écrit')->toContain('Region_A = "FallbackRegion"', '[GridService]', 'opensim restart demo')->not->toContain('{')
        ->and(Site::noticeText(['code' => 'unknown-thing', 'detail' => 'In English'], $translate))->toBe('In English')
        ->and(Site::noticeText('An old notice', $translate))->toBe('An old notice');
});
