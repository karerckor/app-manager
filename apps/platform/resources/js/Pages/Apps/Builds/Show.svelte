<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { ArrowLeft, Download, Trash2, Upload, Package } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import TagEditor from '../../../Components/TagEditor.svelte';
    import ForceStatusModal from '../Modals/ForceStatusModal.svelte';
    import { formatDateTime, formatParameters, formatSize, buildStatusClass } from '../lib.js';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'builds';
    export let build;

    let forcingBuild = null;

    const artifactForm = useForm({ name: '', metadata: '', file: null });

    const uploadArtifact = () => artifactForm
        .transform((data) => ({ ...data, metadata: data.metadata?.trim() ? data.metadata : null }))
        .post(build.artifacts_url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => artifactForm.reset(),
        });

    const deleteArtifact = (artifact) => router.delete(artifact.delete_url, { preserveScroll: true });

    const cancelBuild = () => router.post(build.cancel_url, {}, { preserveScroll: true });
    const openForceStatus = () => forcingBuild = build;
    const closeForceStatus = () => forcingBuild = null;

    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
        push(app.tags);
        push(build.tags);
        (build.artifacts || []).forEach((artifact) => push(artifact.tags));
        return acc;
    })();

    onMount(() => {
        const timer = setInterval(() => {
            const waiting = !['completed', 'failed', 'cancelled'].includes(build.status) || build.logs_pending;

            if (waiting) {
                router.reload({ only: ['build'], preserveScroll: true, preserveState: true });
            }
        }, 5000);

        return () => clearInterval(timer);
    });
</script>

<svelte:head><title>Build #{build.id} · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} {navigationTeams} {navigationTeam} {active}>
    <Link href={app.urls.builds_page} class="mb-5 inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-indigo-600"><ArrowLeft class="h-3.5 w-3.5" strokeWidth={2.2} /> Back to builds</Link>

    <div class="card mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold tracking-tight">{build.pipeline_name || 'Manual build'}</h1>
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 uppercase">{build.reporting_mode}</span>
                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {buildStatusClass(build.status)}">{build.status.replaceAll('_', ' ')}</span>
                </div>
                <p class="mt-2 text-xs text-slate-500">Profile <span class="font-medium text-slate-700">{build.profile_type}</span> · Started {formatDateTime(build.started_at)} · Finished {formatDateTime(build.finished_at)}</p>
                <div class="mt-2.5"><TagEditor tags={build.tags} url={build.tags_url} suggestions={allTags} /></div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {#if build.logs_pending}<span class="px-2 py-1.5 text-[10px] font-medium text-slate-400">Syncing logs…</span>{/if}
                {#if build.external_url}<a href={build.external_url} target="_blank" rel="noreferrer" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold text-slate-600 hover:bg-slate-50">Open run</a>{/if}
                {#if build.can_cancel && auth?.team?.permissions?.run_pipelines}<button class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={cancelBuild}>Cancel</button>{/if}
                {#if build.can_force_status}<button class="ghost px-2.5 py-1.5 text-[10px] text-amber-700" on:click={openForceStatus}>Force status</button>{/if}
            </div>
        </div>
    </div>

    <div class="grid gap-4 {build.logs ? 'lg:grid-cols-2' : ''}">
        <div><p class="mb-2 text-[10px] font-bold tracking-wider text-slate-500 uppercase">Launch parameters</p><pre class="max-h-[24rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 px-4 py-3 font-mono text-[11px] leading-5 text-slate-200">{formatParameters(build.parameters)}</pre></div>
        {#if build.logs}<div><p class="mb-2 text-[10px] font-bold tracking-wider text-slate-500 uppercase">Logs</p><pre class="max-h-[24rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 px-4 py-3 font-mono text-[11px] leading-5 text-slate-200">{build.logs}</pre></div>{/if}
    </div>

    <section class="card mt-6">
        <div class="mb-2.5 flex items-center justify-between"><p class="text-[10px] font-bold tracking-wider text-slate-500 uppercase">Artifacts</p>{#if build.artifacts.length}<span class="text-[10px] font-medium text-slate-400">{build.artifacts.length} attached</span>{/if}</div>

        {#if build.artifacts.length === 0}
            <p class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-3 text-xs text-slate-500">No artifacts attached to this build yet.</p>
        {:else}
            <ul class="space-y-2">
                {#each build.artifacts as artifact (artifact.id)}
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600"><Package class="h-4 w-4" strokeWidth={1.8} /></span>
                            <div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-900">{artifact.name}</p><p class="mt-0.5 text-[10px] text-slate-400">{artifact.size != null ? formatSize(artifact.size) : 'Unknown size'}</p></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <TagEditor tags={artifact.tags} url={artifact.tags_url} suggestions={allTags} />
                            <a href={artifact.download_url} class="grid h-7 w-7 place-items-center rounded-md border border-slate-200 bg-white text-slate-500 hover:text-indigo-600" title="Download" aria-label="Download artifact"><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                            {#if build.can_manage_artifacts}
                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-slate-200 bg-white p-0 text-rose-500 shadow-none hover:text-rose-600" title="Delete" aria-label="Delete artifact" on:click={() => deleteArtifact(artifact)}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}

        {#if build.can_manage_artifacts}
            <form class="mt-3 rounded-xl border border-slate-200 bg-white p-3.5" on:submit|preventDefault={uploadArtifact}>
                <p class="mb-3 text-xs font-semibold text-slate-700">Attach a new artifact</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label><span class="label">Name</span><input type="text" bind:value={artifactForm.name} placeholder="e.g. app-release.ipa" required />{#if artifactForm.errors.name}<span class="error">{artifactForm.errors.name}</span>{/if}</label>
                    <label><span class="label">Metadata (JSON) <span class="font-normal text-slate-400">(optional)</span></span><input type="text" class="font-mono text-xs" bind:value={artifactForm.metadata} placeholder={'{ "channel": "internal" }'} />{#if artifactForm.errors.metadata}<span class="error">{artifactForm.errors.metadata}</span>{/if}</label>
                </div>
                <label class="mt-3 flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 transition hover:border-indigo-400 hover:bg-indigo-50/40">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white text-indigo-600 shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                    <span class="min-w-0"><strong class="block truncate text-xs text-slate-700">{artifactForm.file?.name || 'Choose a file'}</strong><small class="text-[10px] text-slate-400">The build output to store and share.</small></span>
                    <input type="file" class="hidden" on:change={(e) => (artifactForm.file = e.currentTarget.files?.[0] ?? null)} />
                </label>
                {#if artifactForm.errors.file}<span class="error">{artifactForm.errors.file}</span>{/if}
                <div class="mt-3 flex justify-end"><button type="submit" class="px-3 py-2 text-xs" disabled={artifactForm.processing || !artifactForm.file}>{artifactForm.processing ? 'Uploading…' : 'Upload artifact'}</button></div>
            </form>
        {/if}
    </section>
</AppLayout>

<ForceStatusModal build={forcingBuild} onClose={closeForceStatus} />
