<script>
    import { Check, ChevronDown } from '@lucide/svelte';
    import { createEventDispatcher, onMount } from 'svelte';

    export let options = [];
    export let value = '';
    export let placeholder = 'Select an option';
    export let disabled = false;
    export let required = false;
    export let ariaLabel = undefined;
    export let className = '';

    const dispatch = createEventDispatcher();
    let container;
    let dropdown;

    $: selected = options.find((option) => String(option.value) === String(value));
    $: selectedLabel = selected?.label ?? placeholder;

    const choose = (option) => {
        if (option.disabled) return;
        value = option.value;
        dropdown.open = false;
        dispatch('change', value);
    };

    onMount(() => {
        const close = (event) => {
            if (dropdown?.open && !container?.contains(event.target)) dropdown.open = false;
        };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    });
</script>

<div bind:this={container} class={`relative ${className}`}>
    <details bind:this={dropdown} class="group" class:pointer-events-none={disabled}>
        <summary
            class="flex min-h-9 cursor-pointer list-none items-center justify-between gap-3 rounded-md border border-border bg-surface-raised px-3 py-2 text-xs text-text outline-none transition hover:border-border-control focus-visible:ring-2 focus-visible:ring-brand/30 disabled:cursor-not-allowed disabled:opacity-50"
            class:text-faint={!selected}
            aria-label={ariaLabel}
            aria-disabled={disabled}
            title={required && !selected ? 'A selection is required' : undefined}
        >
            <span class="truncate">{selectedLabel}</span>
            <ChevronDown class="h-3.5 w-3.5 shrink-0 text-faint transition group-open:rotate-180" />
        </summary>
        <div class="absolute z-30 mt-1.5 max-h-64 w-full min-w-52 overflow-y-auto rounded-md border border-border bg-surface-raised p-1.5 shadow-lg" role="listbox" aria-label={ariaLabel}>
            {#each options as option (option.value)}
                <button
                    type="button"
                    class="flex w-full justify-between gap-3 rounded-sm bg-transparent px-2.5 py-2 text-left text-xs text-muted shadow-none hover:bg-surface-sunken hover:text-text focus-visible:ring-2 focus-visible:ring-brand/30 disabled:cursor-not-allowed disabled:opacity-45"
                    class:bg-brand-soft={String(option.value) === String(value)}
                    class:text-brand={String(option.value) === String(value)}
                    class:font-semibold={String(option.value) === String(value)}
                    role="option"
                    aria-selected={String(option.value) === String(value)}
                    disabled={option.disabled}
                    on:click={() => choose(option)}
                >
                    <span class="truncate">{option.label}</span>
                    {#if String(option.value) === String(value)}
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-sm bg-brand text-white"><Check class="h-3.5 w-3.5" strokeWidth={2.5} /></span>
                    {/if}
                </button>
            {:else}
                <p class="px-2 py-4 text-center text-[11px] text-faint">No options available</p>
            {/each}
        </div>
    </details>
</div>
