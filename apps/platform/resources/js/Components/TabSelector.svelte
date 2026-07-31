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
        indigo:  'border-indigo-200 bg-indigo-50 text-indigo-700',
        violet:  'border-violet-200 bg-violet-50 text-violet-700',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        rose:    'border-rose-200 bg-rose-50 text-rose-700',
        amber:   'border-amber-200 bg-amber-50 text-amber-700',
        neutral: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    };
    const hoverTone = {
        indigo:  'hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700',
        violet:  'hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700',
        emerald: 'hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700',
        rose:    'hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700',
        amber:   'hover:border-amber-200 hover:bg-amber-50 hover:text-amber-700',
        neutral: 'hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700',
    };
    const restTone = {
        rose:   'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300',
        amber:  'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300',
    };
    const disabledClass = 'border-slate-100 bg-slate-50/60 text-slate-300';
    const inactiveDefault = 'border-slate-200 bg-white text-slate-600';

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
        ? 'bg-white text-slate-950 shadow-sm'
        : 'bg-transparent text-slate-500 hover:bg-white/60';
</script>

{#if variant === 'segmented'}
    <div class="inline-flex rounded-xl bg-slate-100 p-1" role="tablist" aria-label={ariaLabel}>
        {#each items as item (item.value)}
            <button
                type="button"
                role="tab"
                aria-selected={isActive(item, value)}
                class="flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-semibold shadow-none transition {segmentClass(item, value)}"
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
