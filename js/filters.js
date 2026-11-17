// The choices of the filters of a list as one kind of field (Tom Select, select[data-w4os-filter]): several values as tags, or one
// value after the label of the filter (data-chosen: "Label: %s"); the label greyed while nothing is chosen, a × to remove a value
// (data-clear: its text), the meaning of each value as its tip. The select asks the list again when it changes (its onchange), with or without this script; without it, the
// empty option of one value asks for any.
document.querySelectorAll('select[data-w4os-filter]').forEach((select) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const label = select.getAttribute('placeholder') || select.getAttribute('aria-label') || '';
    const clear = select.dataset.clear || '';
    const chosen = select.dataset.chosen || '%s';
    const tips = Object.fromEntries([...select.options].map((option) => [option.value, option.title]));
    // The text size the theme gives its fields, whatever the theme
    const size = getComputedStyle(select).fontSize;
    const tip = (data, escape) => (tips[data.value] ? ` title="${escape(tips[data.value])}"` : '');
    const remove = (escape) => `<span class="remove w4os-clear" role="button" tabindex="-1" title="${escape(clear)}" aria-label="${escape(clear)}">×</span>`;
    const field = new TomSelect(select, {
        plugins: select.multiple ? { remove_button: { title: clear } } : [],
        placeholder: label,
        hidePlaceholder: select.multiple,
        // One value: picked, not typed; the empty value stays, shown as the label of the filter
        ...(select.multiple ? {} : { controlInput: null, allowEmptyOption: true }),
        closeAfterSelect: true,
        maxOptions: null,
        render: {
            option: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
            item: (data, escape) => (data.value === ''
                ? `<div class="w4os-filter-all">${escape(label)}</div>`
                : `<div${tip(data, escape)}>${select.multiple ? escape(data.text) : escape(chosen).replace('%s', escape(data.text)) + remove(escape)}</div>`),
        },
    });
    // The × of one value removes it, without opening the list
    field.wrapper.addEventListener('mousedown', (event) => {
        if (event.target.closest('.w4os-clear')) {
            event.preventDefault();
            event.stopPropagation();
            field.setValue('');
        }
    }, true);
    field.wrapper.style.fontSize = size;
    field.dropdown.style.fontSize = size;
});
