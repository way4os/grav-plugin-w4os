// The times of the admin in the time and the language of the browser (Intl): each <time data-w4os-time> the server wrote in its own
// zone (Site::timeTag), told again in the same style; a recent one it said relatively keeps its words, its tip told again with the
// zone of the browser. W4OSDates.localize(element) for a part of the page drawn again.
(function (localize) {
    if (typeof module === 'object' && module.exports) {
        module.exports = localize;
        return;
    }
    const language = document.documentElement.lang || undefined;
    window.W4OSDates = { localize: (root = document) => localize(root, language) };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => window.W4OSDates.localize()) : window.W4OSDates.localize();
})(function (root, language, zone) {
    const STYLES = {
        date: { dateStyle: 'medium' },
        list: { dateStyle: 'short', timeStyle: 'short' },
        long: { dateStyle: 'medium', timeStyle: 'short' },
        full: { dateStyle: 'long', timeStyle: 'short' },
        month: { year: 'numeric', month: 'long' },
    };
    // The tip: the long date, its time and its zone
    const TIP = { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' };
    const say = (date, options) => {
        try {
            return new Intl.DateTimeFormat(language, { ...options, timeZone: zone }).format(date);
        } catch (error) {
            return new Intl.DateTimeFormat(undefined, options).format(date);
        }
    };
    root.querySelectorAll('time[data-w4os-time][datetime]').forEach((time) => {
        const date = new Date(time.getAttribute('datetime'));
        if (Number.isNaN(date.getTime())) {
            return;
        }
        time.hasAttribute('data-w4os-relative') || (time.textContent = say(date, STYLES[time.dataset.w4osTime] || STYLES.list));
        time.title = say(date, TIP);
    });
});
