<?php

use W4OS\Grav\Site;

describe('the engine the site asks', function () {
    test('is the API of the engine whenever the site has its address and key, whatever bundles the helpers: only the engine touches the configs', function () {
        expect(Site::engineChannel(['engine' => 'bundled', 'api_url' => 'http://127.0.0.1:8780/api'], true))->toBe('api')
            ->and(Site::engineChannel(['engine' => 'remote', 'api_url' => 'http://127.0.0.1:8780/api'], true))->toBe('api')
            ->and(Site::engineChannel(['engine' => 'bundled', 'api_url' => 'http://127.0.0.1:8780/api'], false))->toBe('bundled')
            ->and(Site::engineChannel(['engine' => 'bundled', 'api_url' => ''], true))->toBe('bundled')
            ->and(Site::engineChannel(['engine' => 'remote', 'api_url' => ''], false))->toBe('api');
    });
});
