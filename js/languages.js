// The languages of a profile as tags (Tom Select): each language picked from the list, or typed and closed by a comma or a
// space, becomes a tag; the suggestions are the languages that match what is typed. The field keeps the text of the viewer.
document.querySelectorAll('input[data-w4os-languages]').forEach((input) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const list = document.getElementById(input.getAttribute('list'));
    const names = list ? [...list.options].map((option) => option.value) : [];
    input.removeAttribute('list');
    input.value = input.value.split(/[,;]/u).map((name) => name.trim()).filter(Boolean).join(',');
    const tags = new TomSelect(input, {
        delimiter: ',',
        create: true,
        createOnBlur: true,
        persist: false,
        maxOptions: null,
        options: names.map((name) => ({ value: name, text: name })),
        plugins: ['remove_button'],
    });
    tags.control_input.addEventListener('keydown', (event) => {
        if (event.key === ' ' && tags.inputValue().trim() !== '') {
            event.preventDefault();
            tags.createItem();
        }
    });
});
