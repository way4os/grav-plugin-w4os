<?php

require_once __DIR__.'/Support.php';

/** The w4os_link() of the plugin's Twig, the links built from the root of a site in its first language. */
function link_function(string $prefix = ''): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_link', fn (?string $target, string $text, string $kind = 'plain', array $args = [], string $class = '') => W4OS\Views\Link::to(W4OS\Grav\Site::siteLinks('', $prefix, ['en', 'fr'], '/'), (string) $target, $text, $kind, $args, $class), ['is_safe' => ['html']]);
}

/**
 * The w4os_tp_link() of the plugin's Twig, the links the engine gives (its /place) as a browser sees them: a grid alone (its login
 * URI) added to the viewer, a region teleported to (hop://); the address to copy.
 */
function tp_link_function(): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_tp_link', function (?string $destination, ?string $label = null, ?string $html = null, ?string $prefer = null, ?array $only = null) {
        $destination = (string) $destination;
        $links = str_contains($destination, '/') ? [] : [['action' => 'addgrid', 'format' => 4096, 'link' => 'hop:///app/gridmanager/addgrid/'.rawurlencode($destination), 'label' => 'Add the grid']];
        $links[] = ['action' => 'teleport', 'format' => 8, 'link' => "hop://$destination", 'label' => 'hop'];
        $links[] = ['action' => 'copy', 'format' => 1024, 'link' => $destination, 'label' => 'Copy'];

        return W4OS\Views\TeleportLink::html(['browser' => $links], (string) $label, 'browser', ['copied' => 'Copied'], '', $html, $prefer, $only);
    }, ['is_safe' => ['html']]);
}

/** The w4os_picture_tag() of the plugin's Twig, its picture at /pictures/<uuid>.jpg (none-<default> without one). */
function picture_tag_function(): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_picture_tag', fn (?string $uuid, ?string $default = null, ?string $name = null, string $class = '', int $size = 32) => W4OS\Grav\Site::pictureTag(W4OS\Grav\Site::hasPicture($uuid) ? "/pictures/$uuid.jpg" : null, W4OS\Grav\Site::initials($name), $class, $size), ['is_safe' => ['html']]);
}
