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
        // A tag added, what was typed for it is gone
        onItemAdd() {
            this.setTextboxValue('');
            this.refreshOptions(false);
        },
    });
    // A comma or a space closes the language typed (keyboard, paste or phone): the one of the list when it is one (whatever
    // its capitals), else as typed; what follows the last one is still being typed
    const close = (typed) => {
        const known = names.find((name) => name.toLowerCase() === typed.toLowerCase());
        known ? tags.addItem(known) : tags.createItem(typed);
    };
    tags.control_input.addEventListener('input', () => {
        const parts = tags.inputValue().split(/[,\s]+/u);
        if (parts.length < 2) {
            return;
        }
        const rest = parts.pop();
        parts.filter(Boolean).forEach(close);
        tags.setTextboxValue(rest);
        tags.refreshOptions(rest !== '');
    });
});
