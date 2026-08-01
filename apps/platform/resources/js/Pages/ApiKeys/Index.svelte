<script>
    import { router, useForm } from '@inertiajs/svelte';
    import { Plus, TriangleAlert, KeyRound, Bot, GitBranch } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let apiKeys = [];
    export let scopeGroups = [];
    export let keyPresets = [];
    export let createUrl;

    let showCreate = false;
    let copied = false;
    let selectedPreset = null;
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
    const applyPreset = (preset) => {
        selectedPreset = preset.key;
        form.name = preset.name;
        form.scopes = [...preset.scopes];
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
        selectedPreset = null;
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

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="api-keys" eyebrow="Workspace security" title="API keys" description="Team-bound credentials for CI and integrations. Grant only the scopes each client needs." {flash}>
    {#snippet actions()}
        <button class="flex items-center gap-2" on:click={() => showCreate = true}>
            <Plus class="h-4 w-4" strokeWidth={2.2} />
            New API key
        </button>
    {/snippet}

        {#if flash?.token}
            <section class="mb-6 rounded-md border border-warn-fill/30 bg-warn-text/10 p-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-warn-text/20 text-warn-fill">
                        <TriangleAlert class="h-5 w-5" strokeWidth={1.9} />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-bold text-text">Copy this key now</h2>
                        <p class="mt-1 text-xs text-muted">It is shown once and cannot be recovered later.</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <code class="min-w-0 flex-1 overflow-x-auto rounded-md border border-border bg-surface-raised px-3.5 py-2.5 text-xs text-text">{flash.token}</code>
                            <button class="secondary shrink-0" on:click={copyToken}>{copied ? 'Copied' : 'Copy key'}</button>
                        </div>
                    </div>
                </div>
            </section>
        {/if}

        <section class="card overflow-visible p-0">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-bold">Keys for {navigationTeam?.name}</h2>
                <p class="mt-1 text-xs text-muted">A key can access only this workspace, even when its creator belongs to other teams.</p>
            </div>

            {#if apiKeys.length}
                <div class="divide-y divide-border-subtle">
                    {#each apiKeys as apiKey}
                        <article class="grid gap-4 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,1.2fr)_160px_auto] lg:items-center">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-text">{apiKey.name}</h3>
                                <p class="mt-1 truncate text-xs text-muted">Created by {apiKey.created_by?.name ?? 'Deleted user'}</p>
                            </div>
                            <div class="min-w-0">
                                {#if apiKey.scopes.includes('*')}
                                    <span class="rounded-full bg-brand-soft px-2.5 py-1 text-[11px] font-semibold text-brand">Full access</span>
                                {:else}
                                    <button type="button" class="group relative inline-flex cursor-help rounded-full bg-surface-sunken px-2.5 py-1 font-mono text-[11px] font-medium text-muted underline decoration-border-control underline-offset-2 shadow-none hover:bg-brand-soft hover:text-brand" aria-describedby={`api-key-scopes-${apiKey.id}`}>
                                        {apiKey.scopes.length} {apiKey.scopes.length === 1 ? 'scope' : 'scopes'}
                                        <span id={`api-key-scopes-${apiKey.id}`} role="tooltip" class="pointer-events-none absolute left-0 top-full z-30 mt-2 hidden w-max max-w-sm rounded-md border border-border bg-surface-raised p-3 shadow-lg group-hover:block group-focus:block">
                                            <span class="mb-2 block text-[10px] font-bold uppercase tracking-[0.14em] text-faint">Granted scopes</span>
                                            <span class="flex flex-wrap gap-1.5">
                                                {#each apiKey.scopes as scope}
                                                    <span class="rounded-full bg-surface-sunken px-2 py-1 font-mono text-[10px] font-medium text-muted">{scope}</span>
                                                {/each}
                                            </span>
                                        </span>
                                    </button>
                                {/if}
                            </div>
                            <div class="text-xs text-muted">
                                <p><span class="font-medium text-text">Created</span> {formatDate(apiKey.created_at)}</p>
                                <p class="mt-1"><span class="font-medium text-text">Last used</span> {formatDate(apiKey.last_used_at)}</p>
                            </div>
                            <button class="ghost justify-self-start px-3 py-2 text-danger hover:bg-danger/10 lg:justify-self-end" on:click={() => revoke(apiKey)}>Revoke</button>
                        </article>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><KeyRound class="h-6 w-6" strokeWidth={1.7} /></span>
                    <h3 class="text-sm font-bold">No API keys yet</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-muted">Create a scoped key for a CI runner, deployment pipeline, or another integration.</p>
                    <button class="mt-5" on:click={() => showCreate = true}>New API key</button>
                </div>
            {/if}
        </section>
</WorkspacePage>

{#if showCreate}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeModal()}>
        <form class="modal max-w-2xl" on:submit|preventDefault={createApiKey}>
            <div class="flex items-start justify-between border-b border-border px-6 py-5">
                <div><h2 class="text-lg font-bold">New API key</h2><p class="mt-1 text-sm text-muted">This key will belong to {navigationTeam?.name}.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeModal} aria-label="Close">✕</button>
            </div>
            <div class="space-y-6 p-6">
                {#if keyPresets.length}
                    <fieldset>
                        <legend class="label">Start with a preset</legend>
                        <p class="mb-3 text-xs text-muted">Choose a least-privilege starting point, then adjust the name or scopes below.</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            {#each keyPresets as preset}
                                <button type="button" class={`group !flex !h-auto !w-full !flex-col !items-start !justify-start rounded-md border p-4 text-left transition ${selectedPreset === preset.key ? 'border-brand bg-brand-soft ring-1 ring-brand' : 'border-border bg-surface-raised hover:border-brand/50 hover:bg-surface-sunken'}`} aria-pressed={selectedPreset === preset.key} on:click={() => applyPreset(preset)}>
                                    <span class="mb-3 grid h-8 w-8 place-items-center rounded-sm bg-surface-sunken text-brand group-hover:bg-brand-soft">
                                        {#if preset.key === 'build-runner'}
                                            <Bot class="h-4 w-4" strokeWidth={2} />
                                        {:else}
                                            <GitBranch class="h-4 w-4" strokeWidth={2} />
                                        {/if}
                                    </span>
                                    <strong class="block text-sm font-semibold text-text">{preset.label}</strong>
                                    <span class="mt-1 block text-xs leading-5 text-muted">{preset.description}</span>
                                    <span class="mt-3 block font-mono text-[10px] font-medium text-faint">{preset.scopes.length} scopes selected</span>
                                </button>
                            {/each}
                        </div>
                    </fieldset>
                {/if}

                <label>
                    <span class="label">Key name</span>
                    <input bind:value={form.name} placeholder="e.g. GitHub Actions — production" required />
                    {#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}
                </label>

                <fieldset>
                    <legend class="label">Scopes</legend>
                    <p class="mb-3 text-xs text-muted">Read and write are independent so log shippers and other clients can be write-only.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        {#each scopeGroups as group}
                            <section class="rounded-md border border-border p-3.5">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-text">{group.label}</h3>
                                    <button type="button" class="ghost px-2 py-1 text-[11px]" on:click={() => toggleGroup(group)}>{selectedInGroup(group) === group.scopes.length ? 'Clear' : 'Select all'}</button>
                                </div>
                                <div class="space-y-3">
                                    {#each group.scopes as scope}
                                        <label class="flex cursor-pointer items-start gap-2.5">
                                            <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-border-control text-brand focus:ring-brand" checked={form.scopes.includes(scope.value)} on:change={() => toggleScope(scope.value)} />
                                            <span><strong class="block text-xs font-semibold text-text">{scope.label} <code class="ml-1 font-mono text-[10px] font-medium text-faint">{scope.value}</code></strong><span class="mt-0.5 block text-[11px] leading-4 text-muted">{scope.description}</span></span>
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
            <div class="flex justify-end gap-3 border-t border-border bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" on:click={closeModal}>Cancel</button>
                <button disabled={form.processing || form.scopes.length === 0}>{form.processing ? 'Creating…' : 'Create API key'}</button>
            </div>
        </form>
    </div>
{/if}
