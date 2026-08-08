<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { Pencil, Trash2, Plus, KeyRound, RefreshCw, TriangleAlert, Globe2 } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import IdentifierFormModal from '../Modals/IdentifierFormModal.svelte';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let tag_suggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'identifiers';
    export let expoIdentifiers = {};

    let showIdentifier = false;
    let editingIdentifier = null;
    let confirmingIdentifierDelete = null;
    const expoSyncForm = useForm({});

    $: syncedCount = identifiers.filter((identifier) => identifier.sync_status === 'synced').length;
    $: databaseOnlyCount = identifiers.filter((identifier) => identifier.sync_status === 'database_only').length;
    $: expoOnlyCount = identifiers.filter((identifier) => identifier.sync_status === 'expo_only').length;

    const openCreateIdentifier = () => {
        editingIdentifier = null;
        showIdentifier = true;
    };
    const openEditIdentifier = (identifier) => {
        editingIdentifier = identifier;
        showIdentifier = true;
    };
    const closeIdentifier = () => {
        showIdentifier = false;
        editingIdentifier = null;
    };
    const deleteIdentifier = (identifier) => router.delete(identifier.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingIdentifierDelete = null,
    });
    const syncExpo = () => {
        if (!expoIdentifiers.sync_url) return;
        expoSyncForm.post(expoIdentifiers.sync_url, { preserveScroll: true });
    };
    const platformName = (platform) => ({
        ios: 'iOS application',
        android: 'Android application',
        web: 'Web application',
    })[platform] ?? 'Application';
    const platformIconClass = (platform) => ({
        ios: 'bg-text text-white',
        android: 'bg-ok/10 text-ok',
        web: 'bg-sky-50 text-sky-700',
    })[platform] ?? 'bg-border-subtle text-muted';
</script>

