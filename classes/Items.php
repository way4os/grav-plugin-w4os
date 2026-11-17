<?php

namespace W4os\Grav;

/**
 * The page of an item of the engine (a region...), from its description (OpenApi::itemOf()): who the visitor is to it (its roles),
 * the fields it sees and the ones it changes (their condition and access), the fields of its form for Grav's Form plugin, and what
 * a posted form changes.
 */
final class Items
{
    /**
     * The roles of the visitor for an item: manager (of the grid), self (the item is its avatar), owner (the item is its).
     *
     * @param array<string,mixed> $item
     * @return list<string>
     */
    public static function roles(array $item, ?string $self, bool $manager): array
    {
        return array_values(array_filter([
            $manager ? 'manager' : null,
            $self !== null && Lists::id($item) === $self ? 'self' : null,
            $self !== null && ($item['owner'] ?? null) === $self ? 'owner' : null,
        ]));
    }

    /**
     * Whether a field shows for an item: its condition met (each attribute has one of the values), and seen by one of the roles
     * (`access.view`; whoever sees the item without it).
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $item
     * @param list<string> $roles
     */
    public static function visible(array $field, array $item, array $roles): bool
    {
        foreach ((array) ($field['condition'] ?? []) as $attribute => $values) {
            $value = $item[$attribute] ?? null;
            $met = array_filter((array) $values, fn ($expected) => is_bool($expected) ? $value === $expected : $value !== null && (string) $value === (string) $expected);
            if ($met === []) {
                return false;
            }
        }

        return ! isset($field['access']['view']) || array_intersect((array) $field['access']['view'], $roles) !== [];
    }

    /**
     * Whether a field is changed by one of the roles: not read only, and `access.edit` (the managers without it).
     *
     * @param array<string,mixed> $field
     * @param list<string> $roles
     */
    public static function editable(array $field, array $roles): bool
    {
        return empty($field['readOnly']) && array_intersect((array) ($field['access']['edit'] ?? ['manager']), $roles) !== [];
    }

    /**
     * The groups of an item as its page shows them: each with the fields the visitor sees, in their order; none without any.
     *
     * @param array<string,mixed> $form the item's description (OpenApi::itemOf())
     * @param array<string,mixed> $item
     * @param list<string> $roles
     * @return list<array{name: string, label: string, fields: list<array<string,mixed>>}>
     */
    public static function groups(array $form, array $item, array $roles): array
    {
        $groups = [];
        foreach ($form['groups'] as $group) {
            $fields = array_values(array_filter($form['fields'], fn ($field) => $field['group'] === $group['name'] && self::visible($field, $item, $roles)));
            $fields === [] || $groups[] = $group + ['fields' => $fields];
        }

        return $groups;
    }

    /**
     * Whether the visitor changes something of an item: a field it sees that it may change.
     *
     * @param array<string,mixed> $form
     * @param array<string,mixed> $item
     * @param list<string> $roles
     */
    public static function changeable(array $form, array $item, array $roles): bool
    {
        return array_filter($form['fields'], fn ($field) => self::visible($field, $item, $roles) && self::editable($field, $roles)) !== [];
    }

    /**
     * The fields of the form of an item for Grav's Form plugin: the ones the visitor changes, each as an input of its type, its
     * groups as tabs (one group: its fields alone), the id of the item hidden.
     *
     * @param array<string,mixed> $form the item's description (OpenApi::itemOf())
     * @param array<string,mixed> $item
     * @param list<string> $roles
     * @return array<string,array<string,mixed>>
     */
    public static function fields(array $form, array $item, array $roles): array
    {
        $groups = [];
        foreach ($form['fields'] as $field) {
            if (self::visible($field, $item, $roles) && self::editable($field, $roles)) {
                $groups[$field['group']][$field['name']] = self::input($field);
            }
        }
        $fields = ['id' => ['type' => 'hidden']];
        $shown = array_values(array_filter($form['groups'], fn ($group) => isset($groups[$group['name']])));
        if (count($shown) > 1) {
            $tabs = [];
            foreach ($shown as $group) {
                $tabs[$group['name']] = ['type' => 'tab', 'title' => 'w4os.'.$group['label'], 'fields' => $groups[$group['name']]];
            }

            return $fields + ['groups' => ['type' => 'tabs', 'fields' => $tabs]];
        }

        return $fields + ($groups[$shown[0]['name'] ?? ''] ?? []);
    }

