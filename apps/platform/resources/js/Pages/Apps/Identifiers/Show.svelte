<script>
    import { onDestroy } from 'svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Pencil, Trash2, Plus, KeyRound, Clock, Check, TriangleAlert, Download, Upload, CloudUpload, Unlink, Eye, EyeOff, Copy, LockKeyhole, X, Globe2 } from '@lucide/svelte';
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
    export let availableCredentials = [];
    export let checkUniquenessByDefault = true;
    export let vaultProviders = [];
    export let allowedStorageModes = ['local'];
    export let identifier;

    let showIdentifier = false;
    let confirmingDelete = false;
    let deleting = false;

    let showCredential = false;
    let editingCredential = null;
    let confirmingCredentialDelete = null;
    let pushingCredential = null;
    let expoCredentialError = null;
    const expoCredentialForm = useForm({});
    let revealTarget = null;
    let revealPassword = '';
    let revealError = '';
    let revealLoading = false;
    let revealedCredentialId = null;
    let revealedFields = [];
    let revealTimer;
    let copiedField = null;

    $: canRevealCredentials = auth?.team?.permissions?.manage_credentials ?? false;

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
    const detachCredential = (credential) => router.delete(credential.detach_url, {
        preserveScroll: true,
        onFinish: () => confirmingCredentialDelete = null,
    });
    const pushCredentialToExpo = (credential) => {
        if (credential.expo.pushed && !confirm(
            'Create and attach a new Expo credential from the current local file? '
            + 'The previous credential remains in your Expo account.',
        )) return;

        pushingCredential = credential.id;
        expoCredentialError = null;
        expoCredentialForm.post(credential.expo.push_url, {
            preserveScroll: true,
            onError: (errors) => {
                expoCredentialError = {
                    credentialId: credential.id,
                    message: errors.expo || 'Expo could not upload this credential.',
                };
            },
            onSuccess: () => expoCredentialError = null,
            onFinish: () => pushingCredential = null,
        });
    };
    const hideSensitiveFields = () => {
        clearTimeout(revealTimer);
        revealedCredentialId = null;
        revealedFields = [];
        copiedField = null;
    };
    const openReveal = (credential) => {
        revealTarget = credential;
        revealPassword = '';
        revealError = '';
    };
    const closeReveal = () => {
        if (revealLoading) return;
        revealTarget = null;
        revealPassword = '';
        revealError = '';
    };
    const revealCredential = async () => {
        if (!revealTarget?.reveal_url) return;

        revealLoading = true;
        revealError = '';

        try {
            const response = await fetch(revealTarget.reveal_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ password: revealPassword, scope: 'all' }),
            });
            const payload = await response.json();

            if (!response.ok) {
                revealError = payload?.errors?.password?.[0]
                    ?? payload?.message
                    ?? 'Unable to reveal credential details. Please try again.';
                return;
            }

            hideSensitiveFields();
            revealedCredentialId = revealTarget.id;
            revealedFields = payload.secrets ?? [];
            revealTarget = null;
            revealPassword = '';
            revealTimer = setTimeout(hideSensitiveFields, 60_000);
        } catch {
            revealError = 'Unable to reveal credential details. Please try again.';
        } finally {
            revealLoading = false;
        }
    };
    const copyField = async (field) => {
        await navigator.clipboard.writeText(field.value);
        copiedField = field.name;
        window.setTimeout(() => {
            if (copiedField === field.name) copiedField = null;
        }, 1800);
    };

    const schemaFor = (type) => credentialTypes.find((definition) => definition.value === type);
    const typeLabel = (type) => schemaFor(type)?.label ?? type.replaceAll('_', ' ');
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

    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]));
        push(app.tags);
        (identifier.services || []).forEach((credential) => push(credential.tags));
        (identifier.build_targets || []).forEach((target) => (target.credentials || []).forEach((credential) => push(credential.tags)));
        return acc;
    })();

    onDestroy(() => clearTimeout(revealTimer));
</script>

