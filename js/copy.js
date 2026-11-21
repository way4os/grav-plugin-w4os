// A button that copies its text to the clipboard (data-w4os-copy), then says it is copied (data-w4os-copied, its label and its
// tooltip, .is-copied for its icon) for a moment. The buttons stay hidden where the browser gives no clipboard (a page served
// without https).
if (navigator.clipboard) {
    document.querySelectorAll('[data-w4os-copy]').forEach((button) => { button.hidden = false; });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-w4os-copy]');
        if (!button) {
            return;
        }
        const label = button.getAttribute('aria-label');
        navigator.clipboard.writeText(button.dataset.w4osCopy).then(() => {
            const copied = button.dataset.w4osCopied || label;
            button.classList.add('is-copied');
            button.setAttribute('aria-label', copied);
            button.title = copied;
            setTimeout(() => {
                button.classList.remove('is-copied');
                button.setAttribute('aria-label', label);
                button.title = label;
            }, 2000);
        });
    });
}
