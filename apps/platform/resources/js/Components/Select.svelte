<script>
    import { Check, ChevronDown, LoaderCircle, Search } from '@lucide/svelte';
    import { createEventDispatcher, onMount } from 'svelte';

    export let options = [];
    export let value = '';
    export let placeholder = 'Select an option';
    export let disabled = false;
    export let required = false;
    export let ariaLabel = undefined;
    export let className = '';
    export let menuPlacement = 'bottom';
    export let searchable = false;
    export let searchValue = '';
    export let searchMode = 'local';
    export let onSearch = null;
    export let loading = false;
    export let onScrollEnd = null;

    const dispatch = createEventDispatcher();
    let container;
    let dropdown;
    let searchInput;
    let listbox;

    $: filtered = searchable && searchMode === 'local' && searchValue
        ? options.filter((option) => option.label.toLowerCase().includes(searchValue.toLowerCase()))
        : options;
    $: selected = options.find((option) => String(option.value) === String(value));
    $: selectedLabel = selected?.label ?? placeholder;

    const choose = (option) => {
        if (option.disabled) return;
        value = option.value;
        if (searchMode !== 'remote') searchValue = '';
        dropdown.open = false;
        dispatch('change', value);
    };

    const handleSearchInput = () => {
        onSearch?.(searchValue);
    };

    const scroll = () => {
        if (!onScrollEnd || !listbox || loading) return;
        const el = listbox;
        if (el.scrollHeight - el.scrollTop - el.clientHeight < 30) {
            onScrollEnd();
        }
    };

    const toggle = (event) => {
        event.preventDefault();
        dropdown.open = !dropdown.open;
        if (dropdown.open && searchable) {
            setTimeout(() => searchInput?.focus(), 10);
        }
    };

    const preventClose = (event) => event.stopPropagation();

    onMount(() => {
        const close = (event) => {
            if (dropdown?.open && !container?.contains(event.target)) {
                dropdown.open = false;
                if (searchMode !== 'remote') searchValue = '';
            }
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
            on:click={toggle}
        >
            <span class="truncate">{selectedLabel}</span>
            <ChevronDown class="h-3.5 w-3.5 shrink-0 text-faint transition group-open:rotate-180" />
        </summary>
        <div
            class="absolute z-30 w-full min-w-52 rounded-md border border-border bg-surface-raised shadow-lg"
            class:top-full={menuPlacement !== 'top'}
            class:mt-1.5={menuPlacement !== 'top'}
            class:bottom-full={menuPlacement === 'top'}
            class:mb-1.5={menuPlacement === 'top'}
        >
            {#if searchable}
                <div role="presentation" class="flex items-center gap-2 border-b border-border-subtle px-2.5 py-2" on:pointerdown={preventClose}>
                    <Search class="h-3.5 w-3.5 shrink-0 text-faint" />
                    <input
                        bind:this={searchInput}
                        type="text"
                        class="flex-1 border-0 p-0 text-xs shadow-none focus:ring-0"
                        placeholder="Search…"
                        bind:value={searchValue}
                        on:input={handleSearchInput}
                    />
                </div>
            {/if}
            <div
                bind:this={listbox}
                class="max-h-64 overflow-y-auto p-1.5"
                on:scroll={scroll}
                role="listbox"
                aria-label={ariaLabel}
            >
                {#each filtered as option (option.value)}
                    <button
                        type="button"
                        class="flex w-full justify-between gap-3 rounded-sm bg-transparent px-2.5 py-2 text-left text-xs text-muted shadow-none hover:bg-surface-sunken hover:text-text focus-visible:ring-2 focus-visible:ring-brand/30 disabled:cursor-not-allowed disabled:opacity-45"
                        class:bg-brand-soft={String(option.value) === String(value)}
                        class:text-brand={String(option.value) === String(value)}
                        class:font-semibold={String(option.value) === String(value)}
                        role="option"
                        aria-selected={String(option.value) === String(value)}
                        disabled={option.disabled}
                        on:pointerdown={() => choose(option)}
                    >
                        <span class="truncate">{option.label}</span>
                        {#if String(option.value) === String(value)}
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-sm bg-brand text-white"><Check class="h-3.5 w-3.5" strokeWidth={2.5} /></span>
                        {/if}
                    </button>
                {:else}
                    <p class="px-2 py-4 text-center text-[11px] text-faint">
                        {#if loading}
                            Loading…
                        {:else if searchable && searchMode === 'local' && searchValue}
                            No results
                        {:else if searchable && searchMode === 'remote' && searchValue}
                            No matches
                        {:else}
                            No options available
                        {/if}
                    </p>
                {/each}
                {#if loading}
                    <div class="flex items-center justify-center py-3 text-faint">
                        <LoaderCircle class="h-3.5 w-3.5 animate-spin" />
                    </div>
                {/if}
            </div>
        </div>
    </details>
</div>
