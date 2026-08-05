<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let credentials = [];
    export let targets = [];
    export let stats = { total: 0, ready: 0, attention: 0, shared: 0 };
    export let navigationTeams = [];
    export let navigationTeam = null;

    let query = '';
    let platform = 'all';
    let status = 'all';
    let type = 'all';
    let attachingCredential = null;
    const attachmentForm = useForm({ app_identifier_id: '', profile_type: '' });
    const canManageCredentials = auth?.team?.permissions?.manage_credentials ?? false;

    const statuses = {
        ready: { label: 'Ready', classes: 'bg-emerald-50 text-emerald-700' },
        expiring: { label: 'Expiring', classes: 'bg-amber-50 text-amber-700' },
        expired: { label: 'Expired', classes: 'bg-rose-50 text-rose-700' },
        missing_file: { label: 'Missing file', classes: 'bg-slate-100 text-slate-600' },
    };
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value))
        : 'No expiration';
    const formatBytes = (bytes) => {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB'];
        const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    };

    $: types = Array.from(new Map(credentials.map((credential) => [credential.type, credential.type_label])).entries());
    $: compatibleTargets = attachingCredential
        ? targets.filter((target) => attachingCredential.platforms.includes(target.platform))
        : [];
    $: selectedTarget = compatibleTargets.find((target) => target.id === attachmentForm.app_identifier_id);
    $: availableProfiles = attachingCredential?.profiles?.filter((profile) => profile.platform === selectedTarget?.platform) ?? [];
    $: filteredCredentials = credentials.filter((credential) => {
        const searchable = [
            credential.name,
            credential.type_label,
            credential.type,
            ...credential.usages.flatMap((usage) => [usage.app_name, usage.identifier, usage.profile ?? '']),
        ].join(' ').toLowerCase();

        return searchable.includes(query.toLowerCase())
            && (platform === 'all' || credential.platforms.includes(platform))
            && (status === 'all' || credential.status === status)
            && (type === 'all' || credential.type === type);
    });
    const openAttach = (credential) => {
        attachingCredential = credential;
        const target = targets.find((item) => credential.platforms.includes(item.platform));
        attachmentForm.app_identifier_id = target?.id ?? '';
        attachmentForm.profile_type = credential.profiles?.find((profile) => profile.platform === target?.platform)?.value ?? '';
        attachmentForm.clearErrors();
    };
    const closeAttach = () => {
        attachingCredential = null;
        attachmentForm.clearErrors();
    };
    const changeTarget = (event) => {
        attachmentForm.app_identifier_id = event.currentTarget.value;
        const target = compatibleTargets.find((item) => item.id === attachmentForm.app_identifier_id);
        attachmentForm.profile_type = attachingCredential?.profiles?.find((profile) => profile.platform === target?.platform)?.value ?? '';
    };
    const attach = () => attachmentForm.post(attachingCredential.attach_url, {
        preserveScroll: true,
        onSuccess: closeAttach,
    });
    const detach = (usage) => router.delete(usage.detach_url, { preserveScroll: true });
</script>