<svelte:head><title>Platforms and App Identifiers · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <section class="card p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4">
            <div>
                <h2 class="text-sm font-bold">Platforms and App Identifiers</h2>
                <p class="mt-0.5 text-xs text-muted">Bundle IDs, package names, and web domains for this application.</p>
            </div>
            <div class="flex items-center gap-2">
                {#if expoIdentifiers.can_sync}<button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" disabled={expoSyncForm.processing} on:click={syncExpo}><RefreshCw class="h-4 w-4 {expoSyncForm.processing ? 'animate-spin' : ''}" strokeWidth={2.2} /> {expoSyncForm.processing ? 'Syncing…' : 'Sync'}</button>{/if}
                <button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateIdentifier}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add</button>
            </div>
        </div>
        {#if expoIdentifiers.linked}
            <div class="flex flex-wrap items-center gap-2 border-b border-border-subtle bg-surface-sunken px-5 py-3 text-[11px]">
                {#if expoIdentifiers.comparison_available}
                    <span class="font-medium text-muted">Expo comparison:</span>
                    <span class="rounded-full bg-ok/10 px-2.5 py-1 font-semibold text-ok">{syncedCount} DB + Expo</span>
                    <span class="rounded-full bg-warn-text/10 px-2.5 py-1 font-semibold text-warn-fill">{databaseOnlyCount} DB only</span>
                    <span class="rounded-full bg-sky-50 px-2.5 py-1 font-semibold text-sky-700">{expoOnlyCount} Expo only</span>
                {:else}
                    <span class="font-medium text-muted">Expo comparison is currently unavailable.</span>
                {/if}
            </div>
        {/if}
        {#if expoIdentifiers.error || expoSyncForm.errors.expo}
            <div class="flex items-start gap-2 border-b border-danger/20 bg-danger/10 px-5 py-3 text-xs font-medium text-danger"><TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />{expoSyncForm.errors.expo || expoIdentifiers.error}</div>
        {/if}
        {#if identifiers.length}
            <div class="divide-y divide-border-subtle">
                {#each identifiers as identifier (identifier.key)}
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            {#if identifier.show_url}
                            <Link href={identifier.show_url} class="flex min-w-0 items-center gap-3 rounded-lg p-0 text-left shadow-none transition hover:opacity-80">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md {platformIconClass(identifier.platform)}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else if identifier.platform === 'android'}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{:else}<Globe2 class="h-5 w-5" strokeWidth={1.9} />{/if}</span>
                                <span class="min-w-0"><span class="flex flex-wrap items-center gap-2"><span class="truncate text-sm font-semibold">{identifier.name || platformName(identifier.platform)}</span><span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-muted uppercase">{identifier.platform}</span>{#if identifier.is_default}<span class="rounded bg-brand px-1.5 py-0.5 text-[9px] font-bold text-white">Default</span>{/if}{#if identifier.version || identifier.version_code || identifier.build_number}<span class="rounded bg-brand-soft px-1.5 py-0.5 text-[9px] font-bold text-brand">{identifier.version ? `v${identifier.version}` : ''}{identifier.version_code ? `${identifier.version ? ' · ' : ''}code ${identifier.version_code}` : ''}{identifier.build_number ? `${identifier.version ? ' · ' : ''}build ${identifier.build_number}` : ''}</span>{/if}{#if identifier.sync_status === 'synced'}<span class="rounded bg-ok/10 px-1.5 py-0.5 text-[9px] font-bold text-ok">DB + Expo</span>{:else if identifier.sync_status === 'database_only'}<span class="rounded bg-warn-text/10 px-1.5 py-0.5 text-[9px] font-bold text-warn-fill">DB only</span>{:else if identifier.sync_status === 'unknown'}<span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold text-muted">Expo unknown</span>{:else}<span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold text-muted">Database</span>{/if}</span><code class="mt-1 block truncate text-xs text-muted">{identifier.identifier}</code></span>
                            </Link>
                            {:else}
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md {platformIconClass(identifier.platform)}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else if identifier.platform === 'android'}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{:else}<Globe2 class="h-5 w-5" strokeWidth={1.9} />{/if}</span>
                                <span class="min-w-0"><span class="flex flex-wrap items-center gap-2"><span class="truncate text-sm font-semibold">{platformName(identifier.platform)}</span><span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-muted uppercase">{identifier.platform}</span><span class="rounded bg-sky-50 px-1.5 py-0.5 text-[9px] font-bold text-sky-700">Expo only</span></span><code class="mt-1 block truncate text-xs text-muted">{identifier.identifier}</code></span>
                            </div>
                            {/if}
                            <div class="flex shrink-0 items-center gap-2">
                                {#if identifier.sources.database && identifier.platform !== 'web'}<span class="inline-flex items-center gap-1.5 rounded-full bg-border-subtle px-2.5 py-1 text-[11px] font-semibold text-muted"><KeyRound class="h-3 w-3 text-faint" />{identifier.credentials_count}</span>{/if}
                                {#if identifier.sources.database}<div class="flex items-center gap-0.5 rounded-lg border border-border bg-surface-sunken p-0.5">
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-muted shadow-none hover:bg-surface-raised hover:text-brand hover:shadow-sm" title="Edit" aria-label="Edit identifier" on:click={() => openEditIdentifier(identifier)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-danger shadow-none hover:bg-surface-raised hover:text-danger hover:shadow-sm" title="Delete" aria-label="Delete identifier" on:click={() => confirmingIdentifierDelete = identifier.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                </div>{/if}
                            </div>
                        </div>
                        {#if identifier.id && confirmingIdentifierDelete === identifier.id}
                            <div class="mt-3 ml-12 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-danger/10 px-3 py-2">
                                <span class="text-[11px] font-medium text-danger">{identifier.credentials_count > 0 ? `Delete this identifier? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete this identifier?'}</span>
                                <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingIdentifierDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => deleteIdentifier(identifier)}>Delete</button></span>
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        {:else}
            <div class="px-6 py-12 text-center"><p class="text-sm font-semibold">No identifiers yet</p><p class="mt-1 text-xs text-muted">Add an iOS bundle ID, Android package name, or Web domain.</p><button class="mt-4 px-3 py-2 text-xs" on:click={openCreateIdentifier}>Add identifier</button></div>
        {/if}
    </section>
</AppLayout>

<IdentifierFormModal {app} editing={editingIdentifier} open={showIdentifier} onClose={closeIdentifier} />
