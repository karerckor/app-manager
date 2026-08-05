<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import AppShell from '../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let apps = [];
    export let stats = { apps: 0, identifiers: 0, credentials: 0 };
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let createAppUrl;
    export let tokens = [];
    export let tokenAbilities = [];
    export let tokenStoreUrl;

    let query = '';
    let showCreate = false;
    let showCreateToken = false;
    let showCloseTeam = false;
    let closingTeam = false;
    let copiedToken = false;
    const form = useForm({ name: '', slug: '', description: '' });
    const tokenForm = useForm({ name: '', abilities: ['apps:read', 'secrets:read', 'builds:read', 'builds:write'], expires_at: '' });
    const canManageApplications = auth?.team?.permissions?.manage_applications ?? false;
    const canCloseTeam = auth?.team?.permissions?.close_team ?? false;
    const minimumExpiration = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

    $: filteredApps = apps.filter((app) => `${app.name} ${app.slug} ${app.description ?? ''}`.toLowerCase().includes(query.toLowerCase()));

    const createApp = () => form.post(createAppUrl, {
        onSuccess: () => { form.reset(); showCreate = false; },
    });
    const closeModal = () => { showCreate = false; form.clearErrors(); };
    const closeTokenModal = () => { showCreateToken = false; tokenForm.clearErrors(); };
    const toggleAbility = (ability, enabled) => {
        const selected = new Set(tokenForm.abilities);
        enabled ? selected.add(ability) : selected.delete(ability);

        if (enabled && ability === 'apps:write') selected.add('apps:read');
        if (enabled && ability === 'secrets:read') selected.add('apps:read');
        if (enabled && ability === 'secrets:write') {
            selected.add('apps:read');
            selected.add('secrets:read');
        }
        if (enabled && ability === 'builds:read') selected.add('apps:read');
        if (enabled && ability === 'builds:write') {
            selected.add('apps:read');
            selected.add('builds:read');
        }
        if (!enabled && ability === 'apps:read') {
            selected.delete('apps:write');
            selected.delete('secrets:read');
            selected.delete('secrets:write');
            selected.delete('builds:read');
            selected.delete('builds:write');
        }
        if (!enabled && ability === 'secrets:read') selected.delete('secrets:write');
        if (!enabled && ability === 'builds:read') selected.delete('builds:write');

        tokenForm.abilities = tokenAbilities.map((definition) => definition.value).filter((value) => selected.has(value));
    };
    const createToken = () => tokenForm.post(tokenStoreUrl, {
        preserveScroll: true,
        onSuccess: () => {
            tokenForm.reset();
            showCreateToken = false;
            copiedToken = false;
        },
    });
    const revokeToken = (token) => router.delete(token.destroy_url, { preserveScroll: true });
    const copyToken = async () => {
        await navigator.clipboard.writeText(flash.token);
        copiedToken = true;
    };
    const closeTeam = () => router.delete(navigationTeam.close_url, {
        onStart: () => closingTeam = true,
        onFinish: () => closingTeam = false,
    });
    const formatDate = (value) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric' }).format(new Date(value));
    const formatDateTime = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
    const abilityLabel = (ability) => ability === '*'
        ? 'Full access'
        : tokenAbilities.find((definition) => definition.value === ability)?.label ?? ability;
    const isExpired = (value) => value && new Date(value) <= new Date();
    const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
</script>

