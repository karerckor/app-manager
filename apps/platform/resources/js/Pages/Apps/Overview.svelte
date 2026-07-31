<script>
    import { Link } from '@inertiajs/svelte';
    import { IdCard, Boxes, Clock, Rocket, CircleCheck, CircleX, Ban, Package, Activity, ChevronDown } from '@lucide/svelte';
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
    export let expo = {};

    const activityIcon = (type) => ({
        build_created: { icon: Rocket, class: 'bg-indigo-50 text-indigo-600' },
        build_completed: { icon: CircleCheck, class: 'bg-emerald-50 text-emerald-600' },
        build_failed: { icon: CircleX, class: 'bg-rose-50 text-rose-600' },
        build_cancelled: { icon: Ban, class: 'bg-slate-100 text-slate-500' },
        build_cancel_requested: { icon: Ban, class: 'bg-amber-50 text-amber-600' },
        artifact_uploaded: { icon: Package, class: 'bg-sky-50 text-sky-600' },
        mobileapp_updated: { icon: Boxes, class: 'bg-indigo-50 text-indigo-600' },
        appidentifier_created: { icon: IdCard, class: 'bg-indigo-50 text-indigo-600' },
    }[type] ?? { icon: Activity, class: 'bg-slate-100 text-slate-500' });
</script>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.75fr)]">
        <div class="space-y-5">
            <section class="card grid overflow-clip p-0 sm:grid-cols-3 sm:divide-x sm:divide-slate-100">
                <Link href={app.urls.identifiers_page} class="flex items-center gap-3 px-4 py-3.5 transition hover:bg-slate-50">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><IdCard class="h-4 w-4" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="font-mono text-base font-bold tracking-tight tabular-nums">{stats.identifiers ?? 0}</p>
                        <p class="text-[11px] font-medium text-slate-500">Identifiers</p>
                    </div>
                </Link>
                <Link href={app.urls.builds_page} class="flex items-center gap-3 border-t border-slate-100 px-4 py-3.5 transition hover:bg-slate-50 sm:border-t-0">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-sky-50 text-sky-600"><Boxes class="h-4 w-4" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="font-mono text-base font-bold tracking-tight tabular-nums">{stats.builds ?? 0}</p>
                        <p class="text-[11px] font-medium text-slate-500">Builds</p>
                    </div>
                </Link>
                <Link href={app.urls.builds_page} class="flex items-center gap-3 border-t border-slate-100 px-4 py-3.5 transition hover:bg-slate-50 sm:border-t-0">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg {stats.last_build ? buildStatusClass(stats.last_build.status) : 'bg-slate-100 text-slate-400'}"><Clock class="h-4 w-4" strokeWidth={1.8} /></span>
                    <div class="min-w-0">
                        <p class="truncate text-xs font-bold tracking-tight">{stats.last_build ? stats.last_build.status.replaceAll('_', ' ') : '—'}</p>
                        <p class="mt-0.5 truncate text-[11px] font-medium text-slate-500">Last build{stats.last_build ? ` · ${formatDateTime(stats.last_build.at)}` : ''}</p>
                    </div>
                </Link>
            </section>

            <section class="card p-0">
                <div class="border-b border-slate-100 px-5 py-3.5">
                    <h2 class="text-xs font-bold">Recent activity</h2>
                    <p class="mt-0.5 text-[11px] text-slate-500">Builds and artifacts across this app.</p>
                </div>
                {#if activity.length}
                    <div class="divide-y divide-slate-100">
                        {#each activity as entry}
                            <Link href={entry.url} class="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg {activityIcon(entry.type).class}">
                                    <svelte:component this={activityIcon(entry.type).icon} class="h-3.5 w-3.5" strokeWidth={1.8} />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-slate-900">{entry.title}</p>
                                    {#if entry.actor}<span class="text-[11px] text-slate-500">by {entry.actor.name}{#if entry.source} · {entry.source}{/if}</span>{/if}
                                </div>
                                <span class="shrink-0 font-mono text-[10px] text-slate-400">{formatDateTime(entry.timestamp)}</span>
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

        <aside class="space-y-5">
            {#if expo.enabled}
                <section class="card border-violet-200/80 bg-violet-50/45">
                    <div class="flex items-start gap-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-violet-100 text-[9px] font-black text-violet-700">EAS</span><div><h2 class="text-xs font-bold text-slate-900">{expo.linked ? 'Expo project linked' : 'Expo Application Services'}</h2><p class="mt-1 text-[11px] leading-5 text-slate-500">{expo.linked ? expo.project.full_name : 'Optional builds, submissions, and hosted credentials.'}</p></div></div>
                    {#if expo.linked}<div class="mt-4 flex gap-2"><a href={expo.project.url} target="_blank" rel="noreferrer" class="secondary flex-1 px-3 py-2 text-center text-xs">Open Expo</a><Link href={expo.page_url} class="flex-1 rounded-xl bg-violet-600 px-3 py-2 text-center text-xs font-semibold text-white shadow-sm hover:bg-violet-700">Manage EAS</Link></div>{:else if expo.connected_accounts}<Link href={expo.page_url} class="mt-4 block rounded-xl bg-violet-600 px-3 py-2 text-center text-xs font-semibold text-white shadow-sm hover:bg-violet-700">Set up on Expo</Link>{:else}<Link href={expo.integrations_url} class="mt-4 block rounded-xl bg-violet-600 px-3 py-2 text-center text-xs font-semibold text-white shadow-sm hover:bg-violet-700">Connect Expo account</Link>{/if}
                </section>
            {/if}
            <section class="card overflow-clip p-0">
                <div class="border-b border-slate-100 px-4 py-3"><h2 class="text-xs font-bold">Application details</h2></div>
                <dl class="divide-y divide-slate-100 text-xs">
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Slug</dt><dd><code class="text-[11px] text-slate-600">{app.slug}</code></dd></div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Created</dt><dd class="font-medium">{formatDate(app.created_at)}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Last updated</dt><dd class="font-medium">{formatDate(app.updated_at)}</dd></div>
                </dl>
                {#if app.metadata != null}
                    <details class="group border-t border-slate-100 px-4 py-3">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-[11px] font-semibold text-slate-700">
                            Metadata
                            <ChevronDown class="h-4 w-4 text-slate-400 transition group-open:rotate-180" strokeWidth={2} />
                        </summary>
                        <pre class="mt-3 max-h-72 overflow-auto rounded-xl bg-slate-950 p-3 font-mono text-[11px] leading-5 whitespace-pre text-slate-100">{JSON.stringify(app.metadata, null, 2)}</pre>
                    </details>
                {/if}
            </section>

        </aside>
    </div>
</AppLayout>
