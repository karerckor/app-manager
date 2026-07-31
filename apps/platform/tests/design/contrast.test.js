import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseTokens, contrast, luminance, PAIRS } from '../../scripts/design/contrast.mjs';

const css = readFileSync(new URL('../../resources/css/tokens.css', import.meta.url), 'utf8');
const tokens = parseTokens(css);

test('luminance matches known reference values', () => {
    assert.equal(luminance('#FFFFFF').toFixed(4), '1.0000');
    assert.equal(luminance('#000000').toFixed(4), '0.0000');
});

test('contrast is symmetric and matches a known pair', () => {
    assert.equal(contrast('#FFFFFF', '#000000').toFixed(2), '21.00');
    assert.equal(contrast('#000000', '#FFFFFF').toFixed(2), '21.00');
});

test('both themes define exactly the same token names', () => {
    assert.deepEqual(Object.keys(tokens.light).sort(), Object.keys(tokens.dark).sort());
});

test('every token is a six-digit hex value', () => {
    for (const theme of ['light', 'dark']) {
        for (const [name, value] of Object.entries(tokens[theme])) {
            assert.match(value, /^#[0-9A-Fa-f]{6}$/, `${theme}.${name} is "${value}"`);
        }
    }
});

test('every declared pair meets its contrast minimum', () => {
    const failures = [];
    for (const pair of PAIRS) {
        const themes = pair.theme === 'both' ? ['light', 'dark'] : [pair.theme];
        for (const theme of themes) {
            const fg = tokens[theme][pair.fg];
            const bg = tokens[theme][pair.bg];
            assert.ok(fg, `${theme} is missing token --${pair.fg}`);
            assert.ok(bg, `${theme} is missing token --${pair.bg}`);
            const ratio = contrast(fg, bg);
            if (ratio < pair.min) {
                failures.push(`${theme}: ${pair.fg} (${fg}) on ${pair.bg} (${bg}) = ${ratio.toFixed(2)}:1, needs ${pair.min}:1`);
            }
        }
    }
    assert.deepEqual(failures, []);
});
