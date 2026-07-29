<script>
    import { onDestroy } from 'svelte';
    import { Link, router } from '@inertiajs/svelte';
    import {
        BellRing,
        Braces,
        Check,
        CheckCircle2,
        ChevronDown,
        CircleUserRound,
        Clock3,
        Copy,
        Download,
        Eye,
        EyeOff,
        FileBadge,
        FileCode,
        Flame,
        KeyRound,
        LockKeyhole,
        Pencil,
        Plus,
        Search,
        ShieldCheck,
        Trash2,
        Upload,
        Vault,
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
    let expandedId = null;

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
    const formatRelative = (value) => {
        if (!value) return 'never';
        const days = Math.round((Date.now() - new Date(value).getTime()) / 86_400_000);
        if (days <= 0) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 30) return `${days} days ago`;
        return formatDate(value);
    };
    const TYPE_ICONS = {
        ios_certificate: ShieldCheck,
        provisioning_profile: FileBadge,
        apns_key: BellRing,
        apple_api_key: KeyRound,
        android_keystore: Vault,
        firebase_config: Flame,
        google_play_service_account: CircleUserRound,
        google_services_json: Braces,
        google_service_info_plist: FileCode,
    };
    const typeIcon = (value) => TYPE_ICONS[value] ?? KeyRound;

    const markPlatform = (credential) => {
        const platforms = [...new Set(credential.attachments.map((item) => item.platform))];
        if (platforms.length === 1) return platforms[0];
        if (platforms.length === 0) {
            const supported = schemaFor(credential.type)?.platforms ?? [];
            if (supported.length === 1) return supported[0];
        }
        return 'mixed';
    };
    const markClass = (credential) => ({
        ios: 'bg-slate-900 text-white',
        android: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    }[markPlatform(credential)] ?? 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200');

    const RUNWAY_DAYS = 90;
    const runwayWidth = ({ status: state, days }) => state === 'valid' || state === 'expiring'
        ? Math.min(100, Math.max(0, (days / RUNWAY_DAYS) * 100))
        : 0;
    const runwayFill = ({ status: state, days }) => {
        if (state === 'expiring') return 'bg-amber-500';
        return days >= RUNWAY_DAYS ? 'bg-slate-300' : 'bg-slate-400';
    };
    const validityLabel = ({ status: state, days }) => {
        if (state === 'never') return 'no expiry';
        if (state === 'expired') return `−${Math.abs(days)}d · expired`;
        if (days === 0) return 'expires today';
        if (days === 1) return 'expires tomorrow';
        return `+${days}d`;
    };
    const validityTone = (state) => ({
        expired: 'text-rose-700',
        expiring: 'text-amber-700',
    }[state] ?? 'text-slate-500');
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
            expandedId = revealTarget.id;
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
    const toggleExpanded = (credential) => {
        expandedId = expandedId === credential.id ? null : credential.id;
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

        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-slate-900">
                    Team credentials
                    <span class="ml-1 font-mono text-[11px] font-medium text-slate-400 tabular-nums">{credentials.total ?? 0}{hasFilters ? ' matching' : ''} in {navigationTeam?.name}</span>
                </h2>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700"><CheckCircle2 class="h-3.5 w-3.5" />Encrypted at rest</span>
            </div>

            {#if credentialItems.length}
                <div class="vault-head vault-grid sticky top-0 z-10 hidden border-y border-slate-100 bg-slate-50/80 px-5 py-2 backdrop-blur lg:grid">
                    <span>Credential</span>
                    <span>Used by</span>
                    <span>Validity</span>
                    <span class="sr-only">Actions</span>
                </div>
                <div class="divide-y divide-slate-100">
                    {#each credentialItems as credential (credential.id)}
                        <article class="group px-5 py-2.5 transition hover:bg-slate-50/60">
                            <div class="vault-grid">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg {markClass(credential)}">
                                        <svelte:component this={typeIcon(credential.type)} class="h-3.5 w-3.5" strokeWidth={1.9} />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <button type="button" class="block max-w-full truncate bg-transparent p-0 text-left text-[13px] font-semibold text-slate-900 shadow-none hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500/40 focus-visible:outline-none" aria-expanded={expandedId === credential.id} aria-controls={`vault-detail-${credential.id}`} on:click={() => toggleExpanded(credential)}>
                                            {credential.name}
                                        </button>
                                        <p class="mt-0.5 truncate text-[11px] text-slate-400">
                                            {typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} secure field${credential.fields.length === 1 ? '' : 's'}` : ''} ·
                                            {#if credential.file}<span class="font-mono">{credential.file.name}</span>{:else}<span class="font-medium text-amber-600">no secure file uploaded</span>{/if}
                                        </p>
                                        {#if credential.tags.length || canManage}
                                            <div class="mt-1">
                                                <TagEditor tags={credential.tags} url={canManage ? credential.tags_url : null} disabled={!canManage} suggestions={tagSuggestions} quiet emptyLabel="" />
                                            </div>
                                        {/if}
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    {#if credential.attachments.length}
                                        <Link href={credential.attachments[0].url} class="block min-w-0 truncate text-[11px] font-semibold text-slate-700 hover:text-indigo-700">{credential.attachments[0].app_name}</Link>
                                        <p class="truncate font-mono text-[10px] text-slate-400">{credential.attachments[0].identifier}</p>
                                        {#if credential.attachments.length > 1}
                                            <p class="font-mono text-[10px] text-slate-400">+{credential.attachments.length - 1} more</p>
                                        {/if}
                                    {:else}
                                        <span class="text-[11px] font-semibold text-amber-600">Unattached</span>
                                    {/if}
                                </div>

                                <div>
                                    {#if credential.expiration.status === 'never'}
                                        <div class="border-t border-dashed border-slate-200" aria-hidden="true"></div>
                                    {:else if credential.expiration.status === 'expired'}
                                        <div class="h-1 overflow-hidden rounded-full bg-rose-100" aria-hidden="true">
                                            <span class="block h-full w-full rounded-full bg-rose-500"></span>
                                        </div>
                                    {:else}
                                        <div class="h-1 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                            <span class="block h-full rounded-full {runwayFill(credential.expiration)} motion-safe:transition-[width] motion-safe:duration-300" style="width: {runwayWidth(credential.expiration)}%"></span>
                                        </div>
                                    {/if}
                                    <p class="mt-1.5 font-mono text-[11px] font-semibold tabular-nums {validityTone(credential.expiration.status)}">{validityLabel(credential.expiration)}</p>
                                </div>

                                <div class="flex shrink-0 items-center gap-0.5 lg:justify-self-end">
                                    <div class="row-tools flex items-center gap-0.5">
                                        {#if credential.file && canManage}
                                            <a href={credential.file.download_url} class="icon-btn" title="Download file" aria-label={`Download ${credential.name}`}><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                                        {/if}
                                        {#if canManage}
                                            <label for={`vault-file-${credential.id}`} class="icon-btn cursor-pointer" title={credential.file ? 'Replace file' : 'Upload file'} aria-label={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                                            <input id={`vault-file-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                                            <button type="button" class="icon-btn" title="Edit credential" aria-label={`Edit ${credential.name}`} on:click={() => openEdit(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                            <button type="button" class="icon-btn text-rose-500 hover:bg-rose-50 hover:text-rose-600" title="Delete credential" aria-label={`Delete ${credential.name}`} on:click={() => confirmingDelete = credential.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                        {/if}
                                    </div>
                                    <button type="button" class="icon-btn" aria-expanded={expandedId === credential.id} aria-controls={`vault-detail-${credential.id}`} aria-label={`Details for ${credential.name}`} on:click={() => toggleExpanded(credential)}>
                                        <ChevronDown class="h-4 w-4 transition-transform motion-reduce:transition-none {expandedId === credential.id ? 'rotate-180' : ''}" strokeWidth={1.9} />
                                    </button>
                                </div>
                            </div>

                            {#if expandedId === credential.id}
                                <div id={`vault-detail-${credential.id}`} class="mt-3 grid gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-2">
                                    <div>
                                        <p class="vault-head mb-1.5">Used by</p>
                                        {#if credential.attachments.length}
                                            <ul class="space-y-1">
                                                {#each credential.attachments as attachment}
                                                    <li>
                                                        <Link href={attachment.url} class="group/att inline-flex max-w-full items-center gap-1.5 text-[11px] text-slate-600 hover:text-indigo-700">
                                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {attachment.platform === 'ios' ? 'bg-slate-900' : 'bg-emerald-500'}"></span>
                                                            <span class="truncate font-semibold">{attachment.app_name}</span>
                                                            <span class="truncate font-mono text-[10px] text-slate-400">{attachment.identifier}</span>
                                                            {#if attachment.profile}<span class="rounded bg-indigo-50 px-1 py-0.5 text-[10px] font-bold text-indigo-600">{attachment.profile.label}</span>{/if}
                                                        </Link>
                                                    </li>
                                                {/each}
                                            </ul>
                                        {:else}
                                            <p class="text-[11px] font-medium text-amber-600">Not attached to an application yet. Attach it from an app identifier to use it in builds.</p>
                                        {/if}
                                    </div>

                                    <div class="space-y-3">
                                        <div>
                                            <p class="vault-head mb-1.5">Secure file</p>
                                            {#if credential.file}
                                                <p class="font-mono text-[11px] break-all text-slate-600">{credential.file.name}<span class="text-slate-400"> · {formatBytes(credential.file.size)}</span></p>
                                            {:else}
                                                <p class="text-[11px] font-medium text-amber-600">No file uploaded. Upload one to use this credential in builds.</p>
                                            {/if}
                                        </div>
                                        <div>
                                            <p class="vault-head mb-1.5">Updated</p>
                                            <p class="text-[11px] text-slate-600">{formatRelative(credential.updated_at)}</p>
                                        </div>
                                        {#if credential.reveal_url && revealedCredentialId !== credential.id}
                                            <button type="button" class="secondary inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px]" on:click={() => openReveal(credential)}>
                                                <Eye class="h-3.5 w-3.5" />
                                                Show password{credential.password_fields_count === 1 ? '' : 's'}
                                            </button>
                                        {/if}
                                    </div>

                                    {#if revealedCredentialId === credential.id}
                                        <div class="sm:col-span-2 rounded-xl border border-indigo-200 bg-indigo-50/70 p-4">
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
                                </div>
                            {/if}

                            {#if confirmingDelete === credential.id}
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                                    <span class="text-[11px] font-medium text-rose-700">{credential.attachments.length > 1 ? `Delete this credential permanently? ${credential.attachments.length} build targets use it.` : 'Delete this credential permanently?'}</span>
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
                            <p class="font-mono text-[11px] text-slate-400 tabular-nums">{credentials.from}–{credentials.to} of {credentials.total}</p>
                            <div class="flex items-center gap-1.5">
                                {#if credentials.prev_page_url}<Link href={credentials.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                                {#each pageLinks as link}
                                    {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center font-mono text-xs tabular-nums {link.active ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-slate-400">{link.label}</span>{/if}
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
