<script>
    import { Link } from '@inertiajs/svelte';

    /**
     * Unified tab / chip selector component.
     *
     * @prop {Array} items - Each item: { value, label, count?, icon?, href?, tone? }
     * @prop {string} value - Currently active value (for button mode).
     * @prop {'pills' | 'segmented'} variant - Visual style.
     * @prop {string} tone - Default active colour key: indigo | violet | emerald | rose | amber | neutral.
     * @prop {string} ariaLabel - Accessible label for the wrapping nav/div.
     * @prop {Function} onChange - Called with the new value when a button tab is clicked.
     */
    export let items = [];
    export let value = '';
    export let variant = 'pills';
    export let tone = 'indigo';
    export let ariaLabel = 'Tabs';
    export let onChange = () => {};

    /* ── colour maps ─────────────────────────────────────── */
    const activeTone = {
        indigo:  'border-brand-soft-line bg-brand-soft text-brand',
        violet:  'border-brand-soft-line bg-brand-soft text-brand',
        emerald: 'border-ok/30 bg-ok/10 text-ok',
        rose:    'border-danger/30 bg-danger/10 text-danger',
        amber:   'border-warn-fill/30 bg-warn-text/10 text-warn-fill',
        neutral: 'border-brand-soft-line bg-brand-soft text-brand',
    };
    const hoverTone = {
        indigo:  'hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand',
        violet:  'hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand',
        emerald: 'hover:border-ok/30 hover:bg-ok/10 hover:text-ok',
        rose:    'hover:border-danger/30 hover:bg-danger/10 hover:text-danger',
        amber:   'hover:border-warn-fill/30 hover:bg-warn-text/10 hover:text-warn-fill',
        neutral: 'hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand',
    };
    const restTone = {
        rose:   'border-danger/30 bg-danger/10 text-danger hover:border-danger/40',
        amber:  'border-warn-fill/30 bg-warn-text/10 text-warn-fill hover:border-warn-fill/40',
    };
    const disabledClass = 'border-border-subtle bg-surface-sunken text-border-control';
    const inactiveDefault = 'border-border bg-surface-raised text-muted';

    /* ── helpers ──────────────────────────────────────────── */
    /* currentValue is threaded through explicitly (rather than closed over) so Svelte's
       per-expression dependency tracking sees `value` in the template call site and
       re-evaluates these when it changes, even though `items` itself is unchanged. */
    const isActive = (item, currentValue) => {
        if (item.href) return item.active;
        return currentValue === item.value;
    };

    const pillClass = (item, currentValue) => {
        if (isActive(item, currentValue)) return activeTone[item.tone ?? tone] ?? activeTone.indigo;
        if (item.disabled) return disabledClass;
        // If the item has a non-neutral rest tone and is not active, show its own colour
        if (item.tone && restTone[item.tone] && !isActive(item, currentValue)) return restTone[item.tone];
        return `${inactiveDefault} ${hoverTone[item.tone ?? tone] ?? hoverTone.indigo}`;
    };

    const segmentClass = (item, currentValue) => isActive(item, currentValue)
        ? 'bg-surface-raised text-text shadow-sm'
        : 'bg-transparent text-muted hover:bg-surface-raised/60';
</script>

{#if variant === 'segmented'}
    <div class="inline-flex rounded-sm bg-surface-sunken p-0.5" role="tablist" aria-label={ariaLabel}>
        {#each items as item (item.value)}
            <button
                type="button"
                role="tab"
                aria-selected={isActive(item, value)}
                class="flex items-center gap-2 rounded-sm px-3 py-1.5 text-xs font-semibold shadow-none transition {segmentClass(item, value)}"
                on:click={() => onChange(item.value)}
            >
                {#if item.icon}<svelte:component this={item.icon} class="h-3.5 w-3.5" />{/if}
                {item.label}
            </button>
        {/each}
    </div>
{:else}
    <nav class="flex flex-wrap items-center gap-2" aria-label={ariaLabel}>
        {#each items as item (item.value ?? item.href)}
            {#if item.href}
                <Link
                    href={item.href}
                    aria-current={item.active ? 'page' : undefined}
                    class="chip whitespace-nowrap {pillClass(item, value)}"
                >
                    {#if item.icon}<svelte:component this={item.icon} class="h-3.5 w-3.5" strokeWidth={1.9} />{/if}
                    {item.label}
                    {#if item.count != null}<span class="font-mono text-[10px] font-medium tabular-nums opacity-70">{item.count}</span>{/if}
                </Link>
            {:else}
                <button
                    type="button"
                    class="chip {pillClass(item, value)}"
                    disabled={item.disabled}
                    aria-pressed={isActive(item, value)}
                    on:click={() => onChange(item.value)}
                >
                    {#if item.icon}<svelte:component this={item.icon} class="h-3.5 w-3.5" strokeWidth={1.9} />{/if}
                    {item.label}
                    {#if item.count != null}<span class="font-mono text-[10px] font-medium tabular-nums opacity-70">{item.count}</span>{/if}
                </button>
            {/if}
        {/each}
    </nav>
{/if}
