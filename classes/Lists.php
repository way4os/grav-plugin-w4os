<?php

namespace W4os\Grav;

/**
 * A list of the engine on a page of the site, from its description (Contract::listOf()): the filters the page shows, the query of
 * the engine and the query the page keeps in its links, built from the address (search, filters, sort, page) and checked against
 * what the list allows. The rules of a row (its actions, their request) are w4os-views' (Rows).
 */
final class Lists
{
    /**
     * The list with only the filters a page shows, in its order (all of them without a choice).
     *
     * @param array<string,mixed> $list
     * @param ?list<string> $names
     * @return array<string,mixed>
     */
    public static function showing(array $list, ?array $names): array
    {
        if ($names !== null) {
            $filters = array_column($list['filters'], null, 'name');
            $list['filters'] = array_values(array_filter(array_map(fn ($name) => $filters[$name] ?? null, $names)));
        }

        return $list;
    }

    /**
     * The query of the engine and the one of the page.
     *
     * @param array<string,mixed> $list the list (Contract::listOf()), with the filters the page shows
     * @param array<string,mixed> $get the query of the address
     * @param array<string,scalar> $fixed the filters the page always asks the engine (`technical => no`)
     * @return array{api: array<string,scalar>, page: array<string,string|list<string>>}
     */
    public static function query(array $list, array $get, int $perPage, array $fixed = []): array
    {
        $page = [];
        $search = trim((string) ($get['search'] ?? ''));
        $list['search'] !== null && $search !== '' && $page['search'] = $search;
        foreach ($list['filters'] as $filter) {
            $asked = $get[$filter['name']] ?? '';
            if (! empty($filter['multiple'])) {
                // Several values: a list, or one text with commas; the ones the filter has, in its order
                $values = array_map('trim', is_array($asked) ? array_map('strval', $asked) : explode(',', (string) $asked));
                // The values the filter has; any, of a dynamic filter the engine lists none of (the rows' ones)
                $known = ! empty($filter['dynamic']) && $filter['values'] === []
                    ? array_values(array_unique(array_filter($values, fn ($value) => $value !== '' && mb_strlen($value) <= 200)))
                    : array_values(array_intersect(array_column($filter['values'], 'value'), $values));
                $known === [] || $page[$filter['name']] = $known;
            } elseif (self::allowed($filter, $value = trim(is_scalar($asked) ? (string) $asked : ''))) {
                $page[$filter['name']] = $value;
            }
        }
        $asked = (string) ($get['sort'] ?? '');
        $descending = $list['sort']['descending'] ?: '-';
        $bare = str_starts_with($asked, $descending) ? substr($asked, strlen($descending)) : $asked;
        in_array($bare, $list['sort']['values'], true) && $page['sort'] = $asked;
        $api = ['page[size]' => max(1, $perPage), 'page[number]' => max(1, (int) ($get['page'] ?? 1))];
        foreach ($fixed as $name => $value) {
            $api["filter[$name]"] = $value;
        }
        $sort = $page['sort'] ?? $list['sort']['default'];
        $sort === '' || $api['sort'] = $sort;
        isset($page['search']) && $api['search'] = $page['search'];
        foreach ($list['filters'] as $filter) {
            isset($page[$filter['name']]) && $api['filter['.$filter['name'].']'] = is_array($page[$filter['name']]) ? implode(',', $page[$filter['name']]) : $page[$filter['name']];
        }

        return ['api' => $api, 'page' => $page];
    }

    /**
     * The list with the values its dynamic filters offer: the ones the rows have now (the meta of the list: `available`, plain
     * values or {value, label} with a text to show), and the ones asked.
     *
     * @param array<string,mixed> $list
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $page the query of the page (Lists::query())
     * @return array<string,mixed>
     */
    public static function offering(array $list, array $meta, array $page): array
    {
        foreach ($list['filters'] as $i => $filter) {
            if (! empty($filter['dynamic']) && isset($meta['available'][$filter['name']])) {
                // The values the rows have: plain values, or {value, label} with a label to show as it is
                $texts = [];
                foreach ((array) $meta['available'][$filter['name']] as $available) {
                    $value = (string) (is_array($available) ? ($available['value'] ?? '') : $available);
                    $value === '' || $texts[$value] = is_array($available) && isset($available['label']) ? (string) $available['label'] : null;
                }
                foreach ((array) ($page[$filter['name']] ?? []) as $asked) {
                    $texts += [(string) $asked => null];
                }
                // The values the engine lists, with their labels; else the ones of the rows, with their text
                $list['filters'][$i]['values'] = $filter['values'] === []
                    ? array_map(fn ($value, $text) => ['value' => (string) $value, 'label' => ''] + ($text === null ? [] : ['text' => $text]), array_keys($texts), array_values($texts))
                    : array_values(array_filter($filter['values'], fn ($value) => array_key_exists($value['value'], $texts)));
            }
        }

        return $list;
    }

    /** Whether the value of a filter is one the engine takes: one of its values, or a free value of its format. */
    private static function allowed(array $filter, string $value): bool
    {
        if ($value === '') {
            return false;
        }
        // The values of a dynamic filter the engine does not list are the ones of the rows (a simulator...)
        if (! empty($filter['dynamic']) && $filter['values'] === []) {
            return mb_strlen($value) <= 200;
        }
        if (isset($filter['format'])) {
            return $filter['format'] === 'uuid' ? Bundled::library() && \OpenSim\Text\Uuid::isValid($value) : mb_strlen($value) <= 200;
        }

        return in_array($value, array_column($filter['values'], 'value'), true);
    }
}
