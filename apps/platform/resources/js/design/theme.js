import { readable } from 'svelte/store';

export const THEME_STORAGE_KEY = 'app-manager-theme';

const systemPrefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

/** The value the inline boot script in app.blade.php already applied. */
const current = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');

let notify = () => {};
export const theme = readable(current(), (set) => {
    notify = set;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    // Follow the OS only while the user has expressed no preference of their own.
    const onSystemChange = () => {
        if (localStorage.getItem(THEME_STORAGE_KEY)) return;
        apply(systemPrefersDark() ? 'dark' : 'light');
    };
    media.addEventListener('change', onSystemChange);
    return () => media.removeEventListener('change', onSystemChange);
});

const apply = (value) => {
    document.documentElement.classList.toggle('dark', value === 'dark');
    document.documentElement.style.colorScheme = value;
    notify(value);
};

export const setTheme = (value) => {
    localStorage.setItem(THEME_STORAGE_KEY, value);
    apply(value);
};

export const toggleTheme = () => setTheme(current() === 'dark' ? 'light' : 'dark');
