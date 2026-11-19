// The fields of a form shown by the values of its other fields (form[data-w4os-conditions]: by field, the values each field it
// depends on must have, one of them), shown or hidden as the form is changed; without the script, all are shown and the site keeps
// the ones the values sent meet.
document.documentElement.classList.add('w4os-js');
document.querySelectorAll('form[data-w4os-conditions]').forEach((form) => {
    let conditions;
    try {
        conditions = JSON.parse(form.dataset.w4osConditions || '{}');
    } catch {
        return;
    }
    const inputs = (name) => form.querySelectorAll(`[name="data[${name}]"], [name="data[${name}][]"]`);
    const values = (name) => [...inputs(name)].flatMap((input) => {
        // A box alone is a yes or a no (a condition on it is true or false); a box of a list, its value
        if (input.type === 'checkbox' && !input.name.endsWith('[]')) {
            return [String(input.checked)];
        }
        if (input.type === 'radio' || input.type === 'checkbox') {
            return input.checked ? [input.value] : [];
        }
        return input.multiple ? [...input.selectedOptions].map((option) => option.value) : [input.value];
    });
    const apply = () => {
        Object.entries(conditions).forEach(([name, condition]) => {
            const met = Object.entries(condition).every(([on, expected]) => values(on).some((value) => expected.map(String).includes(value)));
            inputs(name).forEach((input) => input.closest('.form-field')?.classList.toggle('w4os-unmet', !met));
            // A database: its fieldset, whole
            form.querySelectorAll(`[id="w4os-field-${name}"]`).forEach((fieldset) => fieldset.classList.toggle('w4os-unmet', !met));
            // The lines of the files of the grid a field takes, shown with it
            form.querySelectorAll(`.w4os-snippet-for-${name}`).forEach((snippet) => snippet.classList.toggle('w4os-unmet', !met));
        });
    };
    // The parts of a database hidden while its default is checked
    const defaults = () => form.querySelectorAll('input[type="checkbox"][name$="[useDefault]"]').forEach((box) => {
        const name = box.name.slice('data['.length, -'][useDefault]'.length);
        form.querySelectorAll(`.w4os-fallback-of-${name}`).forEach((part) => part.classList.toggle('w4os-unmet', box.checked));
    });
    apply();
    defaults();
    form.addEventListener('change', defaults);
    form.addEventListener('change', apply);
    form.addEventListener('input', apply);
});
