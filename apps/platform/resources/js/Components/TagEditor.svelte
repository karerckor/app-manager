<script>
    import { onDestroy, tick } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';
    import { TAG_ANIMATIONS, TAG_ANIMATION_TOKENS, animationClass } from '../lib/tagAnimations.js';

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
    let animation = null;
    let saving = false;
    let open = false;
    let anchorEl;
    let popEl;
    let pos = { top: 0, left: 0, ready: false };

    $: working = (tags || []).map((t) => (Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]));
    $: canEdit = !disabled && !!url;
    $: toolClass = quiet && working.length ? 'row-tools' : '';
    $: available = (suggestions || [])
        .map((t) => (Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]))
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

    // Drop trailing nulls so the payload matches what the server stores. A tag
    // with an animation but no color must keep the null placeholder at index 1.
    const compact = (t) => (t[2] ? [t[0], t[1] ?? null, t[2]] : t[1] ? [t[0], t[1]] : [t[0]]);

    function submit(next) {
        saving = true;
        router.patch(url, { tags: next.map(compact) }, { preserveScroll: true, onFinish: () => (saving = false) });
    }

    function place() {
        if (!anchorEl) return;
        const anchor = anchorEl.getBoundingClientRect();
        const margin = 8;
        const gap = 6;
        const width = popEl?.offsetWidth || 272;
        const height = popEl?.offsetHeight || 0;
        const spaceBelow = window.innerHeight - anchor.bottom - gap - margin;
        const spaceAbove = anchor.top - gap - margin;

        let left = align === 'right' ? anchor.right - width : anchor.left;
        left = Math.max(margin, Math.min(left, window.innerWidth - width - margin));

        let top = anchor.bottom + gap;
        if (height > spaceBelow && spaceAbove > spaceBelow) {
            top = anchor.top - height - gap;
        }
        top = Math.max(margin, Math.min(top, window.innerHeight - height - margin));

        pos = { top: Math.round(top), left: Math.round(left), ready: true };
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
        animation = null;
        pos = { ...pos, ready: false };
        open = true;
        bindReposition();
        requestAnimationFrame(async () => {
            place();
            await tick();
            popEl?.querySelector('input')?.focus();
        });
    }
    function closePop() {
        open = false;
        unbindReposition();
    }

    function addValue(value, c, a) {
        const v = (value || '').trim();
        if (!v) return;
        if (working.some((t) => t[0].toLowerCase() === v.toLowerCase())) return;
        const next = [...working, [v, c ?? null, a ?? null]];
        working = next;
        submit(next);
    }
    function addFromInput() {
        addValue(text, color, animation);
        closePop();
    }
    function addSuggestion(tag) {
        addValue(tag[0], tag[1], tag[2]);
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

<span class="group inline-flex flex-wrap items-center gap-1">
    {#each working as tag, i (tag[0])}
        <span
            class="group/tag inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset {animationClass(tag[2])}"
            style={tag[1]
                ? `background-color:${tint(tag[1])};color:${tag[1]};--tw-ring-color:${tint(tag[1])}`
                : 'background-color:var(--surface-sunken);color:var(--text-muted);--tw-ring-color:var(--border)'}
        >
            <span class="h-1.5 w-1.5 shrink-0 rounded-full" style={`background-color:${tag[1] || '#94a3b8'}`}></span>
            {tag[0]}
            {#if canEdit}
                <button type="button" class="-mr-0.5 rounded p-0 leading-none {quiet ? '' : 'opacity-50'} shadow-none hover:opacity-100 group-hover/tag:opacity-80 {toolClass}" style="background:transparent;color:inherit" aria-label={`Remove ${tag[0]}`} disabled={saving} on:click={() => removeTag(i)}>
                    <X class="h-3 w-3" strokeWidth={2.6} />
                </button>
            {/if}
        </span>
    {/each}

    {#if canEdit}
        <button
            type="button"
            bind:this={anchorEl}
            class="inline-flex items-center gap-1 rounded-sm border border-dashed border-border-control bg-transparent px-2 py-1 text-xs font-semibold text-muted shadow-none transition hover:border-brand hover:bg-brand-soft hover:text-brand {open ? 'border-brand bg-brand-soft text-brand' : ''} {toolClass}"
            aria-label="Add tag"
            aria-expanded={open}
            on:click={() => (open ? closePop() : openPop())}
        >
            <Plus class="h-3 w-3" strokeWidth={2.6} />
            {#if !working.length}Tag{/if}
        </button>
    {:else if !working.length && emptyLabel}
        <span class="text-xs text-faint">{emptyLabel}</span>
    {/if}
</span>

{#if open}
    <div
        bind:this={popEl}
        class="fixed z-[60] max-h-[calc(100vh-1rem)] w-[272px] overflow-y-auto rounded-md border border-border bg-surface-raised p-4 shadow-2xl"
        style="top:{pos.top}px;left:{pos.left}px;visibility:{pos.ready ? 'visible' : 'hidden'}"
    >
        <div class="mb-3 flex items-start justify-between gap-3">
            <div><p class="eyebrow">New tag</p><p class="mt-1 text-xs text-muted">Label and color make filters easier to scan.</p></div>
        </div>
        <div class="flex items-center gap-2 rounded-md border border-border-control bg-surface-raised px-3 py-2 focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10">
            <span class="h-2 w-2 shrink-0 rounded-full" style={`background-color:${color || '#94a3b8'}`}></span>
            <input
                type="text"
                class="w-full border-0 bg-transparent p-0 text-sm text-text shadow-none outline-none ring-0 placeholder:text-faint focus:ring-0"
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
                    class="grid h-6 w-6 place-items-center rounded-full p-0 shadow-none transition hover:scale-110 {color === swatch.value ? 'ring-2 ring-text ring-offset-2 ring-offset-surface-raised' : 'ring-1 ring-border'}"
                    style={`background-color:${swatch.value || 'var(--surface-sunken)'}`}
                    title={swatch.name}
                    aria-label={swatch.name}
                    aria-pressed={color === swatch.value}
                    on:click={() => (color = swatch.value)}
                >
                    {#if !swatch.value}<span class="h-2 w-2 rounded-full ring-1 ring-border-control"></span>{/if}
                </button>
            {/each}
        </div>

        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
            <button
                type="button"
                class="rounded-sm px-2 py-1 text-xs font-medium shadow-none ring-1 ring-inset {animation === null ? 'ring-text' : 'ring-border'}"
                style="background-color:var(--surface-sunken);color:var(--text-muted)"
                aria-pressed={animation === null}
                on:click={() => (animation = null)}
            >
                No motion
            </button>
            {#each TAG_ANIMATION_TOKENS as token}
                <button
                    type="button"
                    class="rounded-sm px-2 py-1 text-xs font-medium shadow-none ring-1 ring-inset {animation === token ? `ring-text ${animationClass(token)}` : 'ring-border'}"
                    style="background-color:var(--surface-sunken);color:var(--text-muted)"
                    aria-pressed={animation === token}
                    on:click={() => (animation = token)}
                >
                    {TAG_ANIMATIONS[token].label}
                </button>
            {/each}
        </div>

        {#if available.length}
            <p class="eyebrow mt-4 mb-2">Reuse</p>
            <div class="flex flex-wrap gap-1.5">
                {#each available as tag}
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-sm px-2 py-1 text-xs font-medium shadow-none ring-1 ring-inset {animationClass(tag[2])}"
                        style={tag[1]
                            ? `background-color:${tint(tag[1])};color:${tag[1]};--tw-ring-color:${tint(tag[1])}`
                            : 'background-color:var(--surface-sunken);color:var(--text-muted);--tw-ring-color:var(--border)'}
                        on:click={() => addSuggestion(tag)}
                    >
                        <span class="h-1.5 w-1.5 rounded-full" style={`background-color:${tag[1] || '#94a3b8'}`}></span>
                        {tag[0]}
                    </button>
                {/each}
            </div>
        {/if}

        <div class="mt-4 flex justify-end gap-2 border-t border-border-subtle pt-3">
            <button type="button" class="ghost px-2.5 py-1 text-xs" on:click={closePop}>Cancel</button>
            <button type="button" class="px-2.5 py-1 text-xs" disabled={!text.trim() || saving} on:click={addFromInput}>Add tag</button>
        </div>
    </div>
{/if}
