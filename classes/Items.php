<?php

namespace W4os\Grav;

use W4OS\Views\FormView;

/**
 * The form of an item of the engine for Grav's Form plugin: its inputs from the form of the viewer (w4os-views FormView, which says
 * who the viewer is to the item, the fields it sees and changes, and what a posted form changes), the choices the engine gives the
 * fields of a form of the site, and a form without some of its fields.
 */
final class Items
{
    /**
     * The fields of the form of an item for Grav's Form plugin, from its form for the viewer (w4os-views FormView): the fields it
     * changes, each as an input of its type, their groups as tabs (one group: its fields alone), the id of the item hidden; the ones
     * whose condition the item does not meet marked `w4os-unmet`, for the script of the form to show them as it is changed.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fields(FormView $view): array
    {
        $groups = [];
        foreach ($view->fields as $field) {
            $groups[$field['group']][$field['name']] = self::input($field)
                // A field the values of the form hide for now (its condition), shown by the script of the form as they change
                + (FormView::met($field, $view->item) ? [] : ['outerclasses' => 'w4os-unmet']);
        }
        $fields = ['id' => ['type' => 'hidden']];
        $shown = array_values(array_filter((array) ($view->form['groups'] ?? []), fn ($group) => isset($groups[$group['name']])));
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
     * The fields of a form of the site (Grav's, nested in tabs and fieldsets) with the choices the engine gives for the item's
     * fields of the same name (their options), whatever the form says of them.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $form the item's description (Contract::itemOf())
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
     * The fields of a form of the site (Grav's, nested in tabs and fieldsets) without some of them, by name.
     *
     * @param array<string,mixed> $fields
     * @param list<string> $names
     * @return array<string,mixed>
     */
    public static function without(array $fields, array $names): array
    {
        foreach ($fields as $name => $field) {
            if (in_array((string) $name, $names, true)) {
                unset($fields[$name]);
            } elseif (is_array($field) && isset($field['fields'])) {
                $fields[$name]['fields'] = self::without((array) $field['fields'], $names);
                // A tab or a section left without a field goes with them
                if ($fields[$name]['fields'] === [] && $field['fields'] !== [] && in_array($field['type'] ?? '', ['tab', 'section', 'fieldset'], true)) {
                    unset($fields[$name]);
                }
            }
        }

        return $fields;
    }

    /**
     * The fields of an item the grid does not allow, as the contract says: the ones whose `setting` is off in the settings of the
     * item's grid (its `meta.settings`), the web address of a profile with AllowUserProfileWebURLs false.
     *
     * @param array<string,mixed> $described the form of the model (x-form)
     * @param array<string,mixed> $settings the settings of the grid of the item
     * @return list<string>
     */
    public static function unallowed(array $described, array $settings): array
    {
        $names = [];
        foreach ((array) ($described['fields'] ?? []) as $field) {
            isset($field['setting']) && ($settings[$field['setting']] ?? true) === false && $names[] = (string) $field['name'];
        }

        return $names;
    }

    /**
     * The conditions of the fields of a form that the fields of the form meet (FormView::live()), by field: what the script of the
     * form (js/conditions.js) shows or hides them by as the form is changed.
     *
     * @return array<string,array<string,list<mixed>>>
     */
    public static function conditions(FormView $view): array
    {
        $names = array_column($view->fields, 'name');
        $conditions = [];
        foreach ($view->fields as $field) {
            FormView::live($field, $names) && $conditions[$field['name']] = array_map(fn ($values) => array_values((array) $values), (array) $field['condition']);
        }

        return $conditions;
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
     * An input of the Form plugin for a field of the engine, by its type; one it needs (`required`) or whose length it limits
     * (`maxLength`) is checked so.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public static function input(array $field): array
    {
        $input = match ($field['type']) {
            'text' => ['type' => 'textarea', 'classes' => 'w4os-autogrow', 'rows' => 3],
            'integer', 'number' => ['type' => 'number'],
            'boolean' => ['type' => 'checkbox'],
            // The choices of a short list side by side (its widget radio), else a list
            'select', 'status', 'role' => ($field['widget'] ?? '') === 'radio'
                ? ['type' => 'radio', 'options' => self::options($field), 'wrapper_classes' => 'form-check form-check-inline']
                : ['type' => 'select', 'options' => self::options($field)],
            'checkboxes' => ['type' => 'checkboxes', 'options' => self::options($field)],
            'secret' => ['type' => 'password', 'autocomplete' => 'new-password'],
            'url' => ['type' => 'url'],
            'email' => ['type' => 'email'],
            'avatar' => ['type' => 'w4os_avatar'],
            default => ['type' => 'text'],
        };

        // A field of several values (the avatars of a list): an array
        ! empty($field['multiple']) && $field['type'] === 'avatar' && $input += ['multiple' => true];
        $validate = array_filter(['required' => ! empty($field['required']) ?: null, 'max' => isset($field['maxLength']) ? (int) $field['maxLength'] : null,
            'type' => ! empty($field['multiple']) ? 'array' : null], fn ($rule) => $rule !== null);

        isset($field['placeholder']) && $input['placeholder'] = 'w4os.'.$field['placeholder'];

        return $input + ['label' => 'w4os.'.$field['label']] + (isset($field['description']) ? ['help' => 'w4os.'.$field['description']] : []) + ($validate === [] ? [] : ['validate' => $validate]);
    }
}
