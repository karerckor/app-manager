<script>
    /**
     * The one page header. Every workspace page uses this — the two competing
     * header implementations it replaces differed in title size, weight, margin,
     * and whether a description was possible at all.
     */
    let { eyebrow = '', title = '', description = '', breadcrumbs, actions } = $props();
</script>

{#if breadcrumbs}
    <div class="mb-4">{@render breadcrumbs()}</div>
{/if}

<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        {#if eyebrow}
            <p class="font-condensed text-2xs font-semibold tracking-[0.18em] text-faint uppercase">{eyebrow}</p>
        {/if}
        <h1 class="mt-1.5 font-condensed text-3xl font-semibold text-text uppercase">{title}</h1>
        {#if description}
            <p class="mt-1.5 max-w-[46ch] text-base text-muted">{description}</p>
        {/if}
    </div>
    {#if actions}
        <div class="flex flex-wrap items-center gap-2">{@render actions()}</div>
    {/if}
</div>
