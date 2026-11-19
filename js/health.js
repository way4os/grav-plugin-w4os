// The health of the grid checked again line by line ([data-w4os-health-check], its link the whole report checked again without the
// script): each line ([data-check]) asked to the site a few at a time (?check=<id>), a spinner in it meanwhile, then drawn again as
// it is now, with its time; the banner then says the level of the lines and the time of the last one, or why a line could not be
// checked.
document.querySelectorAll('[data-w4os-health-check]').forEach((button) => {
    const banner = button.closest('.w4os-health-banner');
    const rows = () => [...document.querySelectorAll('tr[data-check]')].filter((row) => row.dataset.check);
    const address = (id) => {
        const url = new URL(window.location.href);
        url.search = `?check=${encodeURIComponent(id)}`;
        return url;
    };
    const check = (row) => {
        const when = row.querySelector('.w4os-health-when');
        when && (when.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>');
        return fetch(address(row.dataset.check), { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then((answer) => answer.text().then((text) => (answer.ok ? text : Promise.reject(text || answer.status))))
            .then((html) => {
                const body = document.createElement('tbody');
                body.innerHTML = html.trim();
                const line = body.querySelector('tr');
                line ? row.replaceWith(line) : Promise.reject('');
                return line;
            })
            .catch((reason) => {
                when && (when.textContent = (banner?.dataset.failed || '%s').replace('%s', String(reason).slice(0, 200)));
                row.classList.add('w4os-health-error');
                return null;
            });
    };
    button.addEventListener('click', (event) => {
        event.preventDefault();
        if (button.classList.contains('disabled')) {
            return;
        }
        button.classList.add('disabled');
        const queue = rows();
        let last = null;
        const next = () => {
            const row = queue.shift();
            return row ? check(row).then((line) => {
                line && (last = line);
                return next();
            }) : Promise.resolve();
        };
        // A few lines at a time
        Promise.all([next(), next(), next()]).then(() => {
            button.classList.remove('disabled');
            if (!banner) {
                return;
            }
            const failed = document.querySelector('tr[data-check].w4os-health-error');
            const alert = banner.querySelector('.alert');
            alert?.classList.toggle('alert-danger', !!failed);
            alert?.classList.toggle('alert-success', !failed);
            const level = banner.querySelector('.w4os-health-level');
            level && (level.textContent = failed ? banner.dataset.error : banner.dataset.ok);
            const time = last?.querySelector('.w4os-health-when [title]');
            const checked = banner.querySelector('.w4os-health-checked');
            if (time && checked) {
                checked.textContent = (banner.dataset.checked || '%s').replace('%s', time.textContent);
                checked.title = time.title;
            }
        });
    });
});
