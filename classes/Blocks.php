<?php

namespace W4os\Grav;

/**
 * The blocks of the helpers of the grid (`/blocks/{name}`: grid info, grid status, message of the day, search, profile...), as the
 * HTML fragments the helpers make, put in the pages of the site. Plain PHP: the reading of an address (with its cache) is given.
 */
final class Blocks
{
    /** The blocks a visitor may see; `health` is for the managers, with the API key. */
    public const NAMES = ['grid-info', 'grid-status', 'motd', 'destination_guide', 'popular_places', 'directory_info', 'search', 'profile'];

    /** The arguments a block takes from the page or the shortcode. */
    private const ARGS = ['name', 'q', 'topic', 'level', 'title', 'format'];

    /**
     * @param string $url the address of the helpers of the grid (`https://grid.example.org/helpers`)
     * @param \Closure(string $url): ?string $fetch the body at an address, null when it cannot be read
     */
    public function __construct(private string $url, private \Closure $fetch)
    {
        $this->url = rtrim($url, '/');
    }

    /**
     * The data of a block (`?format=json`), null when it is not one or cannot be read.
     *
     * @param array<string,scalar> $args
     * @return ?array<string,mixed>
     */
    public function data(string $name, array $args = []): ?array
    {
        $json = $this->html($name, ['format' => 'json'] + $args);
        $data = $json === '' ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * The HTML of a block, empty when it is not one or cannot be read.
     *
     * @param array<string,scalar> $args name (profile), q and topic (search), level, title
     */
    public function html(string $name, array $args = []): string
    {
        $name = strtolower(trim($name));
        // The helpers take both spellings
        $name = in_array(str_replace('-', '_', $name), self::NAMES, true) ? str_replace('-', '_', $name) : $name;
        if ($this->url === '' || ! in_array($name, self::NAMES, true)) {
            return '';
        }
        $query = array_filter(array_intersect_key($args, array_flip(self::ARGS)), fn ($v) => $v !== null && $v !== '');
        $html = ($this->fetch)($this->url.'/blocks/'.$name.($query === [] ? '' : '?'.http_build_query($query)));

        return is_string($html) ? trim($html) : '';
    }
}
