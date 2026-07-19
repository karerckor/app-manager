<script>
    import { Link, router } from '@inertiajs/svelte';
    import { IdCard, Boxes, Clock, KeyRound, Rocket, CircleCheck, CircleX, Ban, Package, Activity, Image as ImageIcon } from '@lucide/svelte';
    import AppLayout from './AppLayout.svelte';
    import { formatDate, formatDateTime, buildStatusClass } from './lib.js';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let tag_suggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'overview';
    export let stats = {};
    export let activity = [];

    const uploadAsset = (collection, event) => {
        const file = event.currentTarget.files?.[0];
        if (file) router.post(app.urls.assets.replace('__collection__', collection), { file }, { forceFormData: true, preserveScroll: true });
        event.currentTarget.value = '';
    };

    const activityIcon = (type) => ({
        build_created: { icon: Rocket, class: 'bg-indigo-50 text-indigo-600' },
        build_completed: { icon: CircleCheck, class: 'bg-emerald-50 text-emerald-600' },
        build_failed: { icon: CircleX, class: 'bg-rose-50 text-rose-600' },
        build_cancelled: { icon: Ban, class: 'bg-slate-100 text-slate-500' },
        build_cancel_requested: { icon: Ban, class: 'bg-amber-50 text-amber-600' },
        artifact_uploaded: { icon: Package, class: 'bg-sky-50 text-sky-600' },
    }[type] ?? { icon: Activity, class: 'bg-slate-100 text-slate-500' });
</script>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,0.8fr)]">
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link href={app.urls.identifiers_page} class="card flex items-center gap-3 transition hover:border-slate-300">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><IdCard class="h-5 w-5" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="text-2xl font-bold tracking-tight">{stats.identifiers ?? 0}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">Identifiers</p>
                    </div>
                </Link>
                <Link href={app.urls.builds_page} class="card flex items-center gap-3 transition hover:border-slate-300">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600"><Boxes class="h-5 w-5" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="text-2xl font-bold tracking-tight">{stats.builds ?? 0}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">Builds</p>
                    </div>
                </Link>
                <Link href={app.urls.builds_page} class="card flex items-center gap-3 transition hover:border-slate-300">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {stats.last_build ? buildStatusClass(stats.last_build.status) : 'bg-slate-100 text-slate-400'}"><Clock class="h-5 w-5" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold tracking-tight">{stats.last_build ? stats.last_build.status.replaceAll('_', ' ') : '—'}</p>
                        <p class="mt-0.5 truncate text-xs font-medium text-slate-500">Last build{stats.last_build ? ` · ${formatDateTime(stats.last_build.at)}` : ''}</p>
                    </div>
                </Link>
                <div class="card flex items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600"><KeyRound class="h-5 w-5" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="text-2xl font-bold tracking-tight">{stats.credentials ?? 0}</p>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">Team credentials</p>
                    </div>
                </div>
            </div>

            <section class="card p-0">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-sm font-bold">Recent activity</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Builds and artifacts across this app.</p>
                </div>
                {#if activity.length}
                    <div class="divide-y divide-slate-100">
                        {#each activity as entry}
                            <Link href={entry.url} class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg {activityIcon(entry.type).class}">
                                    <svelte:component this={activityIcon(entry.type).icon} class="h-4 w-4" strokeWidth={1.8} />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-slate-900">{entry.title}</p>
                                </div>
                                <span class="shrink-0 text-xs text-slate-500">{formatDateTime(entry.timestamp)}</span>
                            </Link>
                        {/each}
                    </div>
                {:else}
                    <div class="px-6 py-12 text-center">
                        <p class="text-sm font-semibold">No activity yet</p>
                        <p class="mt-1 text-xs text-slate-500">Builds and artifacts will show up here as they happen.</p>
                    </div>
                {/if}
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card">
                <div class="mb-4"><h2 class="text-sm font-bold">Application details</h2><p class="mt-0.5 text-xs text-slate-500">Project metadata</p></div>
                <dl class="space-y-3 text-xs">
                    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Slug</dt><dd><code class="rounded bg-slate-100 px-2 py-1 text-slate-700">{app.slug}</code></dd></div>
                    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Created</dt><dd class="font-medium">{formatDate(app.created_at)}</dd></div>
                    <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Last updated</dt><dd class="font-medium">{formatDate(app.updated_at)}</dd></div>
                </dl>
            </section>

            <section class="card">
                <div class="mb-4"><h2 class="text-sm font-bold">App assets</h2><p class="mt-0.5 text-xs text-slate-500">Upload or download project images.</p></div>
                <div class="space-y-3">
                    {#each [['app_icon', 'App icon', 'Square PNG or JPG'], ['splash_icon', 'Splash icon', 'PNG or JPG up to 10 MB']] as asset}
                        <div class="rounded-xl border border-slate-200 p-3">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center rounded-lg bg-slate-100 text-slate-500"><ImageIcon class="h-4 w-4" strokeWidth={1.8} /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold">{asset[1]}</p>
                                    <p class="mt-0.5 truncate text-[10px] text-slate-400">{app.assets[asset[0]]?.name || asset[2]}</p>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <label for={`asset-${asset[0]}`} class="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-center text-[10px] font-semibold text-slate-600 transition hover:bg-slate-50">{app.assets[asset[0]] ? 'Replace' : 'Upload'}</label>
                                <input id={`asset-${asset[0]}`} class="hidden" type="file" accept="image/*" on:change={(event) => uploadAsset(asset[0], event)} />
                                {#if app.assets[asset[0]]}<a href={app.assets[asset[0]].download_url} class="flex-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-center text-[10px] font-semibold text-slate-600 hover:bg-slate-200">Download</a>{/if}
                            </div>
                        </div>
                    {/each}
                </div>
            </section>
        </aside>
    </div>
</AppLayout>
