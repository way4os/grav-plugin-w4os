<?php

namespace W4os\Grav;

/**
 * The engine's OpenAPI document, as the site reads it to show the lists of its models: what the list operation of a model
 * (`GET /grids/{grid}/<type>`) says in its parameters (search, sort, page, `filter[...]` and their values) and in its `x-list`
 * (the columns in their order with their display type and the catalog key of their label, the filters to show and the keys of their
 * values, the fields the search looks in, the actions of a row and when they apply). The site draws them its own way.
 */
final class OpenApi
{
    /** @param array<string,mixed> $document the document of the engine (`/openapi.json`) */
    public function __construct(private array $document) {}

    /**
     * The list of a type of the engine (avatars, regions...), null when the engine has no list of it.
     *
     * @return ?array{type: string, columns: list<array<string,mixed>>, sorts: list<string>, filters: list<array{name: string, key:
     *     string, values: list<array{value: string, key: string}>}>, search: ?list<string>, actions: list<array<string,mixed>>,
     *     pageSize: ?int}
     */
    public function listOf(string $type): ?array
    {
        $operation = $this->listOperation($type);
        if ($operation === null) {
            return null;
        }
        $parameters = [];
        foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
            $parameter = $this->resolve((array) $parameter);
            isset($parameter['name']) && $parameters[(string) $parameter['name']] = $parameter;
        }
        $x = (array) ($operation['x-list'] ?? []);
        $columns = [];
        foreach ((array) ($x['columns'] ?? []) as $column) {
            isset($column['name']) && $columns[] = ['key' => 'column_'.$column['name'], 'type' => 'text', 'sortable' => false] + (array) $column;
        }
        // The sortable columns: the values of `sort`, else the columns that say so
        $enum = (array) ($parameters['sort']['schema']['enum'] ?? []);
        $sorts = $enum !== []
            ? array_values(array_unique(array_map(fn ($sort) => ltrim((string) $sort, '-'), $enum)))
            : array_values(array_map(fn ($column) => (string) $column['name'], array_filter($columns, fn ($column) => (bool) $column['sortable'])));

        return [
            'type' => $type,
            'columns' => $columns,
            'sorts' => $sorts,
            'filters' => $this->filters($parameters, (array) ($x['filters'] ?? [])),
            'search' => isset($parameters['search']) ? array_values((array) ($x['search'] ?? [])) : null,
            'actions' => array_values(array_filter((array) ($x['actions'] ?? []), fn ($action) => isset($action['name']))),
            'pageSize' => isset($parameters['page[size]']['schema']['default']) ? (int) $parameters['page[size]']['schema']['default'] : null,
        ];
    }

    /**
     * The filters a list shows: the ones its x-list names, in its order (else every `filter[...]` with values), each with its values
     * from the enum of its parameter and the catalog keys of its label and of its values.
     *
     * @param array<string,array<string,mixed>> $parameters
     * @param array<int,array<string,mixed>> $shown
     * @return list<array{name: string, key: string, values: list<array{value: string, key: string}>}>
     */
    private function filters(array $parameters, array $shown): array
    {
        $all = [];
        foreach ($parameters as $name => $parameter) {
            $values = (array) ($parameter['schema']['enum'] ?? []);
            if (preg_match('/^filter\[(.+)\]$/', $name, $m) === 1 && $values !== []) {
                $all[$m[1]] = array_map('strval', array_values($values));
            }
        }
        $filters = [];
        foreach ($shown !== [] ? $shown : array_map(fn ($name) => ['name' => $name], array_keys($all)) as $filter) {
            $name = (string) ($filter['name'] ?? '');
            if (! isset($all[$name])) {
                continue;
            }
            $keys = (array) ($filter['values'] ?? []);
            $filters[] = [
                'name' => $name,
                'key' => (string) ($filter['key'] ?? 'filter_'.$name),
                'values' => array_map(fn (string $value) => ['value' => $value, 'key' => (string) ($keys[$value] ?? '')], $all[$name]),
            ];
        }

        return $filters;
    }

    /** @return ?array<string,mixed> the list operation of a type: `GET` on `/grids/{grid}/<type>` or `/<type>` */
    private function listOperation(string $type): ?array
    {
        foreach ((array) ($this->document['paths'] ?? []) as $path => $item) {
            if (preg_match('#^(/grids/\{grid\})?/'.preg_quote($type, '#').'$#', (string) $path) === 1 && isset($item['get'])) {
                return (array) $item['get'];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $object
     * @return array<string,mixed> a part of the document, its `$ref` followed (`#/components/...`)
     */
    private function resolve(array $object): array
    {
        $ref = (string) ($object['$ref'] ?? '');
        if (! str_starts_with($ref, '#/')) {
            return $object;
        }
        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = (array) ($node[$part] ?? []);
        }

        return $node;
    }
}
