// A button that copies its text to the clipboard (data-w4os-copy), then says it is copied (data-w4os-copied) for a moment.
// The buttons stay hidden where the browser gives no clipboard (a page served without https).
if (navigator.clipboard) {
    document.querySelectorAll('[data-w4os-copy]').forEach((button) => { button.hidden = false; });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-w4os-copy]');
        if (!button) {
            return;
        }
        const label = button.textContent;
        navigator.clipboard.writeText(button.dataset.w4osCopy).then(() => {
            button.textContent = button.dataset.w4osCopied || label;
            setTimeout(() => { button.textContent = label; }, 2000);
        });
    });
}
