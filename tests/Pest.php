<?php

require_once __DIR__.'/Support.php';

/** The w4os_link() of the plugin's Twig, the links built from the root of a site in its first language. */
function link_function(string $prefix = ''): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_link', fn (?string $target, string $text, string $kind = 'plain', array $args = [], string $class = '') => W4OS\Views\Link::to(W4OS\Grav\Site::siteLinks('', $prefix, ['en', 'fr'], '/'), (string) $target, $text, $kind, $args, $class), ['is_safe' => ['html']]);
}

/** The w4os_picture_tag() of the plugin's Twig, its picture at /pictures/<uuid>.jpg (none-<default> without one). */
function picture_tag_function(): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_picture_tag', fn (?string $uuid, ?string $default = null, ?string $name = null, string $class = '', int $size = 32) => W4OS\Grav\Site::pictureTag(W4OS\Grav\Site::hasPicture($uuid) ? "/pictures/$uuid.jpg" : null, W4OS\Grav\Site::initials($name), $class, $size), ['is_safe' => ['html']]);
}
