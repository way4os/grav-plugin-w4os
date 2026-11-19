// The sidebar folded below Tabler's xl breakpoint while the manager has saved no choice (js/sidebar.js), run by node --test
const { test } = require('node:test');
const assert = require('node:assert');
const sidebar = require('../../js/sidebar.js');

// A page: its root element (its attributes), the choice Tabler saved, and the width, which can change
const page = (saved, narrow) => {
    const attributes = {};
    const root = { setAttribute: (name, value) => { attributes[name] = value; }, removeAttribute: (name) => { delete attributes[name]; } };
    const listeners = [];
    const media = { matches: narrow, addEventListener: (event, listener) => listeners.push(listener) };
    const resize = (matches) => { media.matches = matches; listeners.forEach((listener) => listener()); };
    return { attributes, root, media, resize, saved: () => saved.value };
};

test('folds to its icons, unfolded on hover, below xl without a saved choice; unfolds at xl', () => {
    const p = page({ value: null }, true);
    sidebar(p.root, p.saved, p.media);
    assert.strictEqual(p.attributes['data-bs-sidebar'], 'folded-hover');
    p.resize(false);
    assert.strictEqual(p.attributes['data-bs-sidebar'], undefined);
    p.resize(true);
    assert.strictEqual(p.attributes['data-bs-sidebar'], 'folded-hover');
});

test('leaves a saved choice as Tabler applied it, at any width, also once saved later', () => {
    const saved = { value: 'default' };
    const p = page(saved, true);
    sidebar(p.root, p.saved, p.media);
    assert.strictEqual(p.attributes['data-bs-sidebar'], undefined);

    const later = { value: null };
    const q = page(later, false);
    sidebar(q.root, q.saved, q.media);
    later.value = 'default';
    q.resize(true);
    assert.strictEqual(q.attributes['data-bs-sidebar'], undefined);
});
