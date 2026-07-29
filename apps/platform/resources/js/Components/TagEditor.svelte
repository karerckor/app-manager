<script>
    import { onDestroy } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';

    export let tags = [];
    export let url = null;
    export let disabled = false;
    export let suggestions = [];
    export let align = 'left';
    export let emptyLabel = '';
    export let quiet = false;

    const PALETTE = [
        { name: 'None', value: null },
        { name: 'Indigo', value: '#6366f1' },
        { name: 'Violet', value: '#8b5cf6' },
        { name: 'Sky', value: '#0ea5e9' },
        { name: 'Teal', value: '#14b8a6' },
        { name: 'Emerald', value: '#10b981' },
        { name: 'Amber', value: '#f59e0b' },
        { name: 'Rose', value: '#f43f5e' },
    ];

    let working = [];
    let text = '';
    let color = null;
    let saving = false;
    let open = false;
    let anchorEl;
    let popEl;
    let pos = { top: 0, left: 0 };

    $: working = (tags || []).map((t) => [t[0], t[1] ?? null]);
    $: canEdit = !disabled && !!url;
    $: toolClass = quiet ? 'row-tools' : '';
    $: available = (suggestions || [])
        .map((t) => (Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]))
        .filter((t) => t[0] && !working.some((w) => w[0].toLowerCase() === t[0].toLowerCase()))
        .filter((t, i, arr) => arr.findIndex((x) => x[0].toLowerCase() === t[0].toLowerCase()) === i)
        .slice(0, 6);

    function contrast(hex) {
        const h = hex.replace('#', '');
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#0f172a' : '#ffffff';
    }
    const tint = (hex) => `${hex}1f`;

    function submit(next) {
        saving = true;
        router.patch(url, { tags: next }, { preserveScroll: true, onFinish: () => (saving = false) });
    }

    function place() {
        if (!anchorEl) return;
        const r = anchorEl.getBoundingClientRect();
        const w = 244;
        let left = align === 'right' ? r.right - w : r.left;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        pos = { top: Math.round(r.bottom + 6), left: Math.round(left) };
    }

    function bindReposition() {
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
    }
    function unbindReposition() {
        window.removeEventListener('scroll', place, true);
        window.removeEventListener('resize', place);
    }
    onDestroy(unbindReposition);

    function openPop() {
        if (!canEdit) return;
        text = '';
        color = null;
        open = true;
        bindReposition();
        requestAnimationFrame(() => {
            place();
            popEl?.querySelector('input')?.focus();
        });
    }
    function closePop() {
        open = false;
        unbindReposition();
    }

    function addValue(value, c) {
        const v = (value || '').trim();
        if (!v) return;
        if (working.some((t) => t[0].toLowerCase() === v.toLowerCase())) return;
        const next = [...working, [v, c ?? null]];
        working = next;
        submit(next);
    }
    function addFromInput() {
        addValue(text, color);
        closePop();
    }
    function addSuggestion(tag) {
        addValue(tag[0], tag[1]);
        closePop();
    }
    function removeTag(i) {
        const next = working.filter((_, idx) => idx !== i);
        working = next;
        submit(next);
    }

    function onWindowPointer(e) {
        if (!open) return;
        if (popEl?.contains(e.target) || anchorEl?.contains(e.target)) return;
        closePop();
    }
    function onKeydown(e) {
        if (open && e.key === 'Escape') closePop();
    }
</script>

<svelte:window on:pointerdown={onWindowPointer} on:keydown={onKeydown} />

