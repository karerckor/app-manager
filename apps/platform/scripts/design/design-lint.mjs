#!/usr/bin/env node
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

// All 22 of Tailwind's default ramps. Omitting any of them leaves a silent hole:
// `fuchsia` and `pink` are in live use in Pages/Apps/Media/Index.svelte today.
const RAW_PALETTES = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
const UTILITY_PREFIXES = ['bg', 'text', 'border', 'ring', 'divide', 'from', 'via', 'to', 'outline', 'shadow', 'accent', 'fill', 'stroke', 'decoration', 'placeholder', 'caret'];
const RADIUS_STEPS = new Set(['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', 'full']);
const ALLOWED_RADII = new Set(['sm', 'md', 'full', 'none']);

const RULES = [
    {
        name: 'arbitrary-type-size',
        pattern: /\btext-\[[^\]]+\]/g,
        hint: 'use one of the seven named steps: text-2xs xs sm base lg xl 3xl',
    },
    {
        name: 'raw-palette',
        pattern: new RegExp(`\\b(?:${UTILITY_PREFIXES.join('|')})-(?:${RAW_PALETTES.join('|')})-\\d{2,3}\\b`, 'g'),
        hint: 'use a semantic token: surface, surface-raised, surface-sunken, border, border-subtle, border-control, text, muted, faint, brand, brand-fg, brand-soft, brand-soft-line, ok, warn-text, warn-fill, danger, nav, nav-text',
    },
    {
        // The radius counterpart of arbitrary-type-size. `rounded-[2px]` appears four
        // times in AppShell.svelte and `rounded-[22%]` three times in Media/Index.svelte,
        // so this is an active hole, not a hypothetical one. Note this pattern requires
        // a bracket, so it never double-counts with off-scale-radius below, whose own
        // pattern backs off to a bare `rounded` match on the same text.
        name: 'arbitrary-radius',
        pattern: /\brounded(?:-[a-z]+)?-\[[^\]]+\]/g,
        hint: 'radius is three steps: rounded-sm (controls), rounded-md (containers), rounded-full (avatars and dots)',
    },
    {
        name: 'off-scale-radius',
        pattern: /\brounded(?:-[a-z]+)?-?([a-z0-9]*)\b/g,
        hint: 'radius is three steps: rounded-sm (controls), rounded-md (containers), rounded-full (avatars and dots)',
        reject: (match) => {
            const suffix = match.replace(/^rounded-?/, '');
            if (suffix === '') return false;
            // The pattern also swallows a trailing directional infix (e.g. the "t" in
            // "rounded-t-lg", or a decoy like the "not" in "rounded-md-not"). The size
            // step is always the last dash-separated segment; only flag it if it's a
            // real Tailwind radius keyword that isn't one of the permitted three.
            const step = suffix.split('-').pop();
            return RADIUS_STEPS.has(step) && !ALLOWED_RADII.has(step);
        },
    },
];

/** Find every design-system violation in one file's source text. */
export function findViolations(source, filename) {
    const violations = [];
    source.split('\n').forEach((text, index) => {
        for (const rule of RULES) {
            for (const match of text.matchAll(rule.pattern)) {
                if (rule.reject && !rule.reject(match[0])) continue;
                violations.push({ file: filename, line: index + 1, rule: rule.name, match: match[0] });
            }
        }
    });
    return violations;
}

const walk = (path, out = []) => {
    for (const entry of readdirSync(path)) {
        const full = join(path, entry);
        if (statSync(full).isDirectory()) walk(full, out);
        else if (/\.(svelte|js)$/.test(full)) out.push(full);
    }
    return out;
};

if (import.meta.url === `file://${process.argv[1]}`) {
    const roots = process.argv.slice(2);
    if (roots.length === 0) {
        console.error('usage: design-lint.mjs <path> [path...]');
        process.exit(2);
    }
    const all = roots.flatMap((root) => (statSync(root).isDirectory() ? walk(root) : [root]));
    const violations = all.flatMap((file) => findViolations(readFileSync(file, 'utf8'), relative(process.cwd(), file)));

    if (violations.length === 0) {
        console.log(`design:lint — ${all.length} file(s) clean`);
        process.exit(0);
    }
    for (const violation of violations) {
        const hint = RULES.find((rule) => rule.name === violation.rule).hint;
        console.error(`${violation.file}:${violation.line}  ${violation.rule}  "${violation.match}"\n    ${hint}`);
    }
    console.error(`\n${violations.length} violation(s) in ${all.length} file(s)`);
    process.exit(1);
}
