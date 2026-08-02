<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import {
        ArrowLeft,
        Check,
        ChevronUp,
        Clock3,
        Copy,
        Download,
        ExternalLink,
        FileArchive,
        Hash,
        Package,
        Search,
        SlidersHorizontal,
        Terminal,
        Trash2,
        Upload,
        X,
    } from '@lucide/svelte';
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
    export let tag_suggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'builds';
    export let build;

    let forcingBuild = null;
    let logQuery = '';
    let copiedLogs = false;
    let showArtifactForm = false;

    const terminalStatuses = ['completed', 'failed', 'cancelled'];
    const artifactForm = useForm({ name: '', metadata: '', file: null });

    const uploadArtifact = () => artifactForm
        .transform((data) => ({ ...data, metadata: data.metadata?.trim() ? data.metadata : null }))
        .post(build.artifacts_url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                artifactForm.reset();
                showArtifactForm = false;
            },
        });

    const deleteArtifact = (artifact) => router.delete(artifact.delete_url, { preserveScroll: true });

    const cancelBuild = () => router.post(build.cancel_url, {}, { preserveScroll: true });
    const openForceStatus = () => forcingBuild = build;
    const closeForceStatus = () => forcingBuild = null;

    const formatDuration = (startedAt, finishedAt) => {
        if (!startedAt) return 'Not started';

        const start = new Date(startedAt).getTime();
        const end = finishedAt ? new Date(finishedAt).getTime() : Date.now();
        const seconds = Math.max(0, Math.round((end - start) / 1000));

        if (seconds < 60) return `${seconds}s`;

        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;

        if (minutes < 60) return remainingSeconds ? `${minutes}m ${remainingSeconds}s` : `${minutes}m`;

        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;

        return remainingMinutes ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
    };

    const copyLogs = async () => {
        if (!build.logs || typeof navigator === 'undefined') return;

        await navigator.clipboard.writeText(build.logs);
        copiedLogs = true;
        setTimeout(() => copiedLogs = false, 1800);
    };

    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
        push(app.tags);
        push(build.tags);
        (build.artifacts || []).forEach((artifact) => push(artifact.tags));
        return acc;
    })();

    $: logLines = build.logs ? build.logs.split('\n') : [];
    $: normalizedLogQuery = logQuery.trim().toLowerCase();
    $: matchingLogLines = normalizedLogQuery
        ? logLines.filter((line) => line.toLowerCase().includes(normalizedLogQuery))
        : logLines;
    $: visibleLogs = matchingLogLines.join('\n');
    $: buildDuration = formatDuration(build.started_at, build.finished_at);
    $: shortBuildId = build.id?.slice(0, 8) || '—';

    onMount(() => {
        const timer = setInterval(() => {
            const waiting = !terminalStatuses.includes(build.status) || build.logs_pending;

            if (waiting) {
                router.reload({ only: ['build'], preserveScroll: true, preserveState: true });
            }
        }, 5000);

        return () => clearInterval(timer);
    });
</script>

