<script>
    import { onDestroy } from 'svelte';
    import { Link, router } from '@inertiajs/svelte';
    import {
        Check,
        CheckCircle2,
        Clock3,
        Copy,
        Download,
        Eye,
        EyeOff,
        KeyRound,
        LockKeyhole,
        Pencil,
        Plus,
        Search,
        ShieldCheck,
        Trash2,
        Upload,
        X,
    } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';
    import TagEditor from '../../Components/TagEditor.svelte';
    import VaultCredentialFormModal from './VaultCredentialFormModal.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let credentials = { data: [], links: [], total: 0 };
    export let credentialTypes = [];
    export let apps = [];
    export let canManage = false;
    export let checkUniquenessByDefault = true;
    export let filters = {};
    export let stats = { total: 0, expiring: 0, expired: 0, missing_files: 0, unattached: 0, never: 0 };
    export let tagSuggestions = [];

    let query = filters.search ?? '';
    let type = filters.type ?? '';
    let status = filters.status ?? '';
    let app = filters.app ?? '';
    let sort = filters.sort ?? 'updated';
    let searchTimer;
    let showCredential = false;
    let editingCredential = null;
    let confirmingDelete = null;
    let revealTarget = null;
    let revealPassword = '';
    let revealError = '';
    let revealLoading = false;
    let revealedCredentialId = null;
    let revealedSecrets = [];
    let revealTimer;
    let copiedSecret = null;

    $: credentialItems = credentials?.data ?? [];
    $: pageLinks = (credentials?.links ?? []).slice(1, -1);
    $: paginationKey = (credentials?.links ?? [])
        .map((link) => link.url ?? link.label)
        .join('|');
    $: hasConfiguredApps = apps.some((item) => item.identifiers.length);
    $: hasFilters = Boolean(query || type || status || app || sort !== 'updated');
    $: iosCredentialTypes = credentialTypes.filter((definition) => definition.platforms.includes('ios'));
    $: androidCredentialTypes = credentialTypes.filter((definition) => definition.platforms.includes('android'));
    $: statusChips = [
        { value: '', label: 'All', count: stats.total, tone: 'neutral' },
        { value: 'expired', label: 'Expired', count: stats.expired, tone: 'rose' },
        { value: 'expiring', label: 'Expiring', count: stats.expiring, tone: 'amber' },
        { value: 'unattached', label: 'Unattached', count: stats.unattached, tone: 'amber' },
        { value: 'missing_file', label: 'No file', count: stats.missing_files, tone: 'amber' },
        { value: 'never', label: 'No expiry', count: stats.never, tone: 'neutral' },
    ];

    const schemaFor = (value) => credentialTypes.find((definition) => definition.value === value);
    const typeLabel = (value) => schemaFor(value)?.label ?? value.replaceAll('_', ' ');
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value))
        : 'No expiration';
    const formatBytes = (bytes) => bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    const expirationCopy = (credential) => {
        const { status: expirationStatus, days } = credential.expiration;
        if (expirationStatus === 'never') return 'No expiration';
        if (expirationStatus === 'expired') return `Expired ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} ago`;
        if (expirationStatus === 'expiring') {
            if (days === 0) return 'Expires today';
            if (days === 1) return 'Expires tomorrow';
            return `Expires in ${days} days`;
        }
        return formatDate(credential.expires_at);
    };
    const expirationClass = (value) => ({
        expired: 'border-rose-200 bg-rose-50 text-rose-700',
        expiring: 'border-amber-200 bg-amber-50 text-amber-700',
        valid: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        never: 'border-slate-200 bg-slate-50 text-slate-600',
    }[value] ?? 'border-slate-200 bg-slate-50 text-slate-600');
    const typeClass = (value) => {
        if (['ios_certificate', 'provisioning_profile', 'apns_key', 'apple_api_key'].includes(value)) {
            return 'bg-slate-900 text-white';
        }
        if (value === 'android_keystore') return 'bg-emerald-50 text-emerald-700';
        return 'bg-amber-50 text-amber-700';
    };
    const visitFilters = () => router.get(credentials.path, {
        search: query.trim() || undefined,
        type: type || undefined,
        status: status || undefined,
        app: app || undefined,
        sort: sort === 'updated' ? undefined : sort,
    }, {
        only: ['credentials', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    const scheduleSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(visitFilters, 350);
    };
    const clearFilters = () => {
        query = '';
        type = '';
        status = '';
        app = '';
        sort = 'updated';
        visitFilters();
    };
    const chipDisabled = (chip) => Boolean(chip.value) && !chip.count;
    const chipClass = (chip) => {
        if (status === chip.value) return 'border-indigo-200 bg-indigo-50 text-indigo-700';
        if (chipDisabled(chip)) return 'border-slate-100 bg-slate-50/60 text-slate-300';
        if (chip.tone === 'rose') return 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300';
        if (chip.tone === 'amber') return 'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300';
        return 'border-slate-200 bg-white text-slate-600 hover:border-slate-300';
    };
    const applyStatus = (value) => {
        status = status === value ? '' : value;
        visitFilters();
    };
    const openCreate = () => {
        editingCredential = null;
        showCredential = true;
    };
    const openEdit = (credential) => {
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
        onFinish: () => confirmingDelete = null,
    });
    const hideSecrets = () => {
        clearTimeout(revealTimer);
        revealedCredentialId = null;
        revealedSecrets = [];
        copiedSecret = null;
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
                body: JSON.stringify({ password: revealPassword }),
            });
            const payload = await response.json();

            if (!response.ok) {
                revealError = payload?.errors?.password?.[0] ?? 'Unable to reveal passwords. Please try again.';
                return;
            }

            hideSecrets();
            revealedCredentialId = revealTarget.id;
            revealedSecrets = payload.secrets ?? [];
            revealTarget = null;
            revealPassword = '';
            revealTimer = setTimeout(hideSecrets, 60_000);
        } catch {
            revealError = 'Unable to reveal passwords. Please try again.';
        } finally {
            revealLoading = false;
        }
    };
    const copySecret = async (secret) => {
        await navigator.clipboard.writeText(secret.value);
        copiedSecret = secret.name;
        window.setTimeout(() => {
            if (copiedSecret === secret.name) copiedSecret = null;
        }, 1800);
    };

    onDestroy(() => {
        clearTimeout(searchTimer);
        clearTimeout(revealTimer);
    });
