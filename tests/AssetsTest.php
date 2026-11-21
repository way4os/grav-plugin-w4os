<?php

use W4OS\Grav\Assets;

describe('assets', function () {
    test('the libraries of the browser are pinned to their npm release, with its checksum', function () {
        $packages = [];
        foreach (json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true)['repositories'] as $repository) {
            ($repository['type'] ?? '') === 'package' && $packages[$repository['package']['name']] = $repository['package'];
        }

        expect(array_keys($packages))->toEqualCanonicalizing(array_keys(Assets::KEEP))
            ->and($packages['npm-asset/tabler-core'])->toMatchArray(['version' => '1.7.5'])
            ->and($packages['npm-asset/tabler-core']['dist'])->toBe(['type' => 'tar', 'url' => 'https://registry.npmjs.org/@tabler/core/-/core-1.7.5.tgz', 'shasum' => '1f9aea2f37d8048ac6df9217be6ce6c80b2f3081'])
            ->and($packages['npm-asset/tabler-icons-webfont']['dist']['shasum'])->toBe('29a3484cbc7ef9347fc68b9f30a883be120538e7')
            ->and($packages['npm-asset/tom-select']['dist']['shasum'])->toBe('87325ad8f308c1bdba3d1b8941171c3a485e4a94');
    });

    test('of a release, only what the plugin serves is kept, and its license', function () {
        $vendor = sys_get_temp_dir().'/w4os-assets-'.bin2hex(random_bytes(4));
        $files = [
            'npm-asset/tabler-core/package.json', 'npm-asset/tabler-core/README.md', 'npm-asset/tabler-core/dist/css/tabler.min.css', 'npm-asset/tabler-core/dist/css/tabler-vendors.min.css',
            'npm-asset/tabler-core/dist/js/tabler.min.js', 'npm-asset/tabler-core/dist/js/tabler-theme.min.js', 'npm-asset/tabler-core/dist/js/tabler-theme.js', 'npm-asset/tabler-core/dist/img/flags/fr.svg', 'npm-asset/tabler-core/scss/tabler.scss',
            'npm-asset/tabler-core/dist/libs/tom-select/LICENSE', 'npm-asset/tabler-core/dist/libs/tom-select/dist/css/tom-select.bootstrap5.min.css',
            'npm-asset/tabler-core/dist/libs/tom-select/dist/js/tom-select.base.min.js',
            'npm-asset/tabler-icons-webfont/LICENSE', 'npm-asset/tabler-icons-webfont/dist/tabler-icons.min.css',
            'npm-asset/tabler-icons-webfont/dist/fonts/tabler-icons.arrows-1.woff2', 'npm-asset/tabler-icons-webfont/dist/fonts/tabler-icons-200.arrows-1.woff2',
            'npm-asset/tabler-icons-webfont/dist/fonts/tabler-icons.arrows-1.ttf',
            'npm-asset/tom-select/LICENSE', 'npm-asset/tom-select/dist/js/tom-select.popular.min.js', 'npm-asset/tom-select/dist/js/tom-select.complete.js',
            'npm-asset/tom-select/dist/css/tom-select.min.css', 'w4os/w4os-engine/README.md',
        ];
        foreach ($files as $file) {
            is_dir(dirname("$vendor/$file")) || mkdir(dirname("$vendor/$file"), 0777, true);
            file_put_contents("$vendor/$file", 'x');
        }
        Assets::prune($vendor);
        $left = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS)) as $file) {
            $left[] = substr($file->getPathname(), strlen($vendor) + 1);
        }

        expect($left)->toEqualCanonicalizing([
            'npm-asset/tabler-core/package.json', 'npm-asset/tabler-core/dist/css/tabler.min.css', 'npm-asset/tabler-core/dist/css/tabler-vendors.min.css',
            'npm-asset/tabler-core/dist/js/tabler.min.js', 'npm-asset/tabler-core/dist/js/tabler-theme.min.js',
            'npm-asset/tabler-core/dist/libs/tom-select/LICENSE', 'npm-asset/tabler-core/dist/libs/tom-select/dist/css/tom-select.bootstrap5.min.css',
            'npm-asset/tabler-icons-webfont/LICENSE', 'npm-asset/tabler-icons-webfont/dist/tabler-icons.min.css',
            'npm-asset/tabler-icons-webfont/dist/fonts/tabler-icons.arrows-1.woff2',
            'npm-asset/tom-select/LICENSE', 'npm-asset/tom-select/dist/js/tom-select.popular.min.js', 'npm-asset/tom-select/dist/css/tom-select.min.css',
            'w4os/w4os-engine/README.md',
        ]);
        exec('rm -rf '.escapeshellarg($vendor));
    });

    test('of the translations of Carbon, only the ones of the languages of the plugin are kept, a region with its language', function () {
        $vendor = sys_get_temp_dir().'/w4os-locales-'.bin2hex(random_bytes(4));
        mkdir("$vendor/".Assets::LOCALES, 0777, true);
        foreach (['en', 'fr', 'fr_CA', 'pt', 'pt_BR', 'pt_PT', 'ja', 'cy'] as $locale) {
            file_put_contents("$vendor/".Assets::LOCALES."/$locale.php", '<?php return [];');
        }
        Assets::pruneLocales($vendor, ['en', 'fr', 'pt-BR', 'cy']);

        expect(array_map(fn ($file) => basename($file, '.php'), glob("$vendor/".Assets::LOCALES.'/*.php')))->toEqualCanonicalizing(['en', 'fr', 'pt', 'pt_BR', 'cy']);
    });
});
