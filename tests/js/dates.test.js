// The times of the admin told again in the browser's own time and language (js/dates.js), run by node --test
const { test } = require('node:test');
const assert = require('node:assert');
const localize = require('../../js/dates.js');

// A <time> as the server writes it (Site::timeTag): its moment, its style, relative or not, its text and its tip in the server's zone
const time = (datetime, style, relative = false) => {
    const attributes = { datetime, 'data-w4os-time': style };
    relative && (attributes['data-w4os-relative'] = '');
    return {
        textContent: 'server', title: 'server', dataset: { w4osTime: style },
        getAttribute: (name) => attributes[name] ?? null,
        hasAttribute: (name) => name in attributes,
    };
};
const page = (...times) => ({ querySelectorAll: () => times });

test('tells a time in the zone and the language of the browser, its zone in its tip', () => {
    const shown = time('2026-11-25T20:37:00Z', 'list');
    localize(page(shown), 'fr', 'America/Toronto');
    assert.strictEqual(shown.textContent, '25/11/2026 15:37');
    assert.match(shown.title, /^25 novembre 2026 (à )?15:37 UTC−5$/);
});

test('keeps the words of a recent time the server said relatively, its tip told again; a date alone stays a date', () => {
    const recent = time('2026-11-25T20:37:00Z', 'list', true);
    const day = time('2026-11-25T03:00:00Z', 'date');
    localize(page(recent, day), 'en', 'America/Toronto');
    assert.strictEqual(recent.textContent, 'server');
    assert.match(recent.title, /^November 25, 2026 (at )?3:37\sPM EST$/);
    assert.strictEqual(day.textContent, 'Nov 24, 2026');
});