<svelte:head><title>Applications · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam}>
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div><p class="eyebrow mb-2">Workspace overview</p><h1 class="text-3xl font-bold tracking-tight text-slate-950">Applications</h1><p class="mt-2 text-sm text-slate-500">Manage identifiers, credentials, and build assets in one place.</p></div>
            <div class="flex items-center gap-2">
                {#if canCloseTeam}<button type="button" class="secondary text-rose-700" on:click={() => showCloseTeam = true}>Close team</button>{/if}
                {#if canManageApplications}
                    <button class="flex items-center gap-2" on:click={() => showCreate = true}>
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                        New application
                    </button>
                {/if}
            </div>
        </div>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>{/if}
        {#if flash?.token}
            <section class="mb-6 rounded-2xl border border-amber-300 bg-amber-50 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-sm font-bold text-amber-950">Copy this API key now</p><p class="mt-1 text-xs text-amber-800">It is shown once and cannot be recovered later.</p></div>
                    <button type="button" class="secondary px-3 py-2 text-xs" on:click={copyToken}>{copiedToken ? 'Copied' : 'Copy key'}</button>
                </div>
                <code class="mt-4 block overflow-x-auto rounded-xl bg-slate-950 px-4 py-3 text-xs text-emerald-300">{flash.token}</code>
            </section>
        {/if}

        <section class="mb-7 grid gap-4 sm:grid-cols-3">
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg></span><div><p class="text-2xl font-bold">{stats.apps}</p><p class="text-xs font-medium text-slate-500">Applications</p></div></div>
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-50 text-sky-600"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 7h10v10H7z"/><path d="M3 9h4M3 15h4M17 9h4M17 15h4"/></svg></span><div><p class="text-2xl font-bold">{stats.identifiers}</p><p class="text-xs font-medium text-slate-500">App identifiers</p></div></div>
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-amber-50 text-amber-600"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 1 1-2 2-1-1-3 3"/></svg></span><div><p class="text-2xl font-bold">{stats.credentials}</p><p class="text-xs font-medium text-slate-500">Credentials</p></div></div>
        </section>

        <section class="card overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div><h2 class="text-sm font-bold">All applications</h2><p class="mt-0.5 text-xs text-slate-500">{filteredApps.length} in {navigationTeam?.name ?? 'current workspace'}</p></div>
                <label class="relative w-full sm:w-64"><span class="sr-only">Search applications</span><svg viewBox="0 0 24 24" class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input class="py-2 pl-9" bind:value={query} placeholder="Search applications…" /></label>
            </div>

            {#if filteredApps.length}
                <div class="divide-y divide-slate-100">
                    {#each filteredApps as app}
                        <Link href={app.url} class="group grid gap-4 px-5 py-4 transition hover:bg-slate-50/80 sm:grid-cols-[minmax(0,1fr)_140px_120px_24px] sm:items-center">
                            <div class="flex min-w-0 items-center gap-3.5">{#if app.icon_url}<img src={app.icon_url} alt="" class="h-11 w-11 shrink-0 rounded-xl bg-white object-cover shadow-sm ring-1 ring-slate-200" />{:else}<span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-bold text-white shadow-sm">{initials(app.name)}</span>{/if}<div class="min-w-0"><h3 class="truncate text-sm font-semibold text-slate-900 group-hover:text-indigo-700">{app.name}</h3><p class="mt-0.5 truncate text-xs text-slate-500">{app.description || app.slug}</p></div></div>
                            <div><p class="sm:hidden eyebrow mb-1">Identifiers</p><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{app.identifiers_count} identifier{app.identifiers_count === 1 ? '' : 's'}</span></div>
                            <div><p class="sm:hidden eyebrow mb-1">Updated</p><p class="text-xs text-slate-500">Updated {formatDate(app.updated_at)}</p></div>
                            <svg viewBox="0 0 24 24" class="hidden h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-500 sm:block" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </Link>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-16 text-center"><span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8v8M8 12h8"/></svg></span><h3 class="text-sm font-bold">{query ? 'No matching applications' : canManageApplications ? 'Create your first application' : 'No applications yet'}</h3><p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">{query ? 'Try another search term.' : 'Applications group your platform identifiers, signing credentials, and assets.'}</p>{#if !query && canManageApplications}<button class="mt-5" on:click={() => showCreate = true}>New application</button>{/if}</div>
            {/if}
        </section>

        <section class="card mt-7 overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div><h2 class="text-sm font-bold">API keys</h2><p class="mt-0.5 text-xs text-slate-500">Scoped keys used by Fastlane and CI pipelines.</p></div>
                <button type="button" class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => showCreateToken = true}><span class="text-base leading-none">+</span> New key</button>
            </div>
            {#if tokens.length}
                <div class="divide-y divide-slate-100">
                    {#each tokens as token}
                        <div class="grid gap-4 px-5 py-4 lg:grid-cols-[minmax(180px,0.8fr)_minmax(260px,1.4fr)_180px_90px] lg:items-center">
                            <div><div class="flex items-center gap-2"><p class="text-sm font-semibold">{token.name}</p>{#if isExpired(token.expires_at)}<span class="rounded bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold text-rose-700 uppercase">Expired</span>{/if}</div><p class="mt-1 text-[10px] text-slate-400">Created {formatDateTime(token.created_at)}</p></div>
                            <div class="flex flex-wrap gap-1.5">{#each token.abilities ?? [] as ability}<span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-semibold text-indigo-700">{abilityLabel(ability)}</span>{/each}</div>
                            <div class="text-[10px] leading-5 text-slate-500"><p><strong class="text-slate-700">Expires:</strong> {formatDateTime(token.expires_at)}</p><p><strong class="text-slate-700">Last used:</strong> {formatDateTime(token.last_used_at)}</p></div>
                            <button type="button" class="ghost justify-self-start px-2.5 py-1.5 text-xs text-rose-600 hover:bg-rose-50 lg:justify-self-end" on:click={() => revokeToken(token)}>Revoke</button>
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-10 text-center"><p class="text-sm font-semibold">No API keys</p><p class="mt-1 text-xs text-slate-500">Create a scoped build key for your first CI pipeline.</p></div>
            {/if}
        </section>
    </main>
</AppShell>

{#if showCreate && canManageApplications}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeModal()}>
        <form class="modal" on:submit|preventDefault={createApp}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">New application</h2><p class="mt-1 text-sm text-slate-500">Set up the project. Identifiers can be added next.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeModal} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Application name</span><input bind:value={form.name} placeholder="e.g. Atlas Mobile" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>
                <label><span class="label">Slug <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={form.slug} placeholder="Generated from application name" />{#if form.errors.slug}<span class="error">{form.errors.slug}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-slate-400">(optional)</span></span><textarea bind:value={form.description} placeholder="What is this application for?"></textarea>{#if form.errors.description}<span class="error">{form.errors.description}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeModal}>Cancel</button><button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create application'}</button></div>
        </form>
    </div>
{/if}

{#if showCreateToken}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeTokenModal()}>
        <form class="modal" on:submit|preventDefault={createToken}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">New API key</h2><p class="mt-1 text-sm text-slate-500">Grant only the access required by this pipeline.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeTokenModal} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Key name</span><input bind:value={tokenForm.name} placeholder="Production CI" required />{#if tokenForm.errors.name}<span class="error">{tokenForm.errors.name}</span>{/if}</label>
                <fieldset>
                    <legend class="label">Abilities</legend>
                    <div class="space-y-2">
                        {#each tokenAbilities as ability}
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3.5 transition hover:border-indigo-300 hover:bg-indigo-50/30">
                                <input class="mt-0.5 !h-4 !w-4 shrink-0" type="checkbox" checked={tokenForm.abilities.includes(ability.value)} on:change={(event) => toggleAbility(ability.value, event.currentTarget.checked)} />
                                <span><strong class="block text-xs text-slate-800">{ability.label}</strong><span class="mt-0.5 block text-[10px] leading-4 text-slate-500">{ability.description}</span><code class="mt-1 block text-[9px] text-indigo-600">{ability.value}</code></span>
                            </label>
                        {/each}
                    </div>
                    {#if tokenForm.errors.abilities}<span class="error">{tokenForm.errors.abilities}</span>{/if}
                </fieldset>
                <label><span class="label">Expiration <span class="font-normal text-slate-400">(optional)</span></span><input type="date" min={minimumExpiration} bind:value={tokenForm.expires_at} />{#if tokenForm.errors.expires_at}<span class="error">{tokenForm.errors.expires_at}</span>{/if}<span class="mt-1.5 block text-[10px] text-slate-400">Short-lived keys reduce the impact of accidental exposure.</span></label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeTokenModal}>Cancel</button><button disabled={tokenForm.processing || tokenForm.abilities.length === 0}>{tokenForm.processing ? 'Creating…' : 'Create key'}</button></div>
        </form>
    </div>
{/if}

{#if showCloseTeam && canCloseTeam}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showCloseTeam = false)}>
        <div class="modal">
            <div class="border-b border-slate-100 px-6 py-5"><h2 class="text-lg font-bold">Close {navigationTeam.name}?</h2><p class="mt-1 text-sm text-slate-500">All applications, credentials, and team data will be permanently deleted.</p></div>
            <div class="flex justify-end gap-3 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={() => showCloseTeam = false}>Cancel</button><button type="button" class="bg-rose-600 text-white hover:bg-rose-700" disabled={closingTeam} on:click={closeTeam}>{closingTeam ? 'Closing…' : 'Close team'}</button></div>
        </div>
    </div>
{/if}
