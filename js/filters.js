// The filters of a list that take several values (select[multiple][data-w4os-filter]) as tags (Tom Select): their label as
// placeholder, the meaning of each value as its tip; the list is asked again at each change, as with its other filters.
document.querySelectorAll('select[multiple][data-w4os-filter]').forEach((select) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const tips = Object.fromEntries([...select.options].map((option) => [option.value, option.title]));
    const tip = (data, escape) => (tips[data.value] ? ` title="${escape(tips[data.value])}"` : '');
    new TomSelect(select, {
        plugins: ['remove_button'],
        placeholder: select.getAttribute('placeholder'),
        hidePlaceholder: true,
        closeAfterSelect: true,
        maxOptions: null,
        render: {
            option: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
            item: (data, escape) => `<div${tip(data, escape)}>${escape(data.text)}</div>`,
        },
        onChange: () => select.form?.requestSubmit(),
    });
});
