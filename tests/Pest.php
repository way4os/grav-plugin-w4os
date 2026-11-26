<?php

// The libraries the host Grav provides (Twig, Symfony YAML, the PSR interfaces: "replace" in composer.json), from the development
// Grav (dev/grav.sh install) or the Grav GRAV_DIR names; after the plugin's, whose tools (Pest) need their own versions
$grav = getenv('GRAV_DIR') ?: dirname(__DIR__).'/tmp/grav';
is_file("$grav/vendor/autoload.php") || exit("No Grav for the libraries it provides: dev/grav.sh install, or GRAV_DIR=<a Grav>\n");
$host = require "$grav/vendor/autoload.php";
$host->unregister();
$host->register(false);

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

/** The w4os_picture_tag() of the plugin's Twig, its picture at /pictures/<uuid>.jpg (a person without one: /pictures/none-person.jpg). */
function picture_tag_function(): Twig\TwigFunction
{
    return new Twig\TwigFunction('w4os_picture_tag', fn (?string $uuid, ?string $default = null, string $class = '', int $size = 32) => W4OS\Grav\Site::pictureTag(W4OS\Grav\Site::hasPicture($uuid) ? "/pictures/$uuid.jpg" : null, $default === 'person' ? '/pictures/none-person.jpg' : null, $class, $size ?: null), ['is_safe' => ['html']]);
}