<span class="inline-flex flex-wrap items-center gap-1">
    {#each working as tag, i (tag[0])}
        <span
            class="group/tag inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset"
            style={tag[1]
                ? `background-color:${tint(tag[1])};color:${tag[1]};--tw-ring-color:${tint(tag[1])}`
                : 'background-color:#f1f5f9;color:#475569;--tw-ring-color:#e2e8f0'}
        >
            <span class="h-1.5 w-1.5 shrink-0 rounded-full" style={`background-color:${tag[1] || '#94a3b8'}`}></span>
            {tag[0]}
            {#if canEdit}
                <button type="button" class="-mr-0.5 rounded p-0 leading-none opacity-50 shadow-none hover:opacity-100 group-hover/tag:opacity-80 {toolClass}" style="background:transparent;color:inherit" aria-label={`Remove ${tag[0]}`} disabled={saving} on:click={() => removeTag(i)}>
                    <X class="h-3 w-3" strokeWidth={2.6} />
                </button>
            {/if}
        </span>
    {/each}

    {#if canEdit}
        <button
            type="button"
            bind:this={anchorEl}
            class="inline-flex items-center gap-1 rounded-md border border-dashed border-slate-300 bg-transparent px-1.5 py-0.5 text-[11px] font-medium text-slate-400 shadow-none transition hover:border-indigo-400 hover:bg-indigo-50/60 hover:text-indigo-600 {open ? 'border-indigo-400 text-indigo-600' : ''} {toolClass}"
            aria-label="Add tag"
            aria-expanded={open}
            on:click={() => (open ? closePop() : openPop())}
        >
            <Plus class="h-3 w-3" strokeWidth={2.6} />
            {#if !working.length}Tag{/if}
        </button>
    {:else if !working.length && emptyLabel}
        <span class="text-[11px] text-slate-400">{emptyLabel}</span>
    {/if}
</span>

{#if open}
    <div bind:this={popEl} class="fixed z-[60] w-[244px] rounded-xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-950/10" style="top:{pos.top}px;left:{pos.left}px">
        <p class="mb-2 text-[10px] font-semibold tracking-[0.12em] text-slate-400 uppercase">New tag</p>
        <div class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1.5 focus-within:border-indigo-500 focus-within:ring-4 focus-within:ring-indigo-500/10">
            <span class="h-2 w-2 shrink-0 rounded-full" style={`background-color:${color || '#94a3b8'}`}></span>
            <input
                type="text"
                class="w-full border-0 bg-transparent p-0 text-xs text-slate-800 shadow-none outline-none ring-0 placeholder:text-slate-400 focus:ring-0"
                placeholder="Label…"
                maxlength="32"
                bind:value={text}
                on:keydown={(e) => e.key === 'Enter' && (e.preventDefault(), addFromInput())}
            />
        </div>

        <div class="mt-2.5 flex items-center gap-1.5">
            {#each PALETTE as swatch}
                <button
                    type="button"
                    class="grid h-5 w-5 place-items-center rounded-full p-0 shadow-none transition hover:scale-110 {color === swatch.value ? 'ring-2 ring-slate-900 ring-offset-1' : 'ring-1 ring-slate-200'}"
                    style={`background-color:${swatch.value || '#f1f5f9'}`}
                    title={swatch.name}
                    aria-label={swatch.name}
                    aria-pressed={color === swatch.value}
                    on:click={() => (color = swatch.value)}
                >
                    {#if !swatch.value}<span class="h-2 w-2 rounded-full ring-1 ring-slate-300"></span>{/if}
                </button>
            {/each}
        </div>

        {#if available.length}
            <p class="mt-3 mb-1.5 text-[10px] font-semibold tracking-[0.12em] text-slate-400 uppercase">Reuse</p>
            <div class="flex flex-wrap gap-1">
                {#each available as tag}
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium shadow-none ring-1 ring-inset"
                        style={tag[1]
                            ? `background-color:${tint(tag[1])};color:${tag[1]};--tw-ring-color:${tint(tag[1])}`
                            : 'background-color:#f1f5f9;color:#475569;--tw-ring-color:#e2e8f0'}
                        on:click={() => addSuggestion(tag)}
                    >
                        <span class="h-1.5 w-1.5 rounded-full" style={`background-color:${tag[1] || '#94a3b8'}`}></span>
                        {tag[0]}
                    </button>
                {/each}
            </div>
        {/if}

        <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="ghost px-2.5 py-1 text-xs" on:click={closePop}>Cancel</button>
            <button type="button" class="px-2.5 py-1 text-xs" disabled={!text.trim() || saving} on:click={addFromInput}>Add tag</button>
        </div>
    </div>
{/if}
