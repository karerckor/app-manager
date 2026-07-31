/** Parse `--name: #hex;` declarations out of :root and .dark blocks. */
export function parseTokens(cssText) {
    const block = (selector) => {
        const start = cssText.indexOf(selector);
        if (start === -1) throw new Error(`tokens.css has no ${selector} block`);
        const open = cssText.indexOf('{', start);
        const close = cssText.indexOf('}', open);
        return cssText.slice(open + 1, close);
    };
    const read = (body) => {
        const out = {};
        for (const match of body.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/gi)) {
            out[match[1]] = match[2].trim();
        }
        return out;
    };
    return { light: read(block(':root')), dark: read(block('.dark')) };
}

const channel = (eight) => {
    const s = eight / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
};

/** WCAG 2.1 relative luminance. */
export function luminance(hex) {
    const n = parseInt(hex.slice(1), 16);
    const r = channel((n >> 16) & 255);
    const g = channel((n >> 8) & 255);
    const b = channel(n & 255);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio, always >= 1. */
export function contrast(hexA, hexB) {
    const a = luminance(hexA);
    const b = luminance(hexB);
    const [hi, lo] = a > b ? [a, b] : [b, a];
    return (hi + 0.05) / (lo + 0.05);
}

/**
 * Every pair the design system relies on.
 * min 4.5 = text (WCAG 1.4.3). min 3 = UI components and meaningful graphics (1.4.11).
 * Decorative dividers (--border on a surface) are intentionally absent: they carry
 * no information and no threshold applies.
 */
export const PAIRS = [
    // body and prose
    { fg: 'text', bg: 'surface', min: 4.5, theme: 'both' },
    { fg: 'text', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'text-muted', bg: 'surface', min: 4.5, theme: 'both' },
    { fg: 'text-muted', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // structural labels and table heads sit on the sunken surface
    { fg: 'text-faint', bg: 'surface-sunken', min: 4.5, theme: 'both' },
    { fg: 'text-faint', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // brand as text, as a fill, and inside its own soft container
    { fg: 'brand', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'brand', bg: 'brand-soft', min: 4.5, theme: 'both' },
    { fg: 'brand-fg', bg: 'brand', min: 4.5, theme: 'both' },
    // signal colours as text
    { fg: 'ok', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'warn-text', bg: 'surface-raised', min: 4.5, theme: 'both' },
    { fg: 'danger', bg: 'surface-raised', min: 4.5, theme: 'both' },
    // sidebar
    { fg: 'nav-text', bg: 'nav', min: 4.5, theme: 'both' },
    { fg: 'nav-text-strong', bg: 'nav', min: 4.5, theme: 'both' },
    // runway bar fills against the track: meaningful graphics
    { fg: 'brand', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'warn-fill', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'danger', bg: 'border-subtle', min: 3, theme: 'both' },
    { fg: 'ok', bg: 'border-subtle', min: 3, theme: 'both' },
    // control borders delimit a UI component
    { fg: 'border-control', bg: 'surface-raised', min: 3, theme: 'both' },
    // the focus ring must be visible against what surrounds it
    { fg: 'brand', bg: 'surface', min: 3, theme: 'both' },
];
