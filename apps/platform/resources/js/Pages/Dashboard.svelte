<script>
    import { onDestroy } from 'svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { Plus, LayoutGrid, Cpu, KeyRound, Search, ChevronRight, SquarePlus } from '@lucide/svelte';
    import WorkspacePage from '../Components/WorkspacePage.svelte';
    import TabSelector from '../Components/TabSelector.svelte';
    import TagEditor from '../Components/TagEditor.svelte';
    import { parseMetadata } from './Apps/lib.js';

    export let auth;
    export let flash = {};
    export let apps = { data: [], links: [], total: 0 };
    export let filters = {};
    export let stats = { apps: 0, identifiers: 0, credentials: 0 };
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let createAppUrl;

    let query = filters.search ?? '';
    let searchTimer;
    let showCreate = false;
    let metadataText = '';
    let metadataError = '';
    const form = useForm({ name: '', slug: '', description: '', metadata: null });

    $: appItems = apps?.data ?? [];
    $: pageLinks = (apps?.links ?? []).slice(1, -1);

    const createApp = () => {
        const metadata = parseMetadata(metadataText, (message) => metadataError = message);

        if (metadata === undefined) return;

        form.metadata = metadata;
        form.post(createAppUrl, {
            onSuccess: () => {
                form.reset();
                metadataText = '';
                metadataError = '';
                showCreate = false;
            },
        });
    };
    const closeModal = () => {
        showCreate = false;
        metadataError = '';
        form.clearErrors();
    };
    const scheduleSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            router.get(apps.path, {
                search: query.trim() || undefined,
            }, {
                only: ['apps', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);
    };
    const formatDate = (value) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric' }).format(new Date(value));
    const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

    onDestroy(() => clearTimeout(searchTimer));
</script>

<svelte:head><title>Applications · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="apps" eyebrow="Workspace catalog" title="Applications" {flash}>
    {#snippet actions()}
        <button class="flex items-center gap-2" on:click={() => showCreate = true}>
            <Plus class="h-4 w-4" strokeWidth={2.2} />
            New application
        </button>
    {/snippet}

        <div class="mb-4">
            <TabSelector
                variant="pills"
                ariaLabel="Workspace stats"
                items={[
                    { value: 'apps', label: 'Applications', count: stats.apps, icon: LayoutGrid },
                    { value: 'identifiers', label: 'Identifiers', count: stats.identifiers, icon: Cpu },
                    { value: 'credentials', label: 'Credentials', count: stats.credentials, icon: KeyRound },
                ]}
            />
        </div>

        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-slate-900">
                    Workspace applications
                    <span class="ml-1 font-mono text-[11px] font-medium text-slate-400 tabular-nums">{apps.total ?? 0}{filters.search ? ' matching' : ''} in {navigationTeam?.name ?? 'current workspace'}</span>
                </h2>
                <label class="relative w-full sm:w-72">
                    <span class="sr-only">Search applications</span>
                    <Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                    <input class="py-2 pl-9 text-xs" bind:value={query} on:input={scheduleSearch} type="search" placeholder="Search name, slug, tag…" />
                </label>
            </div>

            {#if appItems.length}
                <div class="hidden border-y border-slate-100 bg-slate-50/80 px-5 py-2 text-[10px] font-bold tracking-[0.1em] text-slate-400 uppercase sm:grid sm:grid-cols-[minmax(0,1fr)_90px_70px_16px] sm:items-center sm:gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(150px,0.55fr)_120px_90px_16px] xl:gap-4">
                    <span>Application</span>
                    <span class="hidden xl:block">Tags</span>
                    <span>Identifiers</span>
                    <span>Updated</span>
                    <span class="sr-only">Open</span>
                </div>
                <div class="divide-y divide-slate-100">
                    {#each appItems as app (app.id)}
                        <Link href={app.url} class="group grid gap-3 px-5 py-3 transition hover:bg-slate-50/70 sm:grid-cols-[minmax(0,1fr)_90px_70px_16px] sm:items-center xl:grid-cols-[minmax(0,1fr)_minmax(150px,0.55fr)_120px_90px_16px] xl:gap-4">
                            <div class="flex min-w-0 items-center gap-3">
                                {#if app.icon_url}<img src={app.icon_url} alt="" class="h-8 w-8 shrink-0 rounded-lg bg-white object-cover ring-1 ring-slate-200" />{:else}<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-[10px] font-bold text-white">{initials(app.name)}</span>{/if}
                                <div class="min-w-0">
                                    <h3 class="truncate text-[13px] font-semibold text-slate-900 group-hover:text-indigo-700">{app.name}</h3>
                                    <p class="mt-0.5 truncate text-[11px] text-slate-400"><code>{app.slug}</code>{#if app.description}<span class="font-sans"> · {app.description}</span>{/if}</p>
                                </div>
                            </div>
                            <div class="hidden min-w-0 xl:block">
                                {#if app.tags?.length}<TagEditor tags={app.tags} disabled quiet />{:else}<span class="text-[11px] text-slate-300">No tags</span>{/if}
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-slate-500"><Cpu class="h-3.5 w-3.5 text-slate-400" strokeWidth={1.8} />{app.identifiers_count} identifier{app.identifiers_count === 1 ? '' : 's'}</span>
                            <span class="text-[11px] text-slate-400">{formatDate(app.updated_at)}</span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-500" />
                        </Link>
                    {/each}
                </div>

                {#if apps.last_page > 1}
                    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4" aria-label="Application pagination">
                        <p class="font-mono text-[11px] text-slate-400 tabular-nums">{apps.from}–{apps.to} of {apps.total}</p>
                        <div class="flex items-center gap-1.5">
                            {#if apps.prev_page_url}
                                <Link href={apps.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>
                            {:else}
                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>
                            {/if}

                            {#each pageLinks as link}
                                {#if link.url}
                                    <Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center text-xs {link.active ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>
                                {:else}
                                    <span class="px-1 text-xs text-slate-400">{link.label}</span>
                                {/if}
                            {/each}

                            {#if apps.next_page_url}
                                <Link href={apps.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>
                            {:else}
                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>
                            {/if}
                        </div>
                    </nav>
                {/if}
            {:else}
                <div class="border-t border-slate-100 px-6 py-16 text-center"><span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><SquarePlus class="h-6 w-6" strokeWidth={1.7} /></span><h3 class="text-sm font-bold">{filters.search ? 'No matching applications' : 'Create your first application'}</h3><p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">{filters.search ? 'Try another search term.' : 'Applications group your platform identifiers, signing credentials, and assets.'}</p>{#if !filters.search}<button class="mt-5" on:click={() => showCreate = true}>New application</button>{/if}</div>
            {/if}
        </section>
</WorkspacePage>

{#if showCreate}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeModal()}>
        <form class="modal" on:submit|preventDefault={createApp}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">New application</h2><p class="mt-1 text-sm text-slate-500">Set up the project. Identifiers can be added next.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeModal} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Application name</span><input bind:value={form.name} placeholder="e.g. Atlas Mobile" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>
                <label><span class="label">Slug <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={form.slug} placeholder="Generated from application name" />{#if form.errors.slug}<span class="error">{form.errors.slug}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-slate-400">(optional)</span></span><textarea bind:value={form.description} placeholder="What is this application for?"></textarea>{#if form.errors.description}<span class="error">{form.errors.description}</span>{/if}</label>
                <label><span class="label">Metadata (JSON) <span class="font-normal text-slate-400">(optional)</span></span><textarea class="min-h-36 font-mono text-xs leading-5" bind:value={metadataText} spellcheck="false" placeholder={'{\n  "owner": "mobile-team",\n  "channel": "production"\n}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">Add any JSON object with application-specific values.</span>{#if metadataError || form.errors.metadata}<span class="error">{metadataError || form.errors.metadata}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeModal}>Cancel</button><button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create application'}</button></div>
        </form>
    </div>
{/if}
