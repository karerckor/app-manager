import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { TAG_ANIMATIONS, TAG_ANIMATION_TOKENS, animationClass } from '../../resources/js/lib/tagAnimations.js';

// The token -> CSS class map in tagAnimations.js deliberately mirrors
// App\Enums\TagAnimation, because tags are never rendered server-side and the
// class names are only needed in the browser. That duplication can drift, so
// this test pins the two lists together.
test('the JS token map matches the PHP TagAnimation enum', () => {
    const php = readFileSync(new URL('../../app/Enums/TagAnimation.php', import.meta.url), 'utf8');
    const phpValues = [...php.matchAll(/case\s+\w+\s*=\s*'([^']+)'/g)].map((m) => m[1]);

    assert.ok(phpValues.length > 0, 'failed to parse any case off the PHP enum');
    assert.deepEqual(TAG_ANIMATION_TOKENS, phpValues);
});

test('every token maps to a non-empty class', () => {
    for (const token of TAG_ANIMATION_TOKENS) {
        assert.match(animationClass(token), /\S/, `${token} has no class`);
        assert.match(TAG_ANIMATIONS[token].label, /\S/, `${token} has no label`);
    }
});

test('animationClass is total — unknown and empty input yield no class', () => {
    assert.equal(animationClass(null), '');
    assert.equal(animationClass(undefined), '');
    assert.equal(animationClass('bounce'), '');
});

test('the classes the map points at exist in the stylesheet or come from Tailwind', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    for (const token of TAG_ANIMATION_TOKENS) {
        const cls = animationClass(token);

        if (cls.startsWith('animate-')) {
            continue; // Tailwind ships these.
        }

        assert.ok(css.includes(`.${cls}`), `${cls} is not defined in app.css`);
    }
});
