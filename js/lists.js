// The lines of a list of values (the Form plugin's array field) numbered in their order once one is moved, as the Form plugin
// numbers them when one is added or removed
document.addEventListener('DOMContentLoaded', () => {
    window.jQuery?.('[data-grav-array-type=container]').each((index, container) => {
        window.jQuery(container).data('array-sort')?.option('onEnd', () => {
            container.querySelectorAll(':scope > [data-grav-array-type=row] [data-grav-array-type=value][name]:not([name=""])').forEach((input, line) => {
                input.name = input.name.replace(/\[\d+\]$/, `[${line}]`);
            });
        });
    });
});
