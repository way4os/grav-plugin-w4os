// A list of languages typed in one field (French, English): its suggestions go on after a comma, a semicolon, a slash or a space,
// each one the text typed so far followed by a language not in it yet.
document.querySelectorAll('input[data-w4os-languages]').forEach((input) => {
    const list = document.getElementById(input.getAttribute('list'));
    if (!list) {
        return;
    }
    const names = [...list.options].map((option) => option.value);
    const suggest = () => {
        // What is typed up to the last separator, and the languages already in it
        const done = (input.value.match(/^(.*[,;/\s])/u) || ['', ''])[1];
        const prefix = done.replace(/\s*$/u, ' ').replace(/^ $/u, '');
        const chosen = done.toLowerCase().split(/[,;/\s]+/u).filter(Boolean);
        list.replaceChildren(...names
            .filter((name) => !chosen.includes(name.toLowerCase()))
            .map((name) => new Option(prefix + name)));
    };
    input.addEventListener('input', suggest);
    input.addEventListener('focus', suggest);
});
