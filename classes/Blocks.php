<?php

namespace W4OS\Grav;

/**
 * The blocks of the helpers of the grid (grid info, grid status, message of the day, search, profile...), as the HTML fragments the
 * helpers make, in their own translations (the language of the visitor is asked), put in the pages of the site as they are: read at
 * `/blocks/{name}` of the helpers of the grid, or made by the helpers the plugin bundles. Plain PHP: the reading is given.
 */
final class Blocks
{
    /** The blocks a visitor may see; `health` is for the managers, with the API key. */
    public const NAMES = ['grid-info', 'grid-status', 'motd', 'destination_guide', 'popular_places', 'directory_info', 'search', 'profile', 'new_users', 'map'];

    /** The blocks the site does not keep: their content changes with what is asked and at once (a profile published since). */
    private const LIVE = ['search'];

    /** The arguments a block takes from the page or the shortcode. */
    private const ARGS = ['name', 'feed_only', 'q', 'topic', 'category', 'level', 'title', 'lang', 'assets', 'search_url', 'web_profile_url', 'client', 'count', 'include_hypergrid', 'include_landsales', 'avatar', 'map_tiles', 'fill', 'guide_page'];

    /**
     * @param \Closure(string $name, array<string,scalar> $args): ?string $fetch the HTML of a block (BlockUnavailable when it cannot
     *     be read)
     */
    public function __construct(private \Closure $fetch) {}

    /** Whether the site keeps a block a while (the setting `cache`), or asks for it each time. */
    public static function kept(string $name): bool
    {
        return ! in_array(str_replace('-', '_', strtolower(trim($name))), self::LIVE, true);
    }

    /**
     * The blocks the helpers of a grid serve at their address (`https://grid.example.org/helpers`).
     *
     * @param \Closure(string $url): ?string $get the body at an address, null when it cannot be read
     */
    public static function remote(string $url, \Closure $get): self
    {
        $url = rtrim($url, '/');

        return new self(fn (string $name, array $args) => $url === ''
            ? throw new BlockUnavailable('no address of the helpers is set (setting url)', 'admin_blocks_no_url')
            : $get("$url/blocks/$name".($args === [] ? '' : '?'.http_build_query($args))));
    }

    /**
     * A block as the helpers answered it: its HTML and its notices to the visitor (their header X-Helpers-Notices).
     *
     * @param array<string,list<string>> $headers
     * @return array{html: string, notices: list<array{level: string, text: string}>}
     * @throws BlockUnavailable when they did not give it
     */
    public static function answer(int $status, string $body, array $headers): array
    {
        $status === 200 || throw new BlockUnavailable("HTTP $status", 'admin_blocks_http', [(string) $status]);
        $notices = json_decode((string) ($headers['x-helpers-notices'][0] ?? ''), true);

        return ['html' => $body, 'notices' => is_array($notices) ? $notices : []];
    }

    /**
     * What the site says of the blocks of a page that could not be read: to a manager, once for each reason, the blocks it took
     * and a link to the health of the grid; to a visitor, that the page is unavailable for now, only when the block is the whole
     * page (profile, guide, search), without a reason. Each the level of a message of Grav and its HTML.
     *
     * @param array<string,string> $failures the reason of each block that could not be read
     * @param ?string $page the block that is the whole page
     * @param ?string $health the address of the page of the health of the grid, for whom may open it
     * @param \Closure(string $key, array<int,string> $vars): string $t the text of a key of the catalog
     * @return list<array{level: string, text: string}>
     */
    public static function notices(array $failures, bool $manager, ?string $page, ?string $health, \Closure $t): array
    {
        if ($failures === []) {
            return [];
        }
        if (! $manager) {
            return $page !== null && isset($failures[self::name($page)]) ? [['level' => 'warning', 'text' => htmlspecialchars($t('w4os.error_channel_unavailable', []))]] : [];
        }
        $byReason = [];
        foreach ($failures as $name => $reason) {
            $byReason[$reason][] = $name;
        }
        $link = $health === null ? '' : ' <a href="'.htmlspecialchars($health).'">'.htmlspecialchars($t('w4os.admin_health_title', [])).'</a>';
        $notices = [];
        foreach ($byReason as $reason => $names) {
            $notices[] = ['level' => 'error', 'text' => htmlspecialchars($t('w4os.admin_blocks_unavailable', [implode(', ', $names), (string) $reason])).$link];
        }

        return $notices;
    }

