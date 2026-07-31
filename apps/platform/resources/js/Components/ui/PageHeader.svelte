<script>
    /**
     * The one page header. Every workspace page uses this — the two competing
     * header implementations it replaces differed in title size, weight, margin,
     * and whether a description was possible at all.
     *
     * `titleArea` replaces the eyebrow/title/description block for the rare page
     * needing custom heading content, while this component keeps ownership of the
     * breadcrumbs slot, the wrapper, the margins, and the actions row. That is
     * what stops the escape hatch from becoming a second header implementation:
     * the wrapper markup exists once, here, and no caller can drift from it.
     */
    let { eyebrow = '', title = '', description = '', breadcrumbs, actions, titleArea } = $props();
</script>

{#if breadcrumbs}
    <div class="mb-4">{@render breadcrumbs()}</div>
{/if}

<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        {#if titleArea}
            {@render titleArea()}
        {:else}
            {#if eyebrow}
                <p class="font-condensed text-2xs font-semibold tracking-[0.18em] text-faint uppercase">{eyebrow}</p>
            {/if}
            <h1 class="mt-1.5 font-condensed text-3xl font-semibold text-text uppercase">{title}</h1>
            {#if description}
                <p class="mt-1.5 max-w-[46ch] text-base text-muted">{description}</p>
            {/if}
        {/if}
    </div>
    {#if actions}
        <div class="flex flex-wrap items-center gap-2">{@render actions()}</div>
    {/if}
</div>
