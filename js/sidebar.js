// The sidebar of the admin folded to its icons below Tabler's xl breakpoint (Tabler's folded-hover: unfolded on hover), as long as
// the manager has not chosen with Tabler's pin button (saved by Tabler as tabler-sidebar, applied by tabler-theme.js: it always
// wins). In the head, after Tabler's styles and its theme script, so that the page renders folded.
(function (sidebar) {
    if (typeof module === 'object' && module.exports) {
        module.exports = sidebar;
        return;
    }
    const root = document.documentElement;
    const xl = parseFloat(getComputedStyle(root).getPropertyValue('--tblr-breakpoint-xl')) || 1200;
    const saved = () => {
        try {
            return localStorage.getItem('tabler-sidebar');
        } catch {
            return null;
        }
    };
    sidebar(root, saved, window.matchMedia(`(max-width: ${xl - 0.02}px)`));
})(function (root, saved, narrow) {
    const apply = () => {
        if (saved() !== null) {
            return;
        }
        narrow.matches ? root.setAttribute('data-bs-sidebar', 'folded-hover') : root.removeAttribute('data-bs-sidebar');
    };
    apply();
    narrow.addEventListener('change', apply);
});
