<script>
    import { CircleCheck } from '@lucide/svelte';
    import AppShell from './AppShell.svelte';
    import PageHeader from './ui/PageHeader.svelte';

    /**
     * Standard workspace page layout.
     *
     * `width` is 'default' (1240px, list and table pages) or 'narrow' (720px,
     * single-column forms). Two named widths replace the three ad-hoc container
     * widths the pages used to pick individually.
     */
    let {
        auth,
        navigationTeams = [],
        navigationTeam = null,
        active = '',
        eyebrow = '',
        title = '',
        description = '',
        width = 'default',
        flash = {},
        breadcrumbs,
        actions,
        heading,
        children,
    } = $props();

    const shell = $derived(width === 'narrow' ? 'max-w-[720px]' : 'max-w-[1240px]');
</script>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} {active}>
    <main class="mx-auto {shell} px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
        {#if heading}
            {#if breadcrumbs}<div class="mb-4">{@render breadcrumbs()}</div>{/if}
            <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">{@render heading()}</div>
                {#if actions}<div class="flex flex-wrap items-center gap-2">{@render actions()}</div>{/if}
            </div>
        {:else}
            <PageHeader {eyebrow} {title} {description} {breadcrumbs} {actions} />
        {/if}

        {#if flash?.status}
            <div class="mb-5 flex items-center gap-2.5 rounded-md border border-ok/30 bg-ok/10 px-4 py-3 text-sm font-medium text-ok" role="status">
                <CircleCheck class="h-4 w-4 shrink-0" strokeWidth={2} />
                {flash.status}
            </div>
        {/if}

        {@render children?.()}
    </main>
</AppShell>
