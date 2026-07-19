<script>
    import { Link, router } from '@inertiajs/svelte';
    import { Pencil, Trash2, Plus, KeyRound } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import IdentifierFormModal from '../Modals/IdentifierFormModal.svelte';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'identifiers';
    export let credentialTypes = [];

    let showIdentifier = false;
    let editingIdentifier = null;
    let confirmingIdentifierDelete = null;

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
</script>

<svelte:head><title>Identifiers · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} {navigationTeams} {navigationTeam} {active}>
    <section class="card p-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-sm font-bold">App identifiers</h2>
                <p class="mt-0.5 text-xs text-slate-500">Bundle IDs and package names for each platform.</p>
            </div>
            <button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateIdentifier}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add</button>
        </div>
        {#if identifiers.length}
            <div class="divide-y divide-slate-100">
                {#each identifiers as identifier (identifier.id)}
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <Link href={identifier.show_url} class="flex min-w-0 items-center gap-3 rounded-lg p-0 text-left shadow-none transition hover:opacity-80">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl {identifier.platform === 'ios' ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-700'}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{/if}</span>
                                <span class="min-w-0"><span class="flex items-center gap-2"><span class="truncate text-sm font-semibold">{identifier.name || (identifier.platform === 'ios' ? 'iOS application' : 'Android application')}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase">{identifier.platform}</span>{#if identifier.version || identifier.version_code}<span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600">{identifier.version ? `v${identifier.version}` : ''}{identifier.version_code ? `${identifier.version ? ' · ' : ''}code ${identifier.version_code}` : ''}</span>{/if}</span><code class="mt-1 block truncate text-xs text-slate-500">{identifier.identifier}</code></span>
                            </Link>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600"><KeyRound class="h-3 w-3 text-slate-400" />{identifier.credentials_count}</span>
                                <div class="flex items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50/60 p-0.5">
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Edit" aria-label="Edit identifier" on:click={() => openEditIdentifier(identifier)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-rose-500 shadow-none hover:bg-white hover:text-rose-600 hover:shadow-sm" title="Delete" aria-label="Delete identifier" on:click={() => confirmingIdentifierDelete = identifier.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                </div>
                            </div>
                        </div>
                        {#if confirmingIdentifierDelete === identifier.id}
                            <div class="mt-3 ml-12 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rose-50 px-3 py-2">
                                <span class="text-[11px] font-medium text-rose-700">{identifier.credentials_count > 0 ? `Delete this identifier? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete this identifier?'}</span>
                                <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingIdentifierDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => deleteIdentifier(identifier)}>Delete</button></span>
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        {:else}
            <div class="px-6 py-12 text-center"><p class="text-sm font-semibold">No identifiers yet</p><p class="mt-1 text-xs text-slate-500">Add an iOS bundle ID or Android package name.</p><button class="mt-4 px-3 py-2 text-xs" on:click={openCreateIdentifier}>Add identifier</button></div>
        {/if}
    </section>
</AppLayout>

<IdentifierFormModal {app} editing={editingIdentifier} open={showIdentifier} onClose={closeIdentifier} />
