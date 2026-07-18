<script>
    import { router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let apiKeys = [];
    export let scopeGroups = [];
    export let createUrl;

    let showCreate = false;
    let copied = false;
    const form = useForm({ name: '', scopes: [] });

    const selectedInGroup = (group) => group.scopes.filter((scope) => form.scopes.includes(scope.value)).length;
    const toggleScope = (value) => {
        form.scopes = form.scopes.includes(value)
            ? form.scopes.filter((scope) => scope !== value)
            : [...form.scopes, value];
    };
    const toggleGroup = (group) => {
        const values = group.scopes.map((scope) => scope.value);
        const allSelected = values.every((value) => form.scopes.includes(value));
        form.scopes = allSelected
            ? form.scopes.filter((scope) => !values.includes(scope))
            : [...new Set([...form.scopes, ...values])];
    };
    const createApiKey = () => form.post(createUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showCreate = false;
        },
    });
    const closeModal = () => {
        showCreate = false;
        form.clearErrors();
    };
    const copyToken = async () => {
        await navigator.clipboard.writeText(flash.token);
        copied = true;
        window.setTimeout(() => copied = false, 1800);
    };
    const revoke = (apiKey) => {
        if (window.confirm(`Revoke “${apiKey.name}”? Clients using it will lose access immediately.`)) {
            router.delete(apiKey.revoke_url, { preserveScroll: true });
        }
    };
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
</script>

<svelte:head><title>API Keys · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="api-keys">
    <main class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-2">Workspace security</p>
                <h1 class="text-3xl font-bold tracking-tight text-slate-950">API keys</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500">Team-bound credentials for CI and integrations. Grant only the scopes each client needs.</p>
            </div>
            <button class="flex items-center gap-2" on:click={() => showCreate = true}>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                New API key
            </button>
        </div>

        {#if flash?.token}
            <section class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-amber-400/20 text-amber-700">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.7 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-bold text-amber-950">Copy this key now</h2>
                        <p class="mt-1 text-xs text-amber-800">It is shown once and cannot be recovered later.</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <code class="min-w-0 flex-1 overflow-x-auto rounded-xl border border-amber-200 bg-white px-3.5 py-2.5 text-xs text-slate-800">{flash.token}</code>
                            <button class="secondary shrink-0" on:click={copyToken}>{copied ? 'Copied' : 'Copy key'}</button>
                        </div>
                    </div>
                </div>
            </section>
        {:else if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>
        {/if}

        <section class="card overflow-hidden p-0">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-bold">Keys for {navigationTeam?.name}</h2>
                <p class="mt-1 text-xs text-slate-500">A key can access only this workspace, even when its creator belongs to other teams.</p>
            </div>

            {#if apiKeys.length}
                <div class="divide-y divide-slate-100">
                    {#each apiKeys as apiKey}
                        <article class="grid gap-4 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,1.2fr)_160px_auto] lg:items-center">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-slate-900">{apiKey.name}</h3>
                                <p class="mt-1 truncate text-xs text-slate-500">Created by {apiKey.created_by?.name ?? 'Deleted user'}</p>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                {#if apiKey.scopes.includes('*')}
                                    <span class="rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700">Full access</span>
                                {:else}
                                    {#each apiKey.scopes as scope}
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 font-mono text-[11px] font-medium text-slate-600">{scope}</span>
                                    {/each}
                                {/if}
                            </div>
                            <div class="text-xs text-slate-500">
                                <p><span class="font-medium text-slate-700">Created</span> {formatDate(apiKey.created_at)}</p>
                                <p class="mt-1"><span class="font-medium text-slate-700">Last used</span> {formatDate(apiKey.last_used_at)}</p>
                            </div>
                            <button class="ghost justify-self-start px-3 py-2 text-rose-600 hover:bg-rose-50 hover:text-rose-700 lg:justify-self-end" on:click={() => revoke(apiKey)}>Revoke</button>
                        </article>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 1 1-2 2-1-1-3 3"/></svg></span>
                    <h3 class="text-sm font-bold">No API keys yet</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">Create a scoped key for a CI runner, deployment pipeline, or another integration.</p>
                    <button class="mt-5" on:click={() => showCreate = true}>New API key</button>
                </div>
            {/if}
        </section>
    </main>
</AppShell>

{#if showCreate}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeModal()}>
        <form class="modal max-w-2xl" on:submit|preventDefault={createApiKey}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">New API key</h2><p class="mt-1 text-sm text-slate-500">This key will belong to {navigationTeam?.name}.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeModal} aria-label="Close">✕</button>
            </div>
            <div class="space-y-6 p-6">
                <label>
                    <span class="label">Key name</span>
                    <input bind:value={form.name} placeholder="e.g. GitHub Actions — production" required />
                    {#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}
                </label>

                <fieldset>
                    <legend class="label">Scopes</legend>
                    <p class="mb-3 text-xs text-slate-500">Read and write are independent so log shippers and other clients can be write-only.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        {#each scopeGroups as group}
                            <section class="rounded-xl border border-slate-200 p-3.5">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-slate-800">{group.label}</h3>
                                    <button type="button" class="ghost px-2 py-1 text-[11px]" on:click={() => toggleGroup(group)}>{selectedInGroup(group) === group.scopes.length ? 'Clear' : 'Select all'}</button>
                                </div>
                                <div class="space-y-3">
                                    {#each group.scopes as scope}
                                        <label class="flex cursor-pointer items-start gap-2.5">
                                            <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" checked={form.scopes.includes(scope.value)} on:change={() => toggleScope(scope.value)} />
                                            <span><strong class="block text-xs font-semibold text-slate-700">{scope.label} <code class="ml-1 font-mono text-[10px] font-medium text-slate-400">{scope.value}</code></strong><span class="mt-0.5 block text-[11px] leading-4 text-slate-500">{scope.description}</span></span>
                                        </label>
                                    {/each}
                                </div>
                            </section>
                        {/each}
                    </div>
                    {#if form.errors.scopes}<span class="error">{form.errors.scopes}</span>{/if}
                    {#if form.errors['scopes.0']}<span class="error">{form.errors['scopes.0']}</span>{/if}
                </fieldset>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" on:click={closeModal}>Cancel</button>
                <button disabled={form.processing || form.scopes.length === 0}>{form.processing ? 'Creating…' : 'Create API key'}</button>
            </div>
        </form>
    </div>
{/if}
