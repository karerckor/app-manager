<script>
    import { router } from '@inertiajs/svelte';

    export let tags = [];
    export let url;
    export let disabled = false;

    let working = [];
    let text = '';
    let color = '#3b82f6';
    let useColor = false;
    let saving = false;

    $: working = (tags || []).map((t) => [t[0], t[1] ?? null]);

    function contrast(hex) {
        const h = hex.replace('#', '');
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#111827' : '#ffffff';
    }

    function submit(next) {
        saving = true;
        router.patch(url, { tags: next }, {
            preserveScroll: true,
            onFinish: () => { saving = false; },
        });
    }

    function addTag() {
        const value = text.trim();
        if (!value) return;
        if (working.some((t) => t[0].toLowerCase() === value.toLowerCase())) {
            text = '';
            return;
        }
        const next = [...working, [value, useColor ? color : null]];
        text = '';
        working = next;
        submit(next);
    }

    function removeTag(index) {
        const next = working.filter((_, i) => i !== index);
        working = next;
        submit(next);
    }
</script>

<div class="flex flex-wrap items-center gap-1.5">
    {#each working as tag, i}
        <span
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
            style="background-color: {tag[1] || '#e5e7eb'}; color: {tag[1] ? contrast(tag[1]) : '#374151'};"
        >
            {tag[0]}
            {#if !disabled}
                <button type="button" class="leading-none opacity-70 hover:opacity-100" aria-label="Remove tag" disabled={saving} on:click={() => removeTag(i)}>×</button>
            {/if}
        </span>
    {/each}

    {#if !working.length}
        <span class="text-[10px] text-slate-400">No tags</span>
    {/if}

    {#if !disabled}
        <span class="inline-flex items-center gap-1">
            <input
                type="text"
                class="w-24 rounded border border-slate-200 px-2 py-0.5 text-[10px]"
                placeholder="Add tag"
                bind:value={text}
                on:keydown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag())}
            />
            <input type="checkbox" bind:checked={useColor} aria-label="Use color" />
            <input type="color" bind:value={color} disabled={!useColor} class="h-5 w-5 border-0 bg-transparent p-0" />
            <button type="button" class="ghost px-2 py-0.5 text-[10px]" disabled={saving} on:click={addTag}>Add</button>
        </span>
    {/if}
</div>
