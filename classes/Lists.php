<?php

namespace W4os\Grav;

/**
 * A list of the engine on a page of the site, from its description (OpenApi::listOf()): the query of the engine and the query the
 * page keeps in its links, built from the address (search, filters, sort, page) and checked against what the list allows, and the
 * actions of a row.
 */
final class Lists
{
    /**
     * The query of the engine and the one of the page.
     *
     * @param array<string,mixed> $list the list (OpenApi::listOf())
     * @param array<string,mixed> $get the query of the address
     * @param array<string,scalar> $fixed what the page always asks the engine (`filter[technical] => no`)
     * @param string $sort the order without one in the address (`-created`)
     * @return array{api: array<string,scalar>, page: array<string,string>}
     */
    public static function query(array $list, array $get, int $perPage, array $fixed = [], string $sort = ''): array
    {
        $page = [];
        $search = trim((string) ($get['search'] ?? ''));
        $list['search'] !== null && $search !== '' && $page['search'] = $search;
        foreach ($list['filters'] as $filter) {
            $value = (string) ($get[$filter['name']] ?? '');
            in_array($value, array_column($filter['values'], 'value'), true) && $page[$filter['name']] = $value;
        }
        $asked = (string) ($get['sort'] ?? '');
        in_array(ltrim($asked, '-'), $list['sorts'], true) && $page['sort'] = $asked;
        $api = ['page[size]' => max(1, $perPage), 'page[number]' => max(1, (int) ($get['page'] ?? 1))] + $fixed;
        ($page['sort'] ?? $sort) === '' || $api['sort'] = $page['sort'] ?? $sort;
        isset($page['search']) && $api['search'] = $page['search'];
        foreach ($list['filters'] as $filter) {
            isset($page[$filter['name']]) && $api['filter['.$filter['name'].']'] = $page[$filter['name']];
        }

        return ['api' => $api, 'page' => $page];
    }

    /**
     * The actions of a row: the ones whose condition (`when`) its attributes meet. A condition names attributes, each with the
     * values it takes (a list), or true (it has a value) or false (it has none).
     *
     * @param array<string,mixed> $list
     * @param array<string,mixed> $item
     * @return list<array<string,mixed>>
     */
    public static function actions(array $list, array $item): array
    {
        return array_values(array_filter($list['actions'], fn (array $action) => self::applies((array) ($action['when'] ?? []), $item)));
    }

    /**
     * @param array<string,mixed> $when
     * @param array<string,mixed> $item
     */
    private static function applies(array $when, array $item): bool
    {
        foreach ($when as $attribute => $expected) {
            $value = $item[$attribute] ?? null;
            $set = $value !== null && $value !== '' && $value !== [];
            $met = is_bool($expected) ? $set === $expected : in_array($value, (array) $expected, false);
            if (! $met) {
                return false;
            }
        }

        return true;
    }
}
