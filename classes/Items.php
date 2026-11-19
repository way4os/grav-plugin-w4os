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
     * whose condition the item does not meet marked `w4os-unmet`, for the script of the form to show them as it is changed. With
     * `shown` (a form of settings), what the options of the grid set too, in their place, unchangeable; a help of several points
     * said in full with `translate`, a path on the address it is under (`base`: the one, or by name, `site` and `helpers`).
     *
     * @param ?\Closure(string): string $translate the text of a key of the catalog
     * @param array<string,list<array{file: ?string, text: string}>> $snippets the lines of the files of the grid a field shows, by field
     * @param array<string,array{reason: string|array<string,mixed>, file: ?string, text: string}> $fixed the settings this site cannot write, by field: why (a notice of the engine),
     *     and the line to add by hand in that file (meta.fixed of the engine)
     * @return array<string,array<string,mixed>>
     */
    public static function fields(FormView $view, bool $shown = false, ?\Closure $translate = null, string|array $base = '', array $snippets = [], array $fixed = []): array
    {
        $changed = array_column($view->fields, 'name');
        $readOnly = $shown ? array_column($view->shown, 'name') : [];
        $groups = [];
        foreach ((array) ($view->form['fields'] ?? []) as $field) {
            if (isset($fixed[$field['name']]) && in_array($field['name'], $changed, true)) {
                // Not changed here: why, and the line to add by hand
                $line = $fixed[$field['name']];
                // Why, in the language of the page: a notice of the engine (its code and params), or its text
                $reason = Site::noticeText(is_array($line['reason'] ?? null) ? $line['reason'] : (string) ($line['reason'] ?? ''), $translate ?? fn (string $key) => $key);
                $input = array_diff_key(self::input($field, $translate, $base), ['validate' => 1, 'help' => 1]);
                // Its parts too (a database)
                foreach ($input['fields'] ?? [] as $name => $part) {
                    $input['fields'][$name] = ['disabled' => true] + array_diff_key($part, ['validate' => 1]);
                }
                $groups[$field['group']][$field['name']] = ['disabled' => true, 'help' => $reason] + $input
                    + (FormView::met($field, $view->item) ? [] : ['outerclasses' => 'w4os-unmet']);
                $groups[$field['group']][$field['name'].'_fixed'] = ['type' => 'display', 'label' => self::snippetLabel(['file' => (string) ($line['file'] ?? '')], $translate),
                    'content' => "```ini\n".rtrim((string) ($line['text'] ?? ''))."\n```", 'markdown' => true,
                    'outerclasses' => 'w4os-snippet w4os-snippet-for-'.$field['name'].(FormView::met($field, $view->item) ? '' : ' w4os-unmet')];
                continue;
            }
            if (in_array($field['name'], $changed, true)) {
                $input = self::input($field, $translate, $base);
                // A database whose default is kept now: its parts hidden
                if (! empty($view->values[$field['name']]['useDefault'])) {
                    foreach ($input['fields'] ?? [] as $name => $part) {
                        isset($part['outerclasses']) && $input['fields'][$name]['outerclasses'] .= ' w4os-unmet';
                    }
                }
                // A field the values of the form hide for now (its condition), shown by the script of the form as they change: not
                // required while hidden (the engine checks what it is sent)
                if (! FormView::met($field, $view->item)) {
                    unset($input['validate']['required']);
                    if (($input['validate'] ?? null) === []) {
                        unset($input['validate']);
                    }
                    $input['outerclasses'] = 'w4os-unmet';
                }
                $groups[$field['group']][$field['name']] = $input;
            } elseif (in_array($field['name'], $readOnly, true)) {
                $groups[$field['group']][$field['name']] = ['disabled' => true] + array_diff_key(self::input($field, $translate, $base), ['validate' => 1])
                    + (FormView::met($field, $view->item) ? [] : ['outerclasses' => 'w4os-unmet']);
            } else {
                continue;
            }
            // The lines of the files of the grid it takes, under it, as w4os-wp shows them; an example (no file) after the settings
            foreach (array_values((array) ($snippets[$field['name']] ?? [])) as $i => $snippet) {
                $example = ($snippet['file'] ?? null) === null;
                $show = ['type' => 'display', 'label' => self::snippetLabel($snippet, $translate),
                    'content' => "```".($example ? '' : 'ini')."\n".rtrim((string) ($snippet['text'] ?? ''))."\n```", 'markdown' => true,
                    'outerclasses' => 'w4os-snippet w4os-snippet-for-'.$field['name'].(FormView::met($field, $view->item) ? '' : ' w4os-unmet')];
                $name = $field['name'].'_snippets'.($i ? "_$i" : '');
                $example ? $examples[$field['group']][$name] = $show : $groups[$field['group']][$name] = $show;
            }
        }
        foreach ($examples ?? [] as $group => $shown) {
            $groups[$group] = ($groups[$group] ?? []) + $shown;
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
     * What lines of a file of the grid are, as the engine names them (`label`, a key of the catalog: Lines to add to %s, the file):
     * the lines to add to their file, else an example.
     *
     * @param array<string,mixed> $snippet
     */
    private static function snippetLabel(array $snippet, ?\Closure $translate): string
    {
        $translate ??= fn (string $key) => $key;
        $file = (string) ($snippet['file'] ?? '');
        $text = $translate('w4os.'.(string) ($snippet['label'] ?? ($file === '' ? 'snippet_example' : 'snippet_ini')));

        return str_contains($text, '%s') ? sprintf($text, $file) : $text;
    }

    /**
     * What a form changes without the settings this site cannot write (meta.fixed): their inputs are disabled, so never sent.
     *
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $fixed
     * @return array<string,mixed>
     */
    public static function unfixed(array $changes, array $fixed): array
    {
        return array_diff_key($changes, $fixed);
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
        // On the fields of the form, the ones shown and unchangeable included (the script reads them too)
        $names = [...array_column($view->fields, 'name'), ...array_column($view->shown, 'name')];
        $conditions = [];
        foreach ([...$view->fields, ...$view->shown] as $field) {
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
     * @param ?\Closure(string): string $translate the text of a key of the catalog (a help of several points, a note)
     * @param string $base the address of the site, before the path of a field `path`
     * @return array<string,mixed>
     */
    public static function input(array $field, ?\Closure $translate = null, string|array $base = ''): array
    {
        $help = self::help($field, $translate);
        // A note: its points as text, no input
        if ($field['type'] === 'note') {
            return ['type' => 'display', 'label' => 'w4os.'.$field['label'], 'content' => $help ?? '', 'markdown' => true];
        }
        // A database: its parts, each its input (data[<name>][<part>])
        if ($field['type'] === 'connection-string') {
            $parts = [];
            // Its default (a database the grid has without it) first: its parts hidden while it is checked
            isset($field['fallback']) && $parts[$field['name'].'.useDefault'] = ['type' => 'checkbox', 'label' => 'w4os.'.$field['fallback']];
            foreach ((array) ($field['fields'] ?? []) as $part) {
                $parts[$field['name'].'.'.$part['name']] = self::input($part + ['type' => 'string'], $translate, $base)
                    + (isset($field['fallback']) ? ['outerclasses' => 'w4os-fallback-of-'.$field['name']] : []);
            }

            return ['type' => 'fieldset', 'legend' => 'w4os.'.$field['label'], 'classes' => 'form-fieldset', 'fields' => $parts] + ($help === null ? [] : ['help' => $help]);
        }
        // A path on the address of the site, which is not changed here
        if ($field['type'] === 'path') {
            $under = is_array($base) ? (string) ($base[$field['base'] ?? 'site'] ?? '') : $base;

            return ['type' => 'w4os_path', 'base' => rtrim($under, '/'), 'label' => 'w4os.'.$field['label']] + ($help === null ? [] : ['help' => $help]);
        }
        // Several addresses the options of the grid set: one by line
        ! empty($field['multiple']) && ! empty($field['readOnly']) && $field['type'] = 'text';
        $input = match ($field['type']) {
            'text' => ['type' => 'textarea', 'classes' => 'w4os-autogrow', 'rows' => 3],
            'integer', 'number' => ['type' => 'number'],
            // A switch, as w4os-wp shows Provide
            'boolean' => ['type' => 'checkbox'] + (($field['widget'] ?? '') === 'switch' ? ['wrapper_classes' => 'form-check form-switch'] : []),
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
        // The avatar models to choose from, as pictures (the choice of a new avatar), none for the default outfit
        str_ends_with((string) ($field['source']['path'] ?? ''), '/avatar-models') && $input = ['type' => 'w4os_models', 'validate' => ['type' => 'radio']];
        // What a value must be: its length, its form (the names the viewers take)
        isset($field['maxLength']) && in_array($input['type'], ['text', 'email', 'password', 'url'], true) && $input['maxlength'] = (int) $field['maxLength'];

        // A field of several values (the avatars of a list): an array
        ! empty($field['multiple']) && $field['type'] === 'avatar' && $input += ['multiple' => true];
        $validate = array_filter(['required' => ! empty($field['required']) ?: null, 'max' => isset($field['maxLength']) ? (int) $field['maxLength'] : null,
            'type' => ! empty($field['multiple']) ? 'array' : null, 'pattern' => isset($field['pattern']) ? (string) $field['pattern'] : null], fn ($rule) => $rule !== null);
        $validate = ($input['validate'] ?? []) + $validate;

        // A placeholder is a text of the catalog, or an example as it is (an address, a file, a port)
        isset($field['placeholder']) && $input['placeholder'] = preg_match('/^[a-z][a-z0-9_]*$/', (string) $field['placeholder']) === 1 ? 'w4os.'.$field['placeholder'] : (string) $field['placeholder'];

        // The pictures of the models say their help themselves
        return $input + ['label' => 'w4os.'.$field['label']] + ($help !== null && $input['type'] !== 'w4os_models' ? ['help' => $help] : []) + ($validate === [] ? [] : ['validate' => $validate]);
    }

    /**
     * The help of a field: the key of its text, or its points said in full when it has several (`translate`; the key of the first
     * one without it); null for none.
     *
     * @param array<string,mixed> $field
     * @param ?\Closure(string): string $translate
     */
    private static function help(array $field, ?\Closure $translate): ?string
    {
        $keys = array_values(array_filter(array_map('strval', (array) ($field['description'] ?? [])), fn (string $key) => $key !== ''));
        if ($keys === []) {
            return null;
        }
        if (count($keys) === 1 && ! is_array($field['description'])) {
            return 'w4os.'.$keys[0];
        }

        return $translate === null ? 'w4os.'.$keys[0] : implode(' ', array_map(fn (string $key) => $translate('w4os.'.$key), $keys));
    }
}
