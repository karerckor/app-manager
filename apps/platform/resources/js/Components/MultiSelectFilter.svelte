<script>
    import { Check, ChevronDown, Search } from '@lucide/svelte';
    import { onMount } from 'svelte';

    export let label;
    export let options = [];
    export let values = [];
    export let searchable = false;
    export let searchPlaceholder = 'Search…';
    export let onChange = () => {};

    let query = '';
    let container;
    let dropdown;

    onMount(() => {
        const closeOnOutsideClick = (event) => {
            if (dropdown?.open && !container?.contains(event.target)) {
                dropdown.open = false;
                query = '';
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);

        return () => document.removeEventListener('pointerdown', closeOnOutsideClick);
    });

    $: visibleOptions = options.filter((option) => !query.trim()
        || option.label.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()));
    $: summary = values.length === 0
        ? `All ${label.toLocaleLowerCase()}`
        : values.length === 1
            ? options.find((option) => option.value === values[0])?.label ?? values[0]
            : `${values.length} selected`;

    const toggle = (value) => onChange(values.includes(value)
        ? values.filter((selected) => selected !== value)
        : [...values, value]);
</script>

<div bind:this={container}>
    <span class="label">{label}</span>
    <details bind:this={dropdown} class="group relative">
        <summary class="flex min-h-9 cursor-pointer list-none items-center justify-between gap-3 rounded-md border border-border bg-surface-raised px-3 py-2 text-xs text-text outline-none transition hover:border-border-control focus-visible:ring-2 focus-visible:ring-brand/30">
            <span class:font-semibold={values.length > 0} class:text-brand={values.length > 0}>{summary}</span>
            <span class="flex items-center gap-1.5">
                {#if values.length}<span class="rounded-full bg-brand-soft px-1.5 font-mono text-[9px] font-semibold text-brand">{values.length}</span>{/if}
                <ChevronDown class="h-3.5 w-3.5 text-faint transition group-open:rotate-180" />
            </span>
        </summary>
        <div class="absolute z-30 mt-1.5 w-full min-w-52 rounded-md border border-border bg-surface-raised p-1.5 shadow-lg">
            {#if searchable}
                <label class="relative mb-1.5 block">
                    <span class="sr-only">Search {label.toLocaleLowerCase()}</span>
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-faint" />
                    <input class="py-1.5 pl-8 text-xs" type="search" placeholder={searchPlaceholder} bind:value={query} />
                </label>
            {/if}
            <div class="max-h-56 overflow-y-auto">
                {#each visibleOptions as option (option.value)}
                    <label class="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-xs text-muted hover:bg-surface-sunken hover:text-text">
                        <input class="sr-only" type="checkbox" checked={values.includes(option.value)} on:change={() => toggle(option.value)} />
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border {values.includes(option.value) ? 'border-brand bg-brand text-white' : 'border-border-control bg-surface-raised'}">
                            {#if values.includes(option.value)}<Check class="h-3 w-3" strokeWidth={2.5} />{/if}
                        </span>
                        {#if option.color}<span class="h-2 w-2 shrink-0 rounded-full" style:background-color={option.color}></span>{/if}
                        <span class="truncate">{option.label}</span>
                    </label>
                {:else}
                    <p class="px-2 py-4 text-center text-[11px] text-faint">No matches</p>
                {/each}
            </div>
            {#if values.length}
                <button type="button" class="ghost mt-1 w-full px-2 py-1.5 text-[10px]" on:click={() => onChange([])}>Clear selection</button>
            {/if}
        </div>
    </details>
</div>
