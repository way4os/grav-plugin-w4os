<?php

namespace W4os\Grav;

/**
 * The libraries of the browser the plugin serves (Tabler, its icons, Tom Select): npm releases pinned in composer.json (package
 * repositories, with their checksum), installed in vendor/npm-asset/. Of each, only the files the plugin serves are kept, and its
 * license, so the release zip carries no demo, no source and no other variant.
 */
final class Assets
{
    /** What the plugin serves of each release, from its folder (a pattern takes the matching files of a folder). */
    public const KEEP = [
        'npm-asset/tabler-core' => ['package.json', 'dist/css/tabler.min.css', 'dist/css/tabler-vendors.min.css', 'dist/js/tabler.min.js'],
        'npm-asset/tabler-icons-webfont' => ['LICENSE', 'dist/tabler-icons.min.css', 'dist/fonts/tabler-icons.*.woff2'],
        'npm-asset/tom-select' => ['LICENSE', 'dist/js/tom-select.popular.min.js', 'dist/css/tom-select.min.css'],
    ];

    /** composer's post-install-cmd and post-update-cmd: the vendor folder of the plugin pruned. */
    public static function composer(object $event): void
    {
        self::prune((string) $event->getComposer()->getConfig()->get('vendor-dir'));
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