<svelte:head><title>{identifier.name || identifier.identifier} · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="card mb-6 flex flex-wrap items-start justify-between gap-5">
        <div class="flex min-w-0 items-start gap-4">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md {platformIconClass(identifier.platform)}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else if identifier.platform === 'android'}<svg viewBox="0 0 24 24" class="h-5.5 w-5.5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{:else}<Globe2 class="h-6 w-6" strokeWidth={1.9} />{/if}</span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-bold tracking-tight">{identifier.name || platformName(identifier.platform)}</h1>
                    <span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-muted uppercase">{identifier.platform}</span>
                    {#if identifier.version || identifier.version_code}<span class="rounded bg-brand-soft px-1.5 py-0.5 text-[9px] font-bold text-brand">{identifier.version ? `v${identifier.version}` : ''}{identifier.version_code ? `${identifier.version ? ' · ' : ''}code ${identifier.version_code}` : ''}</span>{/if}
                </div>
                <code class="mt-1 block truncate text-xs text-muted">{identifier.identifier}</code>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" class="secondary flex items-center gap-2" on:click={openEditIdentifier}><Pencil class="h-4 w-4" strokeWidth={2.2} /> Edit</button>
            <button type="button" class="danger flex items-center gap-2" on:click={() => confirmingDelete = true}><Trash2 class="h-4 w-4" strokeWidth={2.2} /> Delete</button>
        </div>
    </div>

    {#if confirmingDelete}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-danger/20 bg-danger/10 px-4 py-3">
            <span class="text-xs font-medium text-danger">{identifier.credentials_count > 0 ? `Delete this identifier? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete this identifier?'}</span>
            <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1.5 text-xs" on:click={() => confirmingDelete = false}>Cancel</button><button type="button" class="danger px-2.5 py-1.5 text-xs" disabled={deleting} on:click={deleteIdentifier}>Confirm delete</button></span>
        </div>
    {/if}

    {#snippet credentialRow(credential)}
        <div class="rounded-md border border-border bg-surface-raised px-3.5 py-3 transition hover:border-control">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-warn-text/10 text-warn-fill"><KeyRound class="h-4 w-4" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-text">{credential.name}</p>
                        <p class="mt-0.5 text-[11px] text-muted">{typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} encrypted field${credential.fields.length === 1 ? '' : 's'}` : ''}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {isExpiring(credential.expires_at) ? 'bg-danger/10 text-danger' : 'bg-ok/10 text-ok'}"><Clock class="h-3 w-3" />{formatDate(credential.expires_at)}</span>
                            {#if credential.file}<span class="inline-flex items-center gap-1 text-[11px] text-muted"><Check class="h-3 w-3 text-ok" />{credential.file.name} · {formatBytes(credential.file.size)}</span>{:else}<span class="inline-flex items-center gap-1 text-[11px] text-warn-fill"><TriangleAlert class="h-3 w-3" />No file uploaded</span>{/if}
                            {#if canRevealCredentials && credential.reveal_url && revealedCredentialId !== credential.id}
                                <button type="button" class="ghost inline-flex items-center gap-1 px-0 py-0.5 text-[11px] font-semibold text-brand hover:bg-transparent hover:text-brand" on:click={() => openReveal(credential)}>
                                    <Eye class="h-3.5 w-3.5" />
                                    Show sensitive info
                                </button>
                            {/if}
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-0.5 rounded-lg border border-border bg-surface-sunken p-0.5">
                    {#if credential.expo?.complete}<span class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-[10px] font-semibold text-ok" title={credential.expo.remote_resource_id}><Check class="h-3.5 w-3.5" /> Expo</span>{:else if credential.expo?.pushed}<span class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-[10px] font-semibold text-warn-fill" title="Uploaded to Expo, but not attached to a complete iOS build credential set"><TriangleAlert class="h-3.5 w-3.5" /> Expo incomplete</span>{/if}
                    {#if credential.expo?.available}<button type="button" class="inline-flex h-7 items-center gap-1 rounded-md bg-transparent px-2 text-[10px] font-semibold text-brand shadow-none hover:bg-surface-raised" disabled={pushingCredential === credential.id} title={credential.expo.pushed ? 'Sync the current local credential to Expo' : 'Push this credential to Expo'} on:click={() => pushCredentialToExpo(credential)}><CloudUpload class="h-3.5 w-3.5" /> {pushingCredential === credential.id ? 'Syncing…' : (credential.expo.pushed ? 'Sync' : 'Expo')}</button>{/if}
                    {#if credential.file}<a href={credential.file.download_url} class="grid h-7 w-7 place-items-center rounded-md bg-transparent text-muted shadow-none hover:bg-surface-raised hover:text-brand hover:shadow-sm" title="Download" aria-label="Download file"><Download class="h-4 w-4" strokeWidth={1.9} /></a>{/if}
                    <label for={`credential-${credential.id}`} class="grid h-7 w-7 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-raised hover:text-brand hover:shadow-sm" title={credential.file ? 'Replace file' : 'Upload file'} aria-label={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                    <input id={`credential-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-muted shadow-none hover:bg-surface-raised hover:text-brand hover:shadow-sm" title="Edit" aria-label="Edit credential" on:click={() => openEditCredential(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                    <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-danger shadow-none hover:bg-surface-raised hover:text-danger hover:shadow-sm" title="Detach from this target" aria-label="Detach credential" on:click={() => confirmingCredentialDelete = credential.id}><Unlink class="h-4 w-4" strokeWidth={1.9} /></button>
                </div>
            </div>
            {#if expoCredentialError?.credentialId === credential.id}
                <div class="mt-2.5 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2 text-[11px] font-medium leading-4 text-danger"><TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />{expoCredentialError.message}</div>
            {/if}
            {#if confirmingCredentialDelete === credential.id}
                <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-danger/10 px-3 py-2">
                    <span class="text-[11px] font-medium text-danger">Detach from this {credential.profile ? 'build target' : 'identifier'}? The credential stays in Credentials Vault and remains available everywhere else.</span>
                    <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingCredentialDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => detachCredential(credential)}>Detach</button></span>
                </div>
            {/if}
            {#if revealedCredentialId === credential.id}
                <div class="mt-2.5 rounded-md border border-brand-soft-line bg-brand-soft/70 p-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="flex items-start gap-2">
                            <LockKeyhole class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand" />
                            <div>
                                <p class="text-[11px] font-bold text-text">Sensitive information revealed</p>
                                <p class="mt-0.5 text-[10px] text-brand">It will be hidden automatically after 60 seconds.</p>
                            </div>
                        </div>
                        <button type="button" class="ghost inline-flex items-center gap-1 px-2 py-1 text-[10px] text-brand hover:bg-brand-soft-line" on:click={hideSensitiveFields}><EyeOff class="h-3.5 w-3.5" /> Hide</button>
                    </div>
                    <div class="mt-2.5 grid gap-2 sm:grid-cols-2">
                        {#each revealedFields as field (field.name)}
                            <div class="rounded-lg border border-brand-soft-line bg-surface-raised p-2.5 shadow-sm">
                                <p class="text-[9px] font-semibold tracking-wider text-faint uppercase">{field.label}</p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <code class="min-w-0 flex-1 overflow-x-auto rounded-md bg-surface-sunken px-2.5 py-1.5 font-mono text-[11px] text-text">{field.value}</code>
                                    <button type="button" class="secondary grid h-7 w-7 shrink-0 place-items-center p-0" title={`Copy ${field.label}`} aria-label={`Copy ${field.label}`} on:click={() => copyField(field)}>
                                        {#if copiedField === field.name}<Check class="h-3.5 w-3.5 text-ok" />{:else}<Copy class="h-3.5 w-3.5" />{/if}
                                    </button>
                                </div>
                            </div>
                        {/each}
                    </div>
                </div>
            {/if}
            <div class="mt-2.5 flex items-center gap-2 border-t border-border-subtle pt-2.5">
                <span class="text-[10px] font-semibold tracking-wider text-faint uppercase">Tags</span>
                <TagEditor tags={credential.tags} url={credential.tags_url} suggestions={allTags} />
            </div>
        </div>
    {/snippet}

    {#if identifier.supports_credentials}
    <section class="card p-0">
        <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
            <div><h2 class="text-sm font-bold">Credentials</h2><p class="mt-0.5 text-xs text-muted">Signing keys, service accounts, and platform configuration for this identifier.</p></div>
            <button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateCredential}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add credential</button>
        </div>
        <div class="space-y-4 p-5">
            {#if expoCredentialForm.errors.expo}<div class="rounded-md bg-danger/10 px-4 py-3 text-xs font-medium text-danger">{expoCredentialForm.errors.expo}</div>{/if}
            {#if identifier.services.length}
                <div>
                    <p class="mb-2 text-[10px] font-bold tracking-wider text-faint uppercase">Services</p>
                    <div class="space-y-2">{#each identifier.services as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                </div>
            {/if}
            {#each identifier.build_targets as target (target.type)}
                <div>
                    <p class="mb-2 flex items-center gap-2 text-[10px] font-bold tracking-wider text-faint uppercase">Build target · {target.label}{#if target.artifact}<span class="font-mono text-muted normal-case">{target.artifact}</span>{/if}</p>
                    <div class="space-y-2">{#each target.credentials as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                </div>
            {/each}
            {#if !identifier.services.length && !identifier.build_targets.length}
                <p class="text-xs text-muted">No credentials attached yet. Add a signing key, service account, or platform configuration.</p>
            {/if}
        </div>
    </section>
    {:else}
        <section class="card flex items-start gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-sky-50 text-sky-700"><Globe2 class="h-4.5 w-4.5" strokeWidth={1.9} /></span>
            <div>
                <h2 class="text-sm font-bold">Web domain</h2>
                <p class="mt-1 text-xs leading-5 text-muted">Web identifiers use their domain as the platform identity and do not require signing or service credentials.</p>
            </div>
        </section>
    {/if}
</AppLayout>

{#if revealTarget}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeReveal()}>
        <form class="modal" on:submit|preventDefault={revealCredential}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-brand-soft text-brand"><LockKeyhole class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-lg font-bold">Show sensitive information</h2>
                        <p class="mt-1 text-sm text-muted">Confirm your account password to reveal {revealTarget.name}.</p>
                    </div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" disabled={revealLoading} on:click={closeReveal} aria-label="Close"><X class="h-4 w-4" /></button>
            </div>
            <div class="space-y-4 p-6">
                <div class="rounded-md border border-warn-fill/20 bg-warn-text/10 px-4 py-3 text-xs leading-5 text-warn-fill">Passwords, Team ID, Issuer ID, Key ID, and other configured fields are decrypted only for this request and hidden again after 60 seconds.</div>
                <label>
                    <span class="label">Your account password</span>
                    <input type="password" bind:value={revealPassword} autocomplete="current-password" required />
                    {#if revealError}<span class="error">{revealError}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-border bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" disabled={revealLoading} on:click={closeReveal}>Cancel</button>
                <button disabled={revealLoading || !revealPassword}>{revealLoading ? 'Verifying…' : 'Reveal information'}</button>
            </div>
        </form>
    </div>
{/if}

<IdentifierFormModal {app} editing={identifier} open={showIdentifier} onClose={closeIdentifier} />
{#if identifier.supports_credentials}
    <CredentialFormModal
        {app}
        identifiers={[identifier]}
        {credentialTypes}
        {availableCredentials}
        {checkUniquenessByDefault}
        {vaultProviders}
        {allowedStorageModes}
        reuseUrl={identifier.attach_credential_url}
        editing={editingCredential}
        open={showCredential}
        onClose={closeCredential}
        onSaved={() => router.reload({ preserveScroll: true })}
    />
{/if}
