<?php

require_once __DIR__.'/Support.php';

/** The w4os_link() of the plugin's Twig, the links built from the root of a site in its first language. */
function link_function(string $prefix = ''): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_link', fn (?string $target, string $text, string $kind = 'plain', array $args = [], string $class = '') => W4OS\Views\Link::to(W4OS\Grav\Site::siteLinks('', $prefix, ['en', 'fr'], '/'), (string) $target, $text, $kind, $args, $class), ['is_safe' => ['html']]);
}