<svelte:head><title>Credential vault · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="credentials">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <header class="mb-8">
            <p class="eyebrow mb-2">Workspace security</p>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950">Credential vault</h1>
            <p class="mt-2 text-sm text-slate-500">Inventory, lifecycle health and application usages for team-owned build credentials.</p>
        </header>

        <section class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card"><p class="text-2xl font-bold">{stats.total}</p><p class="mt-1 text-xs font-medium text-slate-500">Total credentials</p></div>
            <div class="card"><p class="text-2xl font-bold text-emerald-600">{stats.ready}</p><p class="mt-1 text-xs font-medium text-slate-500">Ready</p></div>
            <div class="card"><p class="text-2xl font-bold text-amber-600">{stats.attention}</p><p class="mt-1 text-xs font-medium text-slate-500">Need attention</p></div>
            <div class="card"><p class="text-2xl font-bold text-indigo-600">{stats.shared}</p><p class="mt-1 text-xs font-medium text-slate-500">Shared credentials</p></div>
        </section>

        <section class="card overflow-hidden p-0">
            <div class="grid gap-3 border-b border-slate-100 px-5 py-4 md:grid-cols-[minmax(220px,1fr)_150px_170px_210px]">
                <label><span class="sr-only">Search credentials</span><input bind:value={query} placeholder="Search credentials, apps or identifiers…" /></label>
                <label><span class="sr-only">Platform</span><select bind:value={platform}><option value="all">All platforms</option><option value="ios">iOS</option><option value="android">Android</option></select></label>
                <label><span class="sr-only">Status</span><select bind:value={status}><option value="all">All statuses</option>{#each Object.entries(statuses) as entry}<option value={entry[0]}>{entry[1].label}</option>{/each}</select></label>
                <label><span class="sr-only">Type</span><select bind:value={type}><option value="all">All credential types</option>{#each types as entry}<option value={entry[0]}>{entry[1]}</option>{/each}</select></label>
            </div>

            {#if filteredCredentials.length}
                <div class="divide-y divide-slate-100">
                    {#each filteredCredentials as credential}
                        <article class="px-5 py-5">
                            <div class="grid gap-5 xl:grid-cols-[minmax(230px,0.8fr)_minmax(300px,1.4fr)_180px]">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold">{credential.name}</h2><span class={'rounded-full px-2.5 py-1 text-[10px] font-bold ' + statuses[credential.status].classes}>{statuses[credential.status].label}</span></div>
                                    <p class="mt-1 text-xs text-slate-500">{credential.type_label}</p>
                                    <div class="mt-3 flex flex-wrap gap-1.5">{#each credential.platforms as item}<span class="rounded bg-slate-100 px-2 py-1 text-[9px] font-bold text-slate-600 uppercase">{item}</span>{/each}<span class="rounded bg-indigo-50 px-2 py-1 text-[9px] font-bold text-indigo-600">{credential.attachment}-scoped</span></div>
                                    {#if canManageCredentials}<button type="button" class="secondary mt-4 px-3 py-2 text-xs" disabled={!targets.some((target) => credential.platforms.includes(target.platform))} on:click={() => openAttach(credential)}>Attach to target</button>{/if}
                                </div>

                                <div>
                                    <div class="mb-2 flex items-center justify-between gap-3"><p class="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Usages</p><span class="text-[10px] text-slate-400">{credential.usage_count} attachment{credential.usage_count === 1 ? '' : 's'}</span></div>
                                    {#if credential.usages.length}
                                        <div class="space-y-2">
                                            {#each credential.usages as usage}
                                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2.5">
                                                    <Link href={usage.app_url} class="min-w-0 transition hover:text-indigo-700"><p class="truncate text-xs font-semibold">{usage.app_name}</p><code class="mt-0.5 block truncate text-[10px] text-slate-500">{usage.identifier}</code></Link>
                                                    <div class="flex items-center gap-1.5"><span class="rounded bg-slate-100 px-2 py-1 text-[9px] font-bold text-slate-600 uppercase">{usage.platform}</span>{#if usage.profile}<span class="rounded bg-indigo-50 px-2 py-1 text-[9px] font-bold text-indigo-600">{usage.profile}</span>{/if}{#if canManageCredentials}<button type="button" class="ghost px-2 py-1 text-[9px] text-rose-600 hover:bg-rose-50" on:click|preventDefault={() => detach(usage)}>Detach</button>{/if}</div>
                                                </div>
                                            {/each}
                                        </div>
                                    {:else}<p class="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">Not attached to an application</p>{/if}
                                </div>

                                <dl class="space-y-2 text-xs">
                                    <div><dt class="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Expiration</dt><dd class="mt-1 font-medium">{formatDate(credential.expires_at)}</dd></div>
                                    <div><dt class="text-[10px] font-bold tracking-wider text-slate-400 uppercase">File</dt><dd class="mt-1 font-medium">{credential.file ? credential.file.name : 'Missing'}{#if credential.file}<span class="ml-1 font-normal text-slate-400">· {formatBytes(credential.file.size)}</span>{/if}</dd></div>
                                    <div><dt class="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Updated</dt><dd class="mt-1 font-medium">{formatDate(credential.updated_at)}</dd></div>
                                </dl>
                            </div>
                        </article>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-16 text-center"><h2 class="text-sm font-bold">{credentials.length ? 'No matching credentials' : 'Vault is empty'}</h2><p class="mt-2 text-sm text-slate-500">{credentials.length ? 'Change the filters to see more credentials.' : 'Credentials added to applications will appear here automatically.'}</p></div>
            {/if}
        </section>
    </main>
</AppShell>

{#if attachingCredential && canManageCredentials}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeAttach()}>
        <form class="modal" on:submit|preventDefault={attach}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Attach credential</h2><p class="mt-1 text-sm text-slate-500">{attachingCredential.name}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeAttach} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label>
                    <span class="label">Application target</span>
                    <select value={attachmentForm.app_identifier_id} on:change={changeTarget} required>
                        {#each compatibleTargets as target}<option value={target.id}>{target.app_name} · {target.platform.toUpperCase()} · {target.identifier}{target.is_default ? ' · default' : ''}</option>{/each}
                    </select>
                    {#if attachmentForm.errors.app_identifier_id}<span class="error">{attachmentForm.errors.app_identifier_id}</span>{/if}
                </label>
                {#if attachingCredential.attachment === 'profile'}
                    <fieldset>
                        <legend class="label">Build profile</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            {#each availableProfiles as profile}
                                <label class="cursor-pointer rounded-xl border p-3 transition {attachmentForm.profile_type === profile.value ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200'}">
                                    <input class="sr-only" type="radio" value={profile.value} bind:group={attachmentForm.profile_type} />
                                    <strong class="block text-xs">{profile.label}</strong><span class="mt-1 block text-[10px] text-slate-500">{profile.artifact} · {profile.description}</span>
                                </label>
                            {/each}
                        </div>
                        {#if attachmentForm.errors.profile_type}<span class="error">{attachmentForm.errors.profile_type}</span>{/if}
                    </fieldset>
                {:else}
                    <div class="rounded-xl bg-indigo-50 p-3 text-xs leading-5 text-indigo-800">This service credential will be attached directly to the selected identifier.</div>
                {/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeAttach}>Cancel</button><button disabled={attachmentForm.processing || !attachmentForm.app_identifier_id}>{attachmentForm.processing ? 'Attaching…' : 'Attach credential'}</button></div>
        </form>
    </div>
{/if}
