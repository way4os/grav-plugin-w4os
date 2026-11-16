// The buttons between the profile page and its edit page keep the tab open: its name in the address (#picks) goes with them
document.querySelector('#w4os-view-profile')?.closest('a')?.setAttribute('data-w4os-keep-tab', '');
document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-w4os-keep-tab]');
    link && location.hash && (link.href = `${link.href.split('#')[0]}${location.hash}`);
});