</script>

<svelte:head><title>Credentials Vault · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="credentials">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-1.5">Workspace security</p>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-950">Credentials Vault</h1>
            </div>
            {#if canManage}
                <button class="flex items-center gap-2" disabled={!hasConfiguredApps} title={hasConfiguredApps ? 'Add credential' : 'Create an application identifier first'} on:click={openCreate}>
                    <Plus class="h-4 w-4" strokeWidth={2.2} />
                    Add credential
                </button>
            {:else}
                <span class="text-xs font-semibold text-slate-500">Read-only access</span>
            {/if}
        </div>

        {#if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>
                {flash.status}
            </div>
        {/if}

        <div class="mb-4 flex flex-wrap items-center gap-2">
            {#each statusChips as chip (chip.value)}
                <button type="button" class="chip {chipClass(chip)}" disabled={chipDisabled(chip)} aria-pressed={status === chip.value} on:click={() => applyStatus(chip.value)}>
                    {chip.label}
                    <span class="font-mono text-[10px] font-medium tabular-nums opacity-70">{chip.count}</span>
                </button>
            {/each}
        </div>

        <div class="mb-4 grid gap-2 md:grid-cols-[minmax(220px,1fr)_190px_180px_170px_auto]">
            <label class="relative">
                <span class="sr-only">Search credentials</span>
                <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input class="py-2 pl-9" type="search" placeholder="Search credentials, apps, identifiers…" bind:value={query} on:input={scheduleSearch} />
            </label>
            <label>
                <span class="sr-only">Credential type</span>
                <select class="py-2" bind:value={type} on:change={visitFilters}>
                    <option value="">All credential types</option>
                    <optgroup label="iOS">
                        {#each iosCredentialTypes as definition}<option value={definition.value}>{definition.label}</option>{/each}
                    </optgroup>
                    <optgroup label="Android">
                        {#each androidCredentialTypes as definition}<option value={definition.value}>{definition.label}</option>{/each}
                    </optgroup>
                </select>
            </label>
            <label>
                <span class="sr-only">Application</span>
                <select class="py-2" bind:value={app} on:change={visitFilters}>
                    <option value="">All applications</option>
                    {#each apps as item}<option value={item.id}>{item.name}</option>{/each}
                </select>
            </label>
            <label>
                <span class="sr-only">Sort order</span>
                <select class="py-2" bind:value={sort} on:change={visitFilters}>
                    <option value="updated">Recently updated</option>
                    <option value="expiry">Soonest expiry</option>
                </select>
            </label>
            {#if hasFilters}<button class="ghost px-3 py-2 text-xs" on:click={clearFilters}>Clear</button>{/if}
        </div>

        <section class="card overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 class="text-sm font-bold">Team credentials</h2>
                    <p class="mt-1 text-xs text-slate-500">{credentials.total ?? 0} {hasFilters ? 'matching ' : ''}credential{credentials.total === 1 ? '' : 's'} in {navigationTeam?.name}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700"><CheckCircle2 class="h-3.5 w-3.5" />Encrypted at rest</span>
            </div>

            {#if credentialItems.length}
                <div class="divide-y divide-slate-100">
                    {#each credentialItems as credential (credential.id)}
                        <article class="px-5 py-5 transition hover:bg-slate-50/60">
                            <div class="grid gap-4 xl:grid-cols-[minmax(260px,1.25fr)_minmax(250px,1fr)_190px_auto] xl:items-start">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl {typeClass(credential.type)}">
                                        <KeyRound class="h-5 w-5" strokeWidth={1.8} />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="truncate text-sm font-semibold text-slate-900">{credential.name}</h3>
                                            {#if credential.attachments.length > 1}<span class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700">Shared</span>{/if}
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">{typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} secure field${credential.fields.length === 1 ? '' : 's'}` : ''}</p>
                                        <div class="mt-2 min-h-5">
                                            <TagEditor tags={credential.tags} url={canManage ? credential.tags_url : null} disabled={!canManage} suggestions={tagSuggestions} emptyLabel="No tags" />
                                        </div>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="mb-2 text-[10px] font-semibold tracking-wider text-slate-400 uppercase">Used by</p>
                                    {#if credential.attachments.length}
                                        <div class="flex flex-wrap gap-1.5">
                                            {#each credential.attachments.slice(0, 2) as attachment}
                                                <Link href={attachment.url} class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-medium text-slate-600 shadow-sm transition hover:border-indigo-200 hover:text-indigo-700">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {attachment.platform === 'ios' ? 'bg-slate-900' : 'bg-emerald-500'}"></span>
                                                    <span class="truncate">{attachment.app_name}</span>
                                                    <span class="text-slate-300">/</span>
                                                    <code class="truncate text-[10px]">{attachment.identifier}</code>
                                                    {#if attachment.profile}<span class="rounded bg-indigo-50 px-1 py-0.5 text-[9px] font-bold text-indigo-600">{attachment.profile.label}</span>{/if}
                                                </Link>
                                            {/each}
                                            {#if credential.attachments.length > 2}<span class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] font-semibold text-slate-500">+{credential.attachments.length - 2} more</span>{/if}
                                        </div>
                                    {:else}
                                        <span class="text-xs font-medium text-amber-600">Not attached to an application</span>
                                    {/if}
                                </div>

                                <div>
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold {expirationClass(credential.expiration.status)}">
                                        <Clock3 class="h-3.5 w-3.5" />
                                        {expirationCopy(credential)}
                                    </span>
                                    <p class="mt-2 truncate text-[11px] {credential.file ? 'text-slate-500' : 'font-medium text-amber-600'}">
                                        {credential.file ? `${credential.file.name} · ${formatBytes(credential.file.size)}` : 'No secure file uploaded'}
                                    </p>
                                    {#if credential.reveal_url && revealedCredentialId !== credential.id}
                                        <button type="button" class="ghost mt-1.5 inline-flex items-center gap-1.5 px-0 py-1 text-[11px] font-semibold text-indigo-600 hover:bg-transparent hover:text-indigo-700" on:click={() => openReveal(credential)}>
                                            <Eye class="h-3.5 w-3.5" />
                                            Show password{credential.password_fields_count === 1 ? '' : 's'}
                                        </button>
                                    {/if}
                                </div>

                                <div class="flex shrink-0 items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm xl:justify-self-end">
                                    {#if credential.file && canManage}
                                        <a href={credential.file.download_url} class="grid h-8 w-8 place-items-center rounded-lg text-slate-500 transition hover:bg-indigo-50 hover:text-indigo-600" title="Download file" aria-label={`Download ${credential.name}`}><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                                    {/if}
                                    {#if canManage}
                                        <label for={`vault-file-${credential.id}`} class="grid h-8 w-8 cursor-pointer place-items-center rounded-lg text-slate-500 transition hover:bg-indigo-50 hover:text-indigo-600" title={credential.file ? 'Replace file' : 'Upload file'} aria-label={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                                        <input id={`vault-file-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                                        <button type="button" class="grid h-8 w-8 place-items-center rounded-lg bg-transparent p-0 text-slate-500 shadow-none hover:bg-indigo-50 hover:text-indigo-600" title="Edit credential" aria-label={`Edit ${credential.name}`} on:click={() => openEdit(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                        <button type="button" class="grid h-8 w-8 place-items-center rounded-lg bg-transparent p-0 text-rose-500 shadow-none hover:bg-rose-50 hover:text-rose-600" title="Delete credential" aria-label={`Delete ${credential.name}`} on:click={() => confirmingDelete = credential.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                    {/if}
                                </div>
                            </div>

                            {#if revealedCredentialId === credential.id}
                                <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50/70 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="flex items-start gap-2.5">
                                            <LockKeyhole class="mt-0.5 h-4 w-4 shrink-0 text-indigo-600" />
                                            <div>
                                                <h4 class="text-xs font-bold text-indigo-950">Passwords revealed</h4>
                                                <p class="mt-0.5 text-[11px] text-indigo-700">They will be hidden automatically after 60 seconds.</p>
                                            </div>
                                        </div>
                                        <button type="button" class="ghost inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs text-indigo-700 hover:bg-indigo-100" on:click={hideSecrets}><EyeOff class="h-3.5 w-3.5" /> Hide</button>
                                    </div>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        {#each revealedSecrets as secret (secret.name)}
                                            <div class="rounded-xl border border-indigo-100 bg-white p-3 shadow-sm">
                                                <p class="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{secret.label}</p>
                                                <div class="mt-1.5 flex items-center gap-2">
                                                    <code class="min-w-0 flex-1 overflow-x-auto rounded-lg bg-slate-950 px-3 py-2 font-mono text-xs text-slate-100">{secret.value}</code>
                                                    <button type="button" class="secondary grid h-8 w-8 shrink-0 place-items-center p-0" title={`Copy ${secret.label}`} aria-label={`Copy ${secret.label}`} on:click={() => copySecret(secret)}>
                                                        {#if copiedSecret === secret.name}<Check class="h-4 w-4 text-emerald-600" />{:else}<Copy class="h-4 w-4" />{/if}
                                                    </button>
                                                </div>
                                            </div>
                                        {/each}
                                    </div>
                                </div>
                            {/if}

                            {#if confirmingDelete === credential.id}
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                                    <span class="text-xs font-medium text-rose-700">{credential.attachments.length > 1 ? `Delete this credential permanently? It is used by ${credential.attachments.length} targets.` : 'Delete this credential permanently?'}</span>
                                    <span class="flex gap-2">
                                        <button type="button" class="ghost px-2.5 py-1.5 text-xs" on:click={() => confirmingDelete = null}>Cancel</button>
                                        <button type="button" class="danger px-2.5 py-1.5 text-xs" on:click={() => deleteCredential(credential)}>Delete</button>
                                    </span>
                                </div>
                            {/if}
                        </article>
                    {/each}
                </div>

                {#if credentials.last_page > 1}
                    {#key paginationKey}
                        <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4" aria-label="Credential pagination">
                            <p class="text-xs text-slate-500">Showing {credentials.from}–{credentials.to} of {credentials.total}</p>
                            <div class="flex items-center gap-1.5">
                                {#if credentials.prev_page_url}<Link href={credentials.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                                {#each pageLinks as link}
                                    {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center text-xs {link.active ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-slate-400">{link.label}</span>{/if}
                                {/each}
                                {#if credentials.next_page_url}<Link href={credentials.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                            </div>
                        </nav>
                    {/key}
                {/if}
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><ShieldCheck class="h-6 w-6" strokeWidth={1.7} /></span>
                    <h3 class="text-sm font-bold">{hasFilters ? 'No matching credentials' : 'Your team vault is empty'}</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">{hasFilters ? 'Try a different search or clear the filters.' : 'Keep signing keys, certificates, Firebase files, and service accounts organized in one secure place.'}</p>
                    {#if hasFilters}<button class="secondary mt-5" on:click={clearFilters}>Clear filters</button>{:else if canManage && hasConfiguredApps}<button class="mt-5" on:click={openCreate}>Add first credential</button>{/if}
                </div>
            {/if}
        </section>
    </main>
</AppShell>

{#if revealTarget}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeReveal()}>
        <form class="modal" on:submit|preventDefault={revealCredential}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><LockKeyhole class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-lg font-bold">Show credential password{revealTarget.password_fields_count === 1 ? '' : 's'}</h2>
                        <p class="mt-1 text-sm text-slate-500">Confirm your account password to reveal {revealTarget.name}.</p>
                    </div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" disabled={revealLoading} on:click={closeReveal} aria-label="Close"><X class="h-4 w-4" /></button>
            </div>
            <div class="space-y-4 p-6">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800">Passwords are decrypted only for this request, are never added to page props, and will be hidden after 60 seconds.</div>
                <label>
                    <span class="label">Your account password</span>
                    <input type="password" bind:value={revealPassword} autocomplete="current-password" required />
                    {#if revealError}<span class="error">{revealError}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" disabled={revealLoading} on:click={closeReveal}>Cancel</button>
                <button disabled={revealLoading || !revealPassword}>{revealLoading ? 'Verifying…' : 'Reveal password'}</button>
            </div>
        </form>
    </div>
{/if}

<VaultCredentialFormModal
    {apps}
    {credentialTypes}
    {checkUniquenessByDefault}
    editing={editingCredential}
    open={showCredential}
    onClose={closeCredential}
    onSaved={() => {}}
/>