    /** The reason a block could not be read, without the address it was asked at: the same for every block it took. */
    public static function reason(string $message): string
    {
        return trim(rtrim((string) preg_replace('~\s*(?:for|at)?\s*"https?://[^"]*"~', '', $message), '.'));
    }

    /** The name of a block as the helpers have it (both spellings are taken). */
    public static function name(string $name): string
    {
        $name = strtolower(trim($name));

        return in_array(str_replace('-', '_', $name), self::NAMES, true) ? str_replace('-', '_', $name) : $name;
    }

    /**
     * The arguments of a block from the parameters of its shortcode, as w4os-wp writes them: its name (a profile), its title and
     * level, how many places (`max`, `count`), include-hypergrid and include-landsales (alone, a flag; or with a value), in this
     * order, those given only.
     *
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    public static function shortcodeArgs(array $parameters): array
    {
        $args = [];
        foreach ($parameters as $name => $value) {
            $name = str_replace('-', '_', strtolower((string) $name));
            $name === 'max' && $name = 'count';
            $flag = in_array($name, ['include_hypergrid', 'include_landsales'], true);
            if (! in_array($name, ['name', 'level', 'title', 'count', 'include_hypergrid', 'include_landsales'], true) || (! $flag && ($value === null || $value === ''))) {
                continue;
            }
            $args[$name] = $flag && ($value === null || $value === '') ? '1' : $value;
        }
        $order = array_flip(['name', 'level', 'title', 'count', 'include_hypergrid', 'include_landsales']);
        uksort($args, fn ($a, $b) => $order[$a] <=> $order[$b]);

        return $args;
    }

    /**
     * The parameters of the page a search takes, as the helpers list them (SearchBlock::PARAMETERS: a new field of their form needs
     * nothing here); q and topic with helpers before that list.
     *
     * @return list<string>
     */
    public static function searchParameters(): array
    {
        Bundled::library();
        $listed = \W4OS\Helpers\Block\SearchBlock::class.'::PARAMETERS';

        return defined($listed) ? array_values(array_map('strval', (array) constant($listed))) : ['q', 'topic'];
    }

    /**
     * The HTML of a block, empty when it is not one.
     *
     * @param array<string,scalar|list<scalar>> $args name and feed_only (profile: its stream alone), the parameters of a search (searchParameters(): q, rating[]...), category (destination_guide), count, include_hypergrid and include_landsales (popular_places), client (the class of the visitor, viewer or browser: the engine gives the links for where the page is shown), level, title, the addresses of the site's pages for
     *     the links of the helpers (search_url, web_profile_url with [AGENT_NAME]), avatar (motd: the name of the avatar signed in), map_tiles (map: the address of its tiles on the site), fill (map: the whole height left in the window), guide_page (destination_guide: the page its categories open on)
     * @throws BlockUnavailable when it cannot be read
     */
    public function html(string $name, array $args = []): string
    {
        $name = self::name($name);
        if (! in_array($name, self::NAMES, true)) {
            return '';
        }
        // An empty title is asked as it is: the block leaves its title to the page
        $taken = $name === 'search' ? [...self::ARGS, ...self::searchParameters()] : self::ARGS;
        // A list (the ratings of a search) of its words only, never deeper
        $args = array_map(fn ($v) => is_array($v) ? array_values(array_filter(array_map(fn ($one) => is_scalar($one) ? (string) $one : '', $v), fn ($one) => $one !== '')) : $v, $args);
        $query = array_filter(array_intersect_key($args, array_flip($taken)), fn ($v, $k) => $v !== null && $v !== [] && ($v !== '' || $k === 'title'), ARRAY_FILTER_USE_BOTH);
        $html = ($this->fetch)($name, $query);

        return is_string($html) ? trim($html) : '';
    }
}
