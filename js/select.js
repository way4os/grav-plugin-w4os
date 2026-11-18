// The choices of the forms of the admin as its other choices (Tom Select, select[data-w4os-select]): one value, picked, not typed,
// in the text size the theme gives its fields
document.querySelectorAll('select[data-w4os-select]').forEach((select) => {
    if (typeof TomSelect === 'undefined') {
        return;
    }
    const size = getComputedStyle(select).fontSize;
    const field = new TomSelect(select, { controlInput: null, maxOptions: null });
    field.wrapper.style.fontSize = size;
});
