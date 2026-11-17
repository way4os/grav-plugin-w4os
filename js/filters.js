// The choices of the filters of a list as one kind of field (Tom Select, select[data-w4os-filter]): several values as tags, their
// label as placeholder; or one value, its empty option (the label of the filter, greyed) asking for all. The meaning of each value
// is its tip. The select asks the list again when it changes (its onchange), with or without this script.
document.querySelectorAll('select[data-w4os-filter]').forEach((select) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const tips = Object.fromEntries([...select.options].map((option) => [option.value, option.title]));
    // The text size the theme gives its fields, whatever the theme
    const size = getComputedStyle(select).fontSize;
    const tip = (data, escape) => (tips[data.value] ? ` title="${escape(tips[data.value])}"` : '');
    const field = new TomSelect(select, {
        plugins: select.multiple ? ['remove_button'] : [],
        placeholder: select.getAttribute('placeholder') || select.getAttribute('aria-label'),
        hidePlaceholder: select.multiple,
        allowEmptyOption: !select.multiple,
        // One value: picked, not typed
        ...(select.multiple ? {} : { controlInput: null }),
        closeAfterSelect: true,
        maxOptions: null,
        render: {
            option: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
            item: (data, escape) => `<div${data.value === '' ? ' class="w4os-filter-all"' : ''}${tip(data, escape)}>${escape(data.text)}</div>`,
        },
    });
    field.wrapper.style.fontSize = size;
    field.dropdown.style.fontSize = size;
});
