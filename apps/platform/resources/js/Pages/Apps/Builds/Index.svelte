<script>
    import { Link, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { Package } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import TagEditor from '../../../Components/TagEditor.svelte';
    import ForceStatusModal from '../Modals/ForceStatusModal.svelte';
    import { formatDateTime, buildStatusClass } from '../lib.js';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let tag_suggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'builds';
    export let builds = [];

    let forcingBuild = null;

    const cancelBuild = (build) => router.post(build.cancel_url, {}, { preserveScroll: true });
    const openForceStatus = (build) => forcingBuild = build;
    const closeForceStatus = () => forcingBuild = null;

    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
        push(app.tags);
        builds.forEach((build) => { push(build.tags); (build.artifacts || []).forEach((artifact) => push(artifact.tags)); });
        return acc;
    })();

    onMount(() => {
        const timer = setInterval(() => {
            const waiting = builds.some((build) => !['completed', 'failed', 'cancelled'].includes(build.status) || build.logs_pending);

            if (waiting) {
                router.reload({ only: ['builds'], preserveScroll: true, preserveState: true });
            }
        }, 5000);

        return () => clearInterval(timer);
    });
</script>

<svelte:head><title>Builds · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <section class="card overflow-hidden p-0">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-bold">Build history</h2><p class="mt-1 text-xs text-slate-500">Builds reported by CI providers and your own runners.</p></div>
        {#if builds.length}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-xs">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400"><tr><th class="px-5 py-3">Pipeline</th><th class="px-4 py-3">Profile</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Artifacts</th><th class="px-4 py-3">Started</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        {#each builds as build (build.id)}
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2"><span class="font-semibold">{build.pipeline_name || 'Manual build'}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 uppercase">{build.reporting_mode}</span></div>
                                    <div class="mt-1.5"><TagEditor tags={build.tags} url={build.tags_url} suggestions={allTags} /></div>
                                </td>
                                <td class="px-4 py-4 text-slate-500">{build.profile_type}</td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {buildStatusClass(build.status)}">{build.status.replaceAll('_', ' ')}</span></td>
                                <td class="px-4 py-4">
                                    {#if build.artifacts.length}
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-semibold text-indigo-700"><Package class="h-3 w-3" />{build.artifacts.length} attached</span>
                                    {:else}
                                        <span class="inline-flex items-center gap-1.5 text-[10px] font-medium text-slate-400"><Package class="h-3 w-3" strokeWidth={1.8} />None</span>
                                    {/if}
                                </td>
                                <td class="px-4 py-4 text-slate-500">{formatDateTime(build.started_at || build.created_at)}</td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <Link href={build.show_url} class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold text-slate-600 hover:bg-slate-50">Details</Link>
                                        {#if build.logs_pending}<span class="px-2 py-1.5 text-[10px] font-medium text-slate-400">Syncing logs…</span>{/if}
                                        {#if build.external_url}<a href={build.external_url} target="_blank" rel="noreferrer" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold text-slate-600 hover:bg-slate-50">Open run</a>{/if}
                                        {#if build.can_cancel}<button class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => cancelBuild(build)}>Cancel</button>{/if}
                                        {#if build.can_force_status}<button class="ghost px-2.5 py-1.5 text-[10px] text-amber-700" on:click={() => openForceStatus(build)}>Force status</button>{/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {:else}<div class="px-6 py-14 text-center"><p class="text-sm font-semibold">No builds yet</p><p class="mt-1 text-xs text-slate-500">Run a configured pipeline to create the first build.</p></div>{/if}
    </section>
</AppLayout>

<ForceStatusModal build={forcingBuild} onClose={closeForceStatus} />
