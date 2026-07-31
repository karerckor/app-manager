import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findViolations } from '../../scripts/design/design-lint.mjs';

test('flags arbitrary pixel type sizes', () => {
    const found = findViolations('<p class="text-[9px] font-bold">x</p>', 'a.svelte');
    assert.equal(found.length, 1);
    assert.equal(found[0].rule, 'arbitrary-type-size');
    assert.equal(found[0].match, 'text-[9px]');
    assert.equal(found[0].line, 1);
});

test('flags raw palette classes across every prefix and utility', () => {
    const source = [
        '<div class="bg-slate-200">',
        '<div class="text-indigo-600">',
        '<div class="border-violet-400">',
        '<div class="ring-rose-500">',
        '<div class="divide-emerald-100">',
        '<div class="from-amber-300">',
    ].join('\n');
    const found = findViolations(source, 'b.svelte');
    assert.equal(found.length, 6);
    assert.deepEqual(found.map((v) => v.line), [1, 2, 3, 4, 5, 6]);
    for (const violation of found) assert.equal(violation.rule, 'raw-palette');
});

test('flags radii outside the three-step scale', () => {
    const found = findViolations('<div class="rounded-2xl rounded-3xl rounded-lg rounded-xl">', 'c.svelte');
    assert.equal(found.length, 4);
    for (const violation of found) assert.equal(violation.rule, 'off-scale-radius');
});

test('allows the three permitted radii', () => {
    assert.deepEqual(findViolations('<div class="rounded-sm rounded-md rounded-full">', 'd.svelte'), []);
});

test('flags an off-scale step behind a directional infix', () => {
    const found = findViolations('<div class="rounded-t-lg rounded-b-sm">', 'g.svelte');
    assert.equal(found.length, 1);
    assert.equal(found[0].match, 'rounded-t-lg');
});

test('flags arbitrary bracket radii, once each', () => {
    const found = findViolations('<div class="rounded-[2px] rounded-t-[22%]">', 'h.svelte');
    assert.equal(found.length, 2);
    for (const violation of found) assert.equal(violation.rule, 'arbitrary-radius');
});

test('flags the ramps most easily left out of the list', () => {
    const source = '<div class="bg-pink-500">\n<div class="text-fuchsia-600">\n<div class="border-lime-400">';
    const found = findViolations(source, 'i.svelte');
    assert.equal(found.length, 3);
    for (const violation of found) assert.equal(violation.rule, 'raw-palette');
});

test('allows semantic tokens and named type steps', () => {
    const clean = '<p class="bg-surface-raised text-faint text-2xs font-condensed rounded-sm border-border-control">ok</p>';
    assert.deepEqual(findViolations(clean, 'e.svelte'), []);
});

test('ignores hex values and identifiers that merely look like classes', () => {
    const source = '<span>ai.appfront.butterbird</span>\n<!-- #14508C -->';
    assert.deepEqual(findViolations(source, 'f.svelte'), []);
});

test('reports the filename it was given', () => {
    const found = findViolations('<i class="bg-slate-50">', 'Components/ui/Badge.svelte');
    assert.equal(found[0].file, 'Components/ui/Badge.svelte');
});
