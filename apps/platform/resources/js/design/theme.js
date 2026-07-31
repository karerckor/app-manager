import { writable } from 'svelte/store';

// Must match the literal in resources/views/app.blade.php's boot script, which
// runs before any module loads and so cannot import this.
export const THEME_STORAGE_KEY = 'app-manager-theme';

/*
 * localStorage and matchMedia both throw in some privacy modes and embedded
 * webviews. The boot script guards them for the same reason; the store has to
 * as well, or a private-mode user gets a toggle that silently does nothing.
 */
const readStored = () => {
    try {
        return localStorage.getItem(THEME_STORAGE_KEY);
    } catch {
        return null;
    }
};

/** The value the inline boot script in app.blade.php already applied to <html>. */
const current = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');

/*
 * Writable internally, exported as { subscribe } only. A `readable` whose value
 * is pushed by a `set` captured in its start function goes stale whenever the
 * theme changes before anything has subscribed: the DOM updates, the store does
 * not, and the first subscriber then reads the module-load value. Holding the
 * value here instead means it tracks the DOM regardless of subscriber timing,
 * while callers still cannot write to it except through setTheme.
 */
const store = writable(current());

const apply = (value) => {
    document.documentElement.classList.toggle('dark', value === 'dark');
    document.documentElement.style.colorScheme = value;
    store.set(value);
};

export const theme = { subscribe: store.subscribe };

export const setTheme = (value) => {
    try {
        localStorage.setItem(THEME_STORAGE_KEY, value);
    } catch {
        // Storage unavailable. The theme still applies for this page's lifetime.
    }
    apply(value);
};

export const toggleTheme = () => setTheme(current() === 'dark' ? 'light' : 'dark');

/*
 * Follow the OS only while the user has expressed no preference of their own.
 * Read storage fresh on each event rather than capturing a flag, so the guard
 * cannot be defeated by ordering. One listener for the page's lifetime — this is
 * a singleton module in a single-page app, so there is nothing to tear down.
 */
try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
        if (readStored()) return;
        apply(event.matches ? 'dark' : 'light');
    });
} catch {
    // No matchMedia. Whatever the boot script decided stands.
}
