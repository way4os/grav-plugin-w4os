// A button that copies its text to the clipboard (data-w4os-copy), then says it is copied (data-w4os-copied) for a moment
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-w4os-copy]');
    if (!button || !navigator.clipboard) {
        return;
    }
    const label = button.textContent;
    navigator.clipboard.writeText(button.dataset.w4osCopy).then(() => {
        button.textContent = button.dataset.w4osCopied || label;
        setTimeout(() => { button.textContent = label; }, 2000);
    });
});
