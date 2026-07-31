<script>
    import { Moon, Sun } from '@lucide/svelte';
    import { theme, toggleTheme } from '../../design/theme.js';
</script>

<button
    type="button"
    onclick={toggleTheme}
    class="grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none transition hover:bg-nav-text/10 hover:text-nav-strong"
    aria-label={$theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
    title={$theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
>
    {#if $theme === 'dark'}
        <Sun class="h-4 w-4" strokeWidth={1.9} />
    {:else}
        <Moon class="h-4 w-4" strokeWidth={1.9} />
    {/if}
</button>
