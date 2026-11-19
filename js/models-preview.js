// The models a rule picks, updated as its form is changed, before it is saved ([data-w4os-models-preview]: the address of the
// preview, the form, the fields given as its filters): the fields shown (the ones the choice hides are not asked), the list given
// back by the site in place of the one shown.
document.querySelectorAll('[data-w4os-models-preview]').forEach((preview) => {
    const form = document.getElementById(preview.dataset.form);
    if (!form) {
        return;
    }
    let filters;
    try {
        filters = JSON.parse(preview.dataset.filters || '[]');
    } catch {
        return;
    }
    let asked = '';
    let timer;
    const query = () => {
        const params = new URLSearchParams();
        filters.forEach((name) => {
            const inputs = [...form.querySelectorAll(`[name="data[${name}]"], [name="data[${name}][]"]`)]
                .filter((input) => !input.closest('.w4os-unmet'));
            if (inputs.length === 0) {
                return;
            }
            const values = inputs.flatMap((input) => {
                if (input.type === 'radio' || input.type === 'checkbox') {
                    return input.checked ? [input.value] : [];
                }
                return input.multiple ? [...input.selectedOptions].map((option) => option.value) : [input.value];
            });
            params.set(`filter[${name}]`, values.join(','));
        });
        return params.toString();
    };
    const update = () => {
        const wanted = query();
        if (wanted === asked) {
            return;
        }
        asked = wanted;
        preview.setAttribute('aria-busy', 'true');
        const address = new URL(preview.dataset.w4osModelsPreview, window.location.href);
        address.search = `${address.search ? address.search + '&' : '?'}${wanted}`;
        fetch(address, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then((answer) => (answer.ok ? answer.text() : Promise.reject(answer.status)))
            .then((html) => {
                if (wanted === asked) {
                    preview.innerHTML = html;
                }
            })
            .catch(() => {})
            .finally(() => preview.removeAttribute('aria-busy'));
    };
    asked = query();
    const later = () => {
        clearTimeout(timer);
        timer = setTimeout(update, 300);
    };
    form.addEventListener('change', later);
    form.addEventListener('input', later);
});
