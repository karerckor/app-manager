<script>
    import AppShell from './AppShell.svelte';

    /**
     * Standard workspace page layout wrapper.
     *
     * @prop auth - Auth object passed to AppShell.
     * @prop {Array} navigationTeams - Teams list for AppShell sidebar.
     * @prop {Object} navigationTeam - Current team for AppShell sidebar.
     * @prop {string} active - Active sidebar item key.
     * @prop {string} eyebrow - Small uppercase label above the title.
     * @prop {string} title - Main page heading.
     * @prop {Object} flash - Flash messages object (optional).
     */
    export let auth;
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = '';
    export let eyebrow = '';
    export let title = '';
    export let flash = {};
</script>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} {active}>
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <slot name="breadcrumbs" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                {#if eyebrow}<p class="eyebrow mb-1.5">{eyebrow}</p>{/if}
                <slot name="heading">
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-950">{title}</h1>
                </slot>
            </div>
            <slot name="actions" />
        </div>

        {#if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>
                {flash.status}
            </div>
        {/if}

        <slot />
    </main>
</AppShell>
