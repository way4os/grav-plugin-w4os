<?php

namespace W4OS\Grav;

/**
 * The backdrop of the site: a picture behind its pages, one of a series at random on each page, a series for the light mode of the
 * theme and one for its dark mode. The site's own series (its settings, files in its data), else the active theme's (the images of
 * its folder images/backdrop/<mode>), on the web only (never in a viewer). Its style follows the theme's switch (data-theme on the root, Quark 2's) and, without one,
 * the browser's mode; only the image of the mode shown is loaded.
 */
final class Backdrop
{
    /** The folders of the site's own series, in its data (served, kept across upgrades), by mode. */
    public const FOLDERS = ['light' => 'user://data/theme/images/backdrop/light', 'dark' => 'user://data/theme/images/backdrop/dark'];

    /** The folder of a theme's own series, under its own folder. */
    public const THEME_FOLDER = 'images/backdrop';

    /** The widths served, the largest unless a smaller one fits its media query (a phone). */
    public const WIDTHS = [2048 => '', 1280 => '(max-width: 48rem)'];

    /** The formats served, each with its type, the first one the browser takes. */
    public const FORMATS = ['webp' => 'image/webp', 'jpg' => 'image/jpeg'];

    /** The quality of the images served. */
    public const QUALITY = 82;

    /** The pages laid out without the theme, which the backdrop leaves alone: the plugin's bare pages of a viewer. */
    public const BARE_TEMPLATES = ['w4os-welcome.html.twig', 'w4os-viewer.html.twig'];

    /**
     * Whether a page shows the backdrop: a page the theme lays out in a browser, not an admin page; never a page a viewer shows (the
     * web only: a viewer has its own day and night in the world, and no mode of its own).
     *
     * @param ?string $template the template of the page; $layout: the one it is rendered with when the plugin sets another
     * @param bool $viewer whether a viewer shows the page (Site::isViewer())
     */
    public static function shown(?string $template, ?string $layout, bool $viewer): bool
    {
        return ! $viewer && $template !== 'w4os-admin' && ! in_array($layout, self::BARE_TEMPLATES, true);
    }

    /**
     * The images of a series: the site's own whose files are there, else the theme's.
     *
     * @param array<string,mixed> $own the site's setting, as Grav's file field keeps its files: by their paths from the root
     * @param list<string> $theme the theme's series (themeSeries())
     * @param \Closure(string): bool $exists whether a file is there, by its path from the root
     * @return list<string> paths from the root of the site
     */
    public static function series(array $own, array $theme, \Closure $exists): array
    {
        $files = array_values(array_filter(array_map('strval', array_keys($own)), $exists));

        return $files !== [] ? $files : $theme;
    }

    /**
     * The images a theme gives a mode, the files of its folder images/backdrop/<mode> (FOLDER: its path from the root).
     *
     * @return list<string> paths from the root of the site, by name
     */
    public static function themeSeries(string $root, string $folder): array
    {
        $folder = trim($folder, '/');
        $files = is_dir("$root/$folder") ? array_filter((array) scandir("$root/$folder"), fn ($file) => preg_match('/\.(jpe?g|png|webp)$/i', (string) $file)) : [];
        sort($files);

        return array_map(fn ($file) => "$folder/$file", array_values($files));
    }

    /**
     * One image of a series, at random.
     *
     * @param list<string> $series
     */
    public static function pick(array $series): ?string
    {
        return $series === [] ? null : $series[array_rand($series)];
    }

    /**
     * The widths an image is served at, never larger than itself: each with its media query (none for the default).
     *
     * @return array<int,string>
     */
    public static function widths(int $width): array
    {
        $widths = [];
        foreach (self::WIDTHS as $size => $query) {
            $size = min($size, $width);
            if (! isset($widths[$size])) {
                $widths[$size] = $query;
            }
        }

        return array_filter($widths, fn ($query, $size) => $query === '' || $size < max(array_keys($widths)), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The style of the backdrop: the image of each mode as a property of the root, at each width, and the one of the mode shown
     * behind the page.
     *
     * @param array<string,array<int,array<string,string>>> $images by mode, then width (WIDTHS), then format (FORMATS): its address
     */
    public static function css(array $images): string
    {
        $images = array_filter($images);
        if ($images === []) {
            return '';
        }
        $sets = [];
        foreach ($images as $mode => $widths) {
            foreach ($widths as $width => $formats) {
                $sets[self::WIDTHS[$width] ?? ''][] = "--w4os-backdrop-$mode:".self::imageSet($formats);
            }
        }
        $css = ':root{--w4os-backdrop:var(--w4os-backdrop-light);'.implode(';', $sets[''] ?? []).'}';
        foreach ($sets as $query => $properties) {
            $query === '' || $css .= "@media $query{:root{".implode(';', $properties).'}}';
        }

        return $css
            .':root[data-theme="dark"]{--w4os-backdrop:var(--w4os-backdrop-dark)}'
            .'@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--w4os-backdrop:var(--w4os-backdrop-dark)}}'
            .':root body{background:transparent}'
            .'body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:var(--w4os-backdrop,none) center/cover no-repeat}';
    }

    /** @param array<string,string> $formats an image's addresses, by format */
    private static function imageSet(array $formats): string
    {
        $urls = [];
        foreach ($formats as $format => $url) {
            $urls[] = 'url("'.str_replace(['"', '\\', "\n"], ['%22', '%5C', ''], $url).'") type("'.(self::FORMATS[$format] ?? 'image/'.$format).'")';
        }

        return 'image-set('.implode(',', $urls).')';
    }
}
