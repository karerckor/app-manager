<script>
    import { router } from '@inertiajs/svelte';
    import { Pencil, Trash2, Plus, KeyRound, Clock, Check, TriangleAlert, Download, Upload } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import TagEditor from '../../../Components/TagEditor.svelte';
    import IdentifierFormModal from '../Modals/IdentifierFormModal.svelte';
    import CredentialFormModal from '../Modals/CredentialFormModal.svelte';
    import { formatDate, formatBytes, isExpiring } from '../lib.js';

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
    export let credentialTypes = [];
    export let identifier;

    let showIdentifier = false;
    let confirmingDelete = false;
    let deleting = false;

    let showCredential = false;
    let editingCredential = null;
    let confirmingCredentialDelete = null;

    const openEditIdentifier = () => showIdentifier = true;
    const closeIdentifier = () => showIdentifier = false;
    const deleteIdentifier = () => router.delete(identifier.delete_url, {
        onStart: () => deleting = true,
        onSuccess: () => router.visit(app.urls.identifiers_page),
        onFinish: () => { deleting = false; confirmingDelete = false; },
    });

    const openCreateCredential = () => {
        editingCredential = null;
        showCredential = true;
    };
    const openEditCredential = (credential) => {
        editingCredential = credential;
        showCredential = true;
    };
    const closeCredential = () => {
        showCredential = false;
        editingCredential = null;
    };
    const uploadCredential = (credential, event) => {
        const file = event.currentTarget.files?.[0];
        if (file) router.post(credential.upload_url, { file }, { forceFormData: true, preserveScroll: true });
        event.currentTarget.value = '';
    };
    const deleteCredential = (credential) => router.delete(credential.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingCredentialDelete = null,
    });

    const schemaFor = (type) => credentialTypes.find((definition) => definition.value === type);
    const typeLabel = (type) => schemaFor(type)?.label ?? type.replaceAll('_', ' ');

    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
        push(app.tags);
        (identifier.services || []).forEach((credential) => push(credential.tags));
        (identifier.build_targets || []).forEach((target) => (target.credentials || []).forEach((credential) => push(credential.tags)));
        return acc;
    })();
</script>

<svelte:head><title>{identifier.name || identifier.identifier} · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="card mb-6 flex flex-wrap items-start justify-between gap-5">
        <div class="flex min-w-0 items-start gap-4">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl {identifier.platform === 'ios' ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-700'}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else}<svg viewBox="0 0 24 24" class="h-5.5 w-5.5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{/if}</span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-bold tracking-tight">{identifier.name || (identifier.platform === 'ios' ? 'iOS application' : 'Android application')}</h1>
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase">{identifier.platform}</span>
                    {#if identifier.version || identifier.version_code}<span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600">{identifier.version ? `v${identifier.version}` : ''}{identifier.version_code ? `${identifier.version ? ' · ' : ''}code ${identifier.version_code}` : ''}</span>{/if}
                </div>
                <code class="mt-1 block truncate text-xs text-slate-500">{identifier.identifier}</code>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" class="secondary flex items-center gap-2" on:click={openEditIdentifier}><Pencil class="h-4 w-4" strokeWidth={2.2} /> Edit</button>
            <button type="button" class="danger flex items-center gap-2" on:click={() => confirmingDelete = true}><Trash2 class="h-4 w-4" strokeWidth={2.2} /> Delete</button>
        </div>
    </div>

    {#if confirmingDelete}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
            <span class="text-xs font-medium text-rose-700">{identifier.credentials_count > 0 ? `Delete this identifier? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete this identifier?'}</span>
            <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1.5 text-xs" on:click={() => confirmingDelete = false}>Cancel</button><button type="button" class="danger px-2.5 py-1.5 text-xs" disabled={deleting} on:click={deleteIdentifier}>Confirm delete</button></span>
        </div>
    {/if}

    {#snippet credentialRow(credential)}
        <div class="rounded-xl border border-slate-200 bg-white px-3.5 py-3 transition hover:border-slate-300">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600"><KeyRound class="h-4 w-4" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{credential.name}</p>
                        <p class="mt-0.5 text-[11px] text-slate-500">{typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} encrypted field${credential.fields.length === 1 ? '' : 's'}` : ''}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {isExpiring(credential.expires_at) ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'}"><Clock class="h-3 w-3" />{formatDate(credential.expires_at)}</span>
                            {#if credential.file}<span class="inline-flex items-center gap-1 text-[11px] text-slate-500"><Check class="h-3 w-3 text-emerald-500" />{credential.file.name} · {formatBytes(credential.file.size)}</span>{:else}<span class="inline-flex items-center gap-1 text-[11px] text-amber-600"><TriangleAlert class="h-3 w-3" />No file uploaded</span>{/if}
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50/60 p-0.5">
                    {#if credential.file}<a href={credential.file.download_url} class="grid h-7 w-7 place-items-center rounded-md bg-transparent text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Download" aria-label="Download file"><Download class="h-4 w-4" strokeWidth={1.9} /></a>{/if}
                    <label for={`credential-${credential.id}`} class="grid h-7 w-7 cursor-pointer place-items-center rounded-md text-slate-500 hover:bg-white hover:text-indigo-600 hover:shadow-sm" title={credential.file ? 'Replace file' : 'Upload file'} aria-label={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                    <input id={`credential-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Edit" aria-label="Edit credential" on:click={() => openEditCredential(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-rose-500 shadow-none hover:bg-white hover:text-rose-600 hover:shadow-sm" title="Delete" aria-label="Delete credential" on:click={() => confirmingCredentialDelete = credential.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                </div>
            </div>
            {#if confirmingCredentialDelete === credential.id}
                <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rose-50 px-3 py-2">
                    <span class="text-[11px] font-medium text-rose-700">{credential.attached_elsewhere ? 'Shared with other apps — this removes it everywhere.' : 'Delete this credential permanently?'}</span>
                    <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingCredentialDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => deleteCredential(credential)}>Delete</button></span>
                </div>
            {/if}
            <div class="mt-2.5 flex items-center gap-2 border-t border-slate-100 pt-2.5">
                <span class="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">Tags</span>
                <TagEditor tags={credential.tags} url={credential.tags_url} suggestions={allTags} />
            </div>
        </div>
    {/snippet}

    <section class="card p-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div><h2 class="text-sm font-bold">Credentials</h2><p class="mt-0.5 text-xs text-slate-500">Signing keys, service accounts, and platform configuration for this identifier.</p></div>
            <button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateCredential}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add credential</button>
        </div>
        <div class="space-y-4 p-5">
            {#if identifier.services.length}
                <div>
                    <p class="mb-2 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Services</p>
                    <div class="space-y-2">{#each identifier.services as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                </div>
            {/if}
            {#each identifier.build_targets as target (target.type)}
                <div>
                    <p class="mb-2 flex items-center gap-2 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Build target · {target.label}{#if target.artifact}<span class="font-mono text-slate-300 normal-case">{target.artifact}</span>{/if}</p>
                    <div class="space-y-2">{#each target.credentials as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                </div>
            {/each}
            {#if !identifier.services.length && !identifier.build_targets.length}
                <p class="text-xs text-slate-500">No credentials attached yet. Add a signing key, service account, or platform configuration.</p>
            {/if}
        </div>
    </section>
</AppLayout>

<IdentifierFormModal {app} editing={identifier} open={showIdentifier} onClose={closeIdentifier} />
<CredentialFormModal {app} identifiers={[identifier]} {credentialTypes} {buildProfiles} editing={editingCredential} open={showCredential} onClose={closeCredential} onSaved={() => router.reload({ preserveScroll: true })} />
