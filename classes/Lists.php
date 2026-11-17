<?php

namespace W4os\Grav;

/**
 * A list of the engine on a page of the site, from its description (OpenApi::listOf()): the filters the page shows, the query of
 * the engine and the query the page keeps in its links, built from the address (search, filters, sort, page) and checked against
 * what the list allows, the actions of a row and the request of the engine that does one.
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
     * @param array<string,mixed> $list the list (OpenApi::listOf()), with the filters the page shows
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

    /**
     * The actions of a row: the ones whose conditions it meets. `when`: each attribute has one of the values; `requires`: each
     * attribute has a value; `notSelf`: the row is not the avatar of whoever acts.
     *
     * @param array<string,mixed> $list
     * @param array<string,mixed> $item
     * @param ?string $self the UUID of the avatar of whoever acts
     * @return list<array<string,mixed>>
     */
    public static function actions(array $list, array $item, ?string $self = null): array
    {
        return array_values(array_filter($list['actions'], fn (array $action) => self::applies($action, $item, $self)));
    }

    /**
     * The request of the engine that does an action on a row: its method, its path under the grid, its document.
     *
     * @param array<string,mixed> $action an action of the list
     * @param array<string,mixed> $item the row, its attributes
     * @param array<string,mixed> $more attributes the site adds (the link of its page for a token)
     * @return array{string, string, array<string,mixed>}
     */
    public static function request(array $action, array $item, array $more = []): array
    {
        $id = self::id($item);
        $path = (string) preg_replace('#^/grids/\{grid\}#', '', (string) $action['path']);
        // The type of the document: the last name of the path (`avatars` in /avatars/{id}, `password-resets`)
        $names = array_values(array_filter(explode('/', $path), fn ($part) => $part !== '' && ! str_starts_with($part, '{')));
        $type = (string) ($names[count($names) - 1] ?? '');
        $attributes = array_map(fn ($value) => self::filled($value, $item), (array) ($action['body'] ?? [])) + $more;
        $data = ['type' => $type] + (str_contains($path, '{id}') ? ['id' => $id] : []) + ['attributes' => $attributes];

        return [strtoupper((string) $action['method']), str_replace('{id}', rawurlencode($id), $path), $data];
    }

    /** The id of a row: its UUID, as the engine gives it in the attributes. */
    public static function id(array $item): string
    {
        return (string) ($item['uuid'] ?? $item['id'] ?? '');
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

    /** @param array<string,mixed> $action */
    private static function applies(array $action, array $item, ?string $self): bool
    {
        if (($action['notSelf'] ?? false) && $self !== null && self::id($item) === $self) {
            return false;
        }
        foreach ((array) ($action['requires'] ?? []) as $attribute) {
            $value = $item[$attribute] ?? null;
            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }
        foreach ((array) ($action['when'] ?? []) as $attribute => $values) {
            $value = $item[$attribute] ?? null;
            $met = array_filter((array) $values, fn ($expected) => is_bool($expected) ? $value === $expected : $value !== null && (string) $value === (string) $expected);
            if ($met === []) {
                return false;
            }
        }

        return true;
    }

    /** A value of the body of an action: `{attribute}` replaced by the row's (the value itself when it is the whole string). */
    private static function filled(mixed $value, array $item): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match('/^\{(\w+)\}$/', $value, $m) === 1) {
            return $item[$m[1]] ?? null;
        }

        return (string) preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($item[$m[1]] ?? ''), $value);
    }
}