    /**
     * What a posted form changes: the fields the visitor changes whose value is not the item's (a password only when one is typed,
     * a box left unchecked when the engine does not know the value).
     *
     * @param array<string,mixed> $form
     * @param array<string,mixed> $item
     * @param array<string,mixed> $posted the data posted (`data[...]`)
     * @param list<string> $roles
     * @return array<string,mixed>
     */
    public static function changes(array $form, array $item, array $posted, array $roles): array
    {
        $changes = [];
        foreach ($form['fields'] as $field) {
            if (! self::visible($field, $item, $roles) || ! self::editable($field, $roles)) {
                continue;
            }
            $name = $field['name'];
            $before = $item[$name] ?? null;
            $value = match ($field['type']) {
                'boolean' => (bool) ($posted[$name] ?? false),
                'checkboxes' => Forms::checked($posted[$name] ?? []),
                'integer' => ($posted[$name] ?? '') === '' ? null : (int) $posted[$name],
                default => trim((string) ($posted[$name] ?? '')),
            };
            $same = match ($field['type']) {
                'boolean' => $before === null ? $value === false : $value === (bool) $before,
                'checkboxes' => $value === Forms::checked($before ?? []),
                'secret' => $value === '',
                default => (string) $value === (string) ($before ?? ''),
            };
            $same || $changes[$name] = $value;
        }

        return $changes;
    }

    /**
     * The values of the fields of the form the visitor changes, from the item.
     *
     * @param array<string,mixed> $form
     * @param array<string,mixed> $item
     * @param list<string> $roles
     * @return array<string,mixed>
     */
    public static function values(array $form, array $item, array $roles): array
    {
        $values = ['id' => Lists::id($item)];
        foreach ($form['fields'] as $field) {
            if (self::editable($field, $roles) && ($field['type'] ?? '') !== 'secret' && empty($field['writeOnly'])) {
                $value = $item[$field['name']] ?? null;
                $values[$field['name']] = match ($field['type']) {
                    'boolean' => (bool) $value,
                    'checkboxes' => Forms::checked($value ?? []),
                    default => $value === null ? '' : $value,
                };
            }
        }

        return $values;
    }

    /**
     * The fields of a form of the site (Grav's, nested in tabs and fieldsets) with the choices the engine gives for the item's
     * fields of the same name (their options), whatever the form says of them.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $form the item's description (OpenApi::itemOf())
     * @return array<string,mixed>
     */
    public static function withOptions(array $fields, array $form): array
    {
        $engine = array_column(array_filter($form['fields'], fn ($field) => isset($field['options'])), null, 'name');
        foreach ($fields as $name => $field) {
            if (! is_array($field)) {
                continue;
            }
            isset($engine[$name]) && $fields[$name]['options'] = self::options($engine[$name]);
            isset($field['fields']) && $fields[$name]['fields'] = self::withOptions((array) $field['fields'], $form);
        }

        return $fields;
    }

    /**
     * The options of a field (value => catalog key) for Grav (value => `w4os.` key).
     *
     * @param array<string,mixed> $field
     * @return array<string,string>
     */
    public static function options(array $field): array
    {
        return array_map(fn ($key) => 'w4os.'.$key, (array) ($field['options'] ?? []));
    }

    /**
     * An input of the Form plugin for a field of the engine, by its type.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function input(array $field): array
    {
        $input = match ($field['type']) {
            'text' => ['type' => 'textarea', 'classes' => 'w4os-autogrow', 'rows' => 3],
            'integer', 'number' => ['type' => 'number'],
            'boolean' => ['type' => 'checkbox'],
            'select', 'status', 'role' => ['type' => 'select', 'options' => self::options($field)],
            'checkboxes' => ['type' => 'checkboxes', 'options' => self::options($field)],
            'secret' => ['type' => 'password', 'autocomplete' => 'new-password'],
            'url' => ['type' => 'url'],
            'email' => ['type' => 'email'],
            'avatar' => ['type' => 'w4os_avatar'],
            default => ['type' => 'text'],
        };

        return $input + ['label' => 'w4os.'.$field['label']] + (isset($field['description']) ? ['help' => 'w4os.'.$field['description']] : []);
    }
}
