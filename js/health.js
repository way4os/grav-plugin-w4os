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
    // The lines under a line (its detail, why it could not be checked again), gone when it is drawn again
    const under = (row) => {
        while (row.nextElementSibling?.matches('.w4os-health-detail, .w4os-health-note')) {
            row.nextElementSibling.remove();
        }
    };
    const check = (row) => {
        const when = row.querySelector('.w4os-health-when');
        when && (when.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>');
        return fetch(address(row.dataset.check), { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
            .then((answer) => answer.text().then((text) => (answer.ok ? text : Promise.reject(text || answer.status))))
            .then((html) => {
                const body = document.createElement('tbody');
                body.innerHTML = html.trim();
                const lines = [...body.querySelectorAll('tr')];
                if (!lines.length) {
                    return Promise.reject('');
                }
                under(row);
                row.replaceWith(...lines);
                // Its time in the browser's own time
                lines.forEach((line) => window.W4OSDates?.localize(line));
                return lines[0];
            })
            .catch((reason) => {
                // Why it could not be checked again, under the line, across it: never a column of its own
                when && (when.textContent = '');
                under(row);
                const note = document.createElement('tr');
                const cell = document.createElement('td');
                note.className = 'w4os-health-note';
                cell.colSpan = Math.max(1, row.cells.length - 1);
                cell.className = 'small text-secondary';
                cell.textContent = (banner?.dataset.failed || '%s').replace('%s', String(reason).slice(0, 300));
                note.append(document.createElement('td'), cell);
                row.after(note);
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
            // How many errors and warnings now, as the page says them
            const errors = document.querySelectorAll('tr[data-check].w4os-health-error').length;
            const warnings = document.querySelectorAll('tr[data-check]:is(.w4os-health-warning, .w4os-health-slow)').length;
            const said = (count, kind) => (count === 1 ? banner.dataset[kind + 'One'] : banner.dataset[kind + 'Other']).replace('%d', count);
            const alert = banner.querySelector('.alert');
            alert?.classList.toggle('alert-danger', errors > 0);
            alert?.classList.toggle('alert-warning', !errors && warnings > 0);
            alert?.classList.toggle('alert-success', !errors && !warnings);
            const level = banner.querySelector('.w4os-health-level');
            const parts = [errors && said(errors, 'errors'), warnings && said(warnings, 'warnings')].filter(Boolean);
            level && (level.textContent = parts.length ? parts.join(', ') : banner.dataset.none);
            const time = last?.querySelector('.w4os-health-when time');
            const checked = banner.querySelector('.w4os-health-checked');
            if (time && checked) {
                const [before, after = ''] = (banner.dataset.checked || '%s').split('%s');
                checked.replaceChildren(before, time.cloneNode(true), after);
            }
        });
    });
});
