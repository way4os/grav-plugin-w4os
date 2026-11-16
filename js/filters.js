// The filters of a list with Tom Select (select[data-w4os-filter]): several values as tags, or one value searched among the ones
// of the rows; their label as placeholder (one value: its empty option, which asks for all), the meaning of each value as its tip.
// The list is asked again at each change, as with its other filters.
document.querySelectorAll('select[data-w4os-filter]').forEach((select) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const tips = Object.fromEntries([...select.options].map((option) => [option.value, option.title]));
    const tip = (data, escape) => (tips[data.value] ? ` title="${escape(tips[data.value])}"` : '');
    new TomSelect(select, {
        plugins: select.multiple ? ['remove_button'] : [],
        placeholder: select.getAttribute('placeholder') || select.getAttribute('aria-label'),
        hidePlaceholder: select.multiple,
        allowEmptyOption: !select.multiple,
        closeAfterSelect: true,
        maxOptions: null,
        render: {
            option: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
            item: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
        },
        onChange: () => select.form?.requestSubmit(),
    });
});
