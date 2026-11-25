<?php

namespace W4OS\Grav;

/**
 * The libraries of the browser the plugin serves (Tabler, its icons, Tom Select, Leaflet for the helpers' map): npm releases pinned in composer.json (package
 * repositories, with their checksum), installed in vendor/npm-asset/. Of each, only the files the plugin serves are kept, and its
 * license, so the release zip carries no demo, no source and no other variant.
 */
final class Assets
{
    /** What the plugin serves of each release, from its folder (a pattern takes the matching files of a folder). */
    public const KEEP = [
        'npm-asset/tabler-core' => [
            'package.json', 'dist/css/tabler.min.css', 'dist/css/tabler-vendors.min.css', 'dist/js/tabler.min.js',
            // The theme and the saved state of the sidebar, before the page renders
            'dist/js/tabler-theme.min.js',
            // Tom Select in the styles of Tabler (Bootstrap 5)
            'dist/libs/tom-select/LICENSE', 'dist/libs/tom-select/dist/css/tom-select.bootstrap5.min.css',
        ],
        'npm-asset/tabler-icons-webfont' => ['LICENSE', 'dist/tabler-icons.min.css', 'dist/fonts/tabler-icons.*.woff2'],
        'npm-asset/tom-select' => ['LICENSE', 'dist/js/tom-select.popular.min.js', 'dist/css/tom-select.min.css'],
        // The map of the grid (the helpers' block map, their View::LIBRARIES)
        'npm-asset/leaflet' => ['LICENSE', 'dist/leaflet.js', 'dist/leaflet.css', 'dist/images/*.png'],
    ];

    /** The translations of Carbon (the dates it tells relatively), one file by locale (`fr.php`, `pt_BR.php`). */
    public const LOCALES = 'nesbot/carbon/src/Carbon/Lang';

    /** composer's post-install-cmd and post-update-cmd: the vendor folder of the plugin pruned, to the languages of the plugin. */
    public static function composer(object $event): void
    {
        $vendor = (string) $event->getComposer()->getConfig()->get('vendor-dir');
        self::prune($vendor);
        self::pruneLocales($vendor, array_map(fn (string $file) => basename($file, '.yaml'), glob(dirname($vendor).'/languages/*.yaml') ?: []));
    }

    /**
     * Removes from the translations of Carbon the ones of the languages the plugin has not: a language keeps its own (`fr.php`)
     * and, for a regional one, its region's (`pt-BR`: `pt.php`, `pt_BR.php`).
     *
     * @param list<string> $languages the languages of the plugin (`fr`, `pt-BR`)
     */
    public static function pruneLocales(string $vendor, array $languages): void
    {
        $keep = [];
        foreach ($languages as $language) {
            $keep[explode('-', $language)[0]] = true;
            $keep[str_replace('-', '_', $language)] = true;
        }
        foreach (glob("$vendor/".self::LOCALES.'/*.php') ?: [] as $file) {
            isset($keep[basename($file, '.php')]) || unlink($file);
        }
    }

    /** Removes from each release in a vendor folder what the plugin does not serve. */
    public static function prune(string $vendor): void
    {
        foreach (self::KEEP as $package => $patterns) {
            $root = "$vendor/$package";
            if (! is_dir($root)) {
                continue;
            }
            $keep = [];
            foreach ($patterns as $pattern) {
                foreach (glob("$root/$pattern") ?: [] as $file) {
                    $keep[realpath($file)] = true;
                }
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    // A folder emptied of what is not kept
                    (new \FilesystemIterator($file->getPathname()))->valid() || rmdir($file->getPathname());
                } elseif (! isset($keep[realpath($file->getPathname())])) {
                    unlink($file->getPathname());
                }
            }
        }
    }
}
