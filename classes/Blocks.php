<?php

namespace W4os\Grav;

/**
 * The blocks of the helpers of the grid (grid info, grid status, message of the day, search, profile...), as the HTML fragments the
 * helpers make, in their own translations (the language of the visitor is asked), put in the pages of the site as they are: read at
 * `/blocks/{name}` of the helpers of the grid, or made by the helpers the plugin bundles. Plain PHP: the reading is given.
 */
final class Blocks
{
    /** The blocks a visitor may see; `health` is for the managers, with the API key. */
    public const NAMES = ['grid-info', 'grid-status', 'motd', 'destination_guide', 'popular_places', 'directory_info', 'search', 'profile', 'new_users'];

    /** The arguments a block takes from the page or the shortcode. */
    private const ARGS = ['name', 'q', 'topic', 'level', 'title', 'lang', 'assets', 'search_url', 'web_profile_url'];

    /**
     * @param \Closure(string $name, array<string,scalar> $args): ?string $fetch the HTML of a block, null when it cannot be read
     */
    public function __construct(private \Closure $fetch) {}

    /**
     * The blocks the helpers of a grid serve at their address (`https://grid.example.org/helpers`).
     *
     * @param \Closure(string $url): ?string $get the body at an address, null when it cannot be read
     */
    public static function remote(string $url, \Closure $get): self
    {
        $url = rtrim($url, '/');

        return new self(fn (string $name, array $args) => $url === '' ? null : $get("$url/blocks/$name".($args === [] ? '' : '?'.http_build_query($args))));
    }

    /**
     * The HTML of a block, empty when it is not one or cannot be read.
     *
     * @param array<string,scalar> $args name (profile), q and topic (search), level, title, the addresses of the site's pages for
     *     the links of the helpers (search_url, web_profile_url with [AGENT_NAME])
     */
    public function html(string $name, array $args = []): string
    {
        $name = strtolower(trim($name));
        // The helpers take both spellings
        $name = in_array(str_replace('-', '_', $name), self::NAMES, true) ? str_replace('-', '_', $name) : $name;
        if (! in_array($name, self::NAMES, true)) {
            return '';
        }
        $query = array_filter(array_intersect_key($args, array_flip(self::ARGS)), fn ($v) => $v !== null && $v !== '');
        $html = ($this->fetch)($name, $query);

        return is_string($html) ? trim($html) : '';
    }
}
