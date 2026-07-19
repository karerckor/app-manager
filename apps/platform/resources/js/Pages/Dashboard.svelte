<script>
    import { Link, useForm } from '@inertiajs/svelte';
    import { Plus, LayoutGrid, Cpu, KeyRound, Search, ChevronRight, SquarePlus } from '@lucide/svelte';
    import AppShell from '../Components/AppShell.svelte';
    import TagEditor from '../Components/TagEditor.svelte';

    export let auth;
    export let flash = {};
    export let apps = [];
    export let stats = { apps: 0, identifiers: 0, credentials: 0 };
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let createAppUrl;

    let query = '';
    let showCreate = false;
    const form = useForm({ name: '', slug: '', description: '' });

    $: filteredApps = apps.filter((app) => `${app.name} ${app.slug} ${app.description ?? ''} ${(app.tags ?? []).map((t) => t[0]).join(' ')}`.toLowerCase().includes(query.toLowerCase()));

    const createApp = () => form.post(createAppUrl, {
        onSuccess: () => { form.reset(); showCreate = false; },
    });
    const closeModal = () => { showCreate = false; form.clearErrors(); };
    const formatDate = (value) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric' }).format(new Date(value));
    const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
</script>

<svelte:head><title>Applications · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam}>
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div><p class="eyebrow mb-2">Workspace overview</p><h1 class="text-3xl font-bold tracking-tight text-slate-950">Applications</h1><p class="mt-2 text-sm text-slate-500">Manage identifiers, credentials, and build assets in one place.</p></div>
            <button class="flex items-center gap-2" on:click={() => showCreate = true}>
                <Plus class="h-4 w-4" strokeWidth={2.2} />
                New application
            </button>
        </div>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>{/if}

        <section class="mb-7 grid gap-4 sm:grid-cols-3">
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><LayoutGrid class="h-5 w-5" strokeWidth={1.8} /></span><div><p class="text-2xl font-bold">{stats.apps}</p><p class="text-xs font-medium text-slate-500">Applications</p></div></div>
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-50 text-sky-600"><Cpu class="h-5 w-5" strokeWidth={1.8} /></span><div><p class="text-2xl font-bold">{stats.identifiers}</p><p class="text-xs font-medium text-slate-500">App identifiers</p></div></div>
            <div class="card flex items-center gap-4 p-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-amber-50 text-amber-600"><KeyRound class="h-5 w-5" strokeWidth={1.8} /></span><div><p class="text-2xl font-bold">{stats.credentials}</p><p class="text-xs font-medium text-slate-500">Credentials</p></div></div>
        </section>

        <section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-sm font-bold text-slate-900">All applications</h2><p class="mt-0.5 text-xs text-slate-500">{filteredApps.length} in {navigationTeam?.name ?? 'current workspace'}</p></div>
                <label class="relative w-full sm:w-64"><span class="sr-only">Search applications</span><Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" /><input class="py-2 pl-9" bind:value={query} placeholder="Search name, slug, tag…" /></label>
            </div>

            {#if filteredApps.length}
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {#each filteredApps as app (app.id)}
                        <Link href={app.url} class="group card flex flex-col gap-3.5 p-4 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg hover:shadow-indigo-100/60">
                            <div class="flex items-start gap-3">
                                {#if app.icon_url}<img src={app.icon_url} alt="" class="h-11 w-11 shrink-0 rounded-xl bg-white object-cover shadow-sm ring-1 ring-slate-200" />{:else}<span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-bold text-white shadow-sm">{initials(app.name)}</span>{/if}
                                <div class="min-w-0 flex-1"><h3 class="truncate text-sm font-semibold text-slate-900 group-hover:text-indigo-700">{app.name}</h3><code class="mt-0.5 block truncate text-[11px] text-slate-400">{app.slug}</code></div>
                                <ChevronRight class="mt-1 h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-500" />
                            </div>
                            <p class="line-clamp-2 min-h-[2.25rem] text-xs leading-relaxed text-slate-500">{app.description || 'No description yet.'}</p>
                            {#if app.tags?.length}<div class="min-h-[1.25rem]"><TagEditor tags={app.tags} disabled /></div>{/if}
                            <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><Cpu class="h-3.5 w-3.5 text-slate-400" strokeWidth={1.8} />{app.identifiers_count} identifier{app.identifiers_count === 1 ? '' : 's'}</span>
                                <span class="text-[11px] text-slate-400">Updated {formatDate(app.updated_at)}</span>
                            </div>
                        </Link>
                    {/each}
                </div>
            {:else}
                <div class="card px-6 py-16 text-center"><span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><SquarePlus class="h-6 w-6" strokeWidth={1.7} /></span><h3 class="text-sm font-bold">{query ? 'No matching applications' : 'Create your first application'}</h3><p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">{query ? 'Try another search term.' : 'Applications group your platform identifiers, signing credentials, and assets.'}</p>{#if !query}<button class="mt-5" on:click={() => showCreate = true}>New application</button>{/if}</div>
            {/if}
        </section>
    </main>
</AppShell>

{#if showCreate}
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