<svelte:head><title>Build #{build.id} · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <Link href={app.urls.builds_page} class="mb-4 inline-flex items-center gap-1.5 text-xs font-semibold text-muted transition hover:text-brand">
        <ArrowLeft class="h-3.5 w-3.5" strokeWidth={2.2} />
        All builds
    </Link>

    <section class="relative mb-5 overflow-hidden rounded-md border border-border bg-surface-raised shadow-[0_1px_2px_rgba(15,23,42,0.03),0_10px_30px_rgba(15,23,42,0.04)]">
        <div class="absolute inset-y-0 left-0 w-1 {build.status === 'failed' ? 'bg-danger' : build.status === 'completed' ? 'bg-ok' : build.status === 'cancelled' ? 'bg-faint' : 'bg-brand'}"></div>
        <div class="flex flex-wrap items-start justify-between gap-5 px-6 py-5">
            <div class="min-w-0">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold capitalize {buildStatusClass(build.status)}">
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        {build.status.replaceAll('_', ' ')}
                    </span>
                    <span class="rounded-md bg-border-subtle px-2 py-1 text-[9px] font-bold tracking-[0.08em] text-muted uppercase">{build.reporting_mode}</span>
                    {#if build.logs_pending}
                        <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold text-brand">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand"></span>
                            Syncing logs
                        </span>
                    {/if}
                </div>
                <h1 class="break-words text-xl font-bold tracking-tight text-text">{build.pipeline_name || 'Manual build'}</h1>
                <p class="mt-1 text-xs text-muted">Build <span class="font-mono font-semibold text-text">#{shortBuildId}</span> for {app.name}</p>
                <div class="mt-3"><TagEditor tags={build.tags} url={build.tags_url} suggestions={allTags} /></div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {#if build.external_url}
                    <a href={build.external_url} target="_blank" rel="noreferrer" class="secondary inline-flex items-center gap-2 px-3 py-2 text-xs">
                        Open run
                        <ExternalLink class="h-3.5 w-3.5" strokeWidth={2} />
                    </a>
                {/if}
                {#if build.expo_build_url}
                    <a href={build.expo_build_url} target="_blank" rel="noreferrer" class="secondary inline-flex items-center gap-2 px-3 py-2 text-xs">
                        Open build in EAS
                        <ExternalLink class="h-3.5 w-3.5" strokeWidth={2} />
                    </a>
                {/if}
                {#if build.can_cancel}<button class="ghost px-3 py-2 text-xs text-danger" on:click={cancelBuild}>Cancel build</button>{/if}
                {#if build.can_force_status}<button class="ghost px-3 py-2 text-xs text-warn-fill" on:click={openForceStatus}>Force status</button>{/if}
            </div>
        </div>

        <div class="grid grid-cols-2 border-t border-border bg-surface-sunken lg:grid-cols-4">
            <div class="flex items-center gap-3 border-r border-b border-border px-5 py-3.5 lg:border-b-0">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-raised text-muted shadow-sm ring-1 ring-border"><SlidersHorizontal class="h-4 w-4" strokeWidth={1.9} /></span>
                <div><p class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Profile</p><p class="mt-0.5 text-xs font-semibold capitalize text-text">{build.profile_type}</p></div>
            </div>
            <div class="flex items-center gap-3 border-b border-border px-5 py-3.5 lg:border-r lg:border-b-0">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-raised text-muted shadow-sm ring-1 ring-border"><Clock3 class="h-4 w-4" strokeWidth={1.9} /></span>
                <div><p class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Duration</p><p class="mt-0.5 text-xs font-semibold text-text">{buildDuration}</p></div>
            </div>
            <div class="flex items-center gap-3 border-r border-border px-5 py-3.5">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-raised text-muted shadow-sm ring-1 ring-border"><Terminal class="h-4 w-4" strokeWidth={1.9} /></span>
                <div><p class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Log output</p><p class="mt-0.5 text-xs font-semibold text-text">{build.log_archive_url ? `${formatSize(build.log_archive_size)} archive` : `${logLines.length} ${logLines.length === 1 ? 'line' : 'lines'}`}</p></div>
            </div>
            <div class="flex items-center gap-3 px-5 py-3.5">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-raised text-muted shadow-sm ring-1 ring-border"><Package class="h-4 w-4" strokeWidth={1.9} /></span>
                <div><p class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Artifacts</p><p class="mt-0.5 text-xs font-semibold text-text">{build.artifacts.length} attached</p></div>
            </div>
        </div>
    </section>

    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
        <section class="min-w-0 overflow-hidden rounded-md border border-slate-800 bg-slate-950 shadow-[0_16px_40px_rgba(15,23,42,0.12)]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-slate-900 px-4 py-3">
                <div class="flex items-center gap-2.5">
                    <span class="flex gap-1.5" aria-hidden="true"><i class="h-2.5 w-2.5 rounded-full bg-rose-400/80"></i><i class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></i><i class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></i></span>
                    <span class="h-4 w-px bg-white/10"></span>
                    <div>
                        <h2 class="text-[11px] font-bold tracking-wide text-slate-200">Build logs</h2>
                        <p class="text-[9px] text-slate-500">{build.logs_pending ? 'Waiting for the latest output…' : build.log_archive_url ? `Archived ${formatDateTime(build.logs_archived_at)}` : 'Latest output'}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    {#if build.logs}
                        <label class="relative block">
                            <span class="sr-only">Search logs</span>
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-500" strokeWidth={2} />
                            <input class="h-8 w-36 rounded-lg border-white/10 bg-white/5 py-1.5 pl-8 pr-7 font-sans text-[10px] text-slate-200 shadow-none placeholder:text-slate-500 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-400/20 sm:w-44" type="search" bind:value={logQuery} placeholder="Filter output…" />
                            {#if logQuery}
                                <button type="button" class="absolute right-1.5 top-1/2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded bg-transparent p-0 text-slate-500 shadow-none hover:bg-white/10 hover:text-slate-300" on:click={() => logQuery = ''} aria-label="Clear log filter"><X class="h-3 w-3" /></button>
                            {/if}
                        </label>
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-white/10 bg-white/5 p-0 text-slate-400 shadow-none hover:bg-white/10 hover:text-white" on:click={copyLogs} aria-label="Copy logs" title="Copy logs">
                            {#if copiedLogs}<Check class="h-3.5 w-3.5 text-emerald-400" strokeWidth={2.2} />{:else}<Copy class="h-3.5 w-3.5" strokeWidth={2} />{/if}
                        </button>
                    {/if}
                </div>
            </div>

            {#if build.logs}
                {#if normalizedLogQuery && matchingLogLines.length === 0}
                    <div class="grid min-h-[32rem] place-items-center px-6 text-center">
                        <div>
                            <Search class="mx-auto h-6 w-6 text-text" strokeWidth={1.6} />
                            <p class="mt-3 text-xs font-semibold text-slate-300">No matching log lines</p>
                            <p class="mt-1 text-[10px] text-slate-500">Try a different search term.</p>
                        </div>
                    </div>
                {:else}
                    <pre class="min-h-[32rem] max-h-[42rem] overflow-auto whitespace-pre-wrap break-words px-5 py-4 font-mono text-[11px] leading-[1.75] text-slate-300 selection:bg-indigo-500/40">{visibleLogs}</pre>
                {/if}
                {#if normalizedLogQuery}
                    <div class="border-t border-white/10 bg-slate-900 px-4 py-2 text-[9px] font-medium text-slate-500">{matchingLogLines.length} of {logLines.length} lines match “{logQuery.trim()}”</div>
                {/if}
            {:else}
                <div class="grid min-h-[32rem] place-items-center px-6 text-center">
                    <div class="max-w-xs">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-white/5 text-slate-500 ring-1 ring-white/10">{#if build.log_archive_url}<FileArchive class="h-5 w-5" strokeWidth={1.7} />{:else}<Terminal class="h-5 w-5" strokeWidth={1.7} />{/if}</span>
                        <p class="mt-4 text-sm font-semibold text-slate-200">{build.logs_pending ? 'Logs are syncing' : build.log_archive_url ? 'Logs moved to archive' : 'No log output'}</p>
                        <p class="mt-1.5 text-[11px] leading-5 text-slate-500">{build.logs_pending ? 'This view refreshes automatically when new output arrives.' : build.log_archive_url ? 'This log is older than the retention window. Download the original text file when you need it.' : 'This build did not report any log output.'}</p>
                        {#if build.log_archive_url}
                            <a class="mt-4 inline-flex h-8 items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 text-[10px] font-semibold text-slate-300 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" href={build.log_archive_url}>
                                <Download class="h-3.5 w-3.5" strokeWidth={2} />
                                Download log archive
                            </a>
                        {/if}
                    </div>
                </div>
            {/if}
        </section>

        <aside class="space-y-4">
            <section class="overflow-hidden rounded-md border border-border bg-surface-raised shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="border-b border-border-subtle px-4 py-3.5">
                    <h2 class="text-xs font-bold text-text">Run details</h2>
                </div>
                <dl class="divide-y divide-border-subtle px-4">
                    <div class="py-3">
                        <dt class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Started</dt>
                        <dd class="mt-1 text-[11px] font-medium leading-4 text-text">{formatDateTime(build.started_at || build.created_at)}</dd>
                    </div>
                    <div class="py-3">
                        <dt class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Finished</dt>
                        <dd class="mt-1 text-[11px] font-medium leading-4 text-text">{formatDateTime(build.finished_at)}</dd>
                    </div>
                    {#if build.provider_status}
                        <div class="py-3">
                            <dt class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Provider status</dt>
                            <dd class="mt-1 text-[11px] font-medium capitalize text-text">{build.provider_status.replaceAll('_', ' ')}</dd>
                        </div>
                    {/if}
                    <div class="py-3">
                        <dt class="text-[9px] font-bold tracking-[0.1em] text-faint uppercase">Build ID</dt>
                        <dd class="mt-1 flex items-center gap-1.5 break-all font-mono text-[10px] leading-4 text-muted"><Hash class="h-3 w-3 shrink-0 text-faint" />{build.id}</dd>
                    </div>
                </dl>
            </section>

            <section class="overflow-hidden rounded-md border border-border bg-surface-raised shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex items-center justify-between border-b border-border-subtle px-4 py-3.5">
                    <h2 class="text-xs font-bold text-text">Launch parameters</h2>
                    <span class="rounded bg-border-subtle px-1.5 py-0.5 text-[8px] font-bold text-faint">JSON</span>
                </div>
                <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words bg-surface-sunken px-4 py-3.5 font-mono text-[10px] leading-[1.7] text-muted">{formatParameters(build.parameters)}</pre>
            </section>
        </aside>
    </div>

    <section class="mt-5 overflow-hidden rounded-md border border-border bg-surface-raised shadow-[0_1px_2px_rgba(15,23,42,0.03),0_8px_24px_rgba(15,23,42,0.035)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-md bg-brand-soft text-brand"><FileArchive class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                <div>
                    <h2 class="text-sm font-bold text-text">Artifacts</h2>
                    <p class="mt-0.5 text-[10px] text-muted">{build.artifacts.length ? `${build.artifacts.length} build ${build.artifacts.length === 1 ? 'output' : 'outputs'} available` : 'Files produced by this build'}</p>
                </div>
            </div>
            {#if build.can_manage_artifacts}
                <button type="button" class="secondary flex items-center gap-2 px-3 py-2 text-xs" on:click={() => showArtifactForm = !showArtifactForm} aria-expanded={showArtifactForm}>
                    {#if showArtifactForm}<ChevronUp class="h-3.5 w-3.5" /> Hide uploader{:else}<Upload class="h-3.5 w-3.5" /> Add artifact{/if}
                </button>
            {/if}
        </div>

        {#if build.artifacts.length === 0}
            <div class="px-5 py-7 text-center">
                <span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-surface-sunken text-faint ring-1 ring-border"><Package class="h-4.5 w-4.5" strokeWidth={1.7} /></span>
                <p class="mt-3 text-xs font-semibold text-text">No artifacts attached</p>
                <p class="mt-1 text-[10px] text-faint">Upload an APK, IPA, archive, or any other build output.</p>
            </div>
        {:else}
            <ul class="divide-y divide-border-subtle">
                {#each build.artifacts as artifact (artifact.id)}
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 transition hover:bg-surface-sunken">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-ok/10 text-ok"><Package class="h-4 w-4" strokeWidth={1.8} /></span>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-text">{artifact.name}</p>
                                <p class="mt-0.5 text-[10px] text-faint">{artifact.size != null ? formatSize(artifact.size) : 'Unknown size'}{artifact.mime ? ` · ${artifact.mime}` : ''}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <TagEditor tags={artifact.tags} url={artifact.tags_url} suggestions={allTags} />
                            <a href={artifact.download_url} class="grid h-8 w-8 place-items-center rounded-md border border-border bg-surface-raised text-muted transition hover:border-brand-soft-line hover:text-brand" title="Download" aria-label="Download artifact"><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                            {#if build.can_manage_artifacts}
                                <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-border bg-surface-raised p-0 text-faint shadow-none hover:border-rose-200 hover:bg-danger/10 hover:text-danger" title="Delete" aria-label="Delete artifact" on:click={() => deleteArtifact(artifact)}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}

        {#if build.can_manage_artifacts && showArtifactForm}
            <form class="border-t border-border bg-surface-sunken p-5" on:submit|preventDefault={uploadArtifact}>
                <div class="mb-4">
                    <h3 class="text-xs font-bold text-text">Attach a new artifact</h3>
                    <p class="mt-1 text-[10px] text-muted">Store a build output so your team can download it later.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label><span class="label text-xs">Name</span><input type="text" bind:value={artifactForm.name} placeholder="e.g. app-release.ipa" required />{#if artifactForm.errors.name}<span class="error">{artifactForm.errors.name}</span>{/if}</label>
                    <label><span class="label text-xs">Metadata (JSON) <span class="font-normal text-faint">(optional)</span></span><input type="text" class="font-mono text-xs" bind:value={artifactForm.metadata} placeholder={'{ "channel": "internal" }'} />{#if artifactForm.errors.metadata}<span class="error">{artifactForm.errors.metadata}</span>{/if}</label>
                </div>
                <label class="mt-3 flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-control bg-surface-raised px-4 py-3 transition hover:border-brand hover:bg-brand-soft/40">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-brand-soft text-brand"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                    <span class="min-w-0"><strong class="block truncate text-xs text-text">{artifactForm.file?.name || 'Choose a file'}</strong><small class="text-[10px] text-faint">APK, IPA, archive, or any other build output.</small></span>
                    <input type="file" class="hidden" on:change={(e) => (artifactForm.file = e.currentTarget.files?.[0] ?? null)} />
                </label>
                {#if artifactForm.errors.file}<span class="error">{artifactForm.errors.file}</span>{/if}
                <div class="mt-3 flex justify-end"><button type="submit" class="px-3 py-2 text-xs" disabled={artifactForm.processing || !artifactForm.file}>{artifactForm.processing ? 'Uploading…' : 'Upload artifact'}</button></div>
            </form>
        {/if}
    </section>
</AppLayout>

<ForceStatusModal build={forcingBuild} onClose={closeForceStatus} />
