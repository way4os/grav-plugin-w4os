// The avatars of a form picked by their name (select[data-w4os-avatar], Tom Select): the avatars whose name has what is typed, asked
// to the site (data-source: the avatars of the managers' list, as JSON), each with its picture (the engine's silhouette, faint,
// without one: none), never initials; the field sends the UUID.
document.querySelectorAll('select[data-w4os-avatar]').forEach((select) => {
    if (typeof TomSelect === 'undefined' || !select.dataset.source) {
        return;
    }
    const size = getComputedStyle(select).fontSize;
    const pictures = Object.fromEntries([...select.options].map((option) => [option.value, { picture: option.dataset.picture || '', none: 'none' in option.dataset }]));
    const show = (data, escape) => {
        const kept = pictures[data.value] || {};
        const picture = data.picture || kept.picture || '';
        const none = data.picture ? data.none === true : kept.none === true;
        return `<div class="w4os-who">${picture ? `<img class="w4os-thumb w4os-thumb-person${none ? ' w4os-picture-none' : ''}" src="${escape(picture)}" alt="" width="24" height="24"${none ? ' style="opacity: 0.5"' : ''}>` : ''}<span>${escape(data.text)}</span></div>`;
    };
    const field = new TomSelect(select, {
        valueField: 'value',
        labelField: 'text',
        searchField: ['text'],
        maxOptions: 20,
        closeAfterSelect: true,
        shouldLoad: (query) => query.trim().length >= 2,
        load: (query, done) => {
            fetch(`${select.dataset.source}&search=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                .then((answer) => answer.json())
                .then((avatars) => done(avatars))
                .catch(() => done());
        },
        render: { option: show, item: show },
    });
    field.wrapper.style.fontSize = size;
    field.dropdown.style.fontSize = size;
});
