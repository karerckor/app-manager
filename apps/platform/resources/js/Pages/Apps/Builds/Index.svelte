<script>
    import { Link, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { Filter, Package } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import MultiSelectFilter from '../../../Components/MultiSelectFilter.svelte';
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
    export let filters = {};
    export let filterTags = [];
    export let filterPipelines = [];

    let forcingBuild = null;
    let tags = filters.tags ?? [];
    let statuses = filters.statuses ?? [];
    let startedFrom = filters.started_from ?? '';
    let startedTo = filters.started_to ?? '';
    let durationMin = filters.duration_min ?? '';
    let durationMax = filters.duration_max ?? '';
    let artifacts = filters.artifacts ?? '';
    let reportingMode = filters.reporting_mode ?? '';
    let selectedPipelines = filters.pipelines ?? [];

    const statusOptions = [
        ['pending', 'Pending'], ['running', 'Running'], ['cancel_requested', 'Cancel requested'],
        ['completed', 'Completed'], ['failed', 'Failed'], ['cancelled', 'Cancelled'],
    ].map(([value, label]) => ({ value, label }));
    $: tagOptions = filterTags.map((tag) => ({ value: tag[0], label: tag[0], color: tag[1] ?? null }));
    $: pipelineOptions = filterPipelines.map((item) => ({ value: item.id, label: item.name }));

    const cancelBuild = (build) => router.post(build.cancel_url, {}, { preserveScroll: true });
    const openForceStatus = (build) => forcingBuild = build;
    const closeForceStatus = () => forcingBuild = null;
    const visitFilters = () => router.get(app.urls.builds_page, {
        tags: tags.length ? tags : undefined,
        statuses: statuses.length ? statuses : undefined,
        started_from: startedFrom || undefined,
        started_to: startedTo || undefined,
        duration_min: durationMin === '' ? undefined : durationMin,
        duration_max: durationMax === '' ? undefined : durationMax,
        artifacts: artifacts || undefined,
        reporting_mode: reportingMode || undefined,
        pipelines: selectedPipelines.length ? selectedPipelines : undefined,
    }, {
        only: ['builds', 'filters', 'filterPipelines'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    const updateTags = (values) => {
        tags = values;
        visitFilters();
    };
    const updateStatuses = (values) => {
        statuses = values;
        visitFilters();
    };
    const updatePipelines = (values) => {
        selectedPipelines = values;
        visitFilters();
    };
    const selectArtifacts = (value) => {
        artifacts = value;
        visitFilters();
    };
    const selectReportingMode = (value) => {
        reportingMode = value;
        visitFilters();
    };
    const clearFilters = () => {
        tags = []; statuses = []; startedFrom = ''; startedTo = ''; durationMin = ''; durationMax = '';
        artifacts = ''; reportingMode = ''; selectedPipelines = [];
        visitFilters();
    };
    const segmentClass = (current, value) => current === value
        ? 'bg-surface-raised text-text shadow-sm'
        : 'bg-transparent text-muted hover:bg-surface-raised/60';
    const formatDuration = (startedAt, finishedAt) => {
        if (!startedAt || !finishedAt) return '—';

        const seconds = Math.max(0, Math.round((new Date(finishedAt) - new Date(startedAt)) / 1000));
        if (seconds < 60) return `${seconds}s`;

        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        if (minutes < 60) return remainingSeconds ? `${minutes}m ${remainingSeconds}s` : `${minutes}m`;

        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;

        return remainingMinutes ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
    };
    $: hasFilters = tags.length > 0 || statuses.length > 0 || selectedPipelines.length > 0
        || [startedFrom, startedTo, durationMin, durationMax, artifacts, reportingMode].some((value) => value !== '');
    $: hasAdvancedFilters = [startedFrom, startedTo, durationMin, durationMax].some((value) => value !== '');

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
        <div class="border-b border-border-subtle px-5 py-4"><h2 class="text-sm font-bold">Build history</h2><p class="mt-1 text-xs text-muted">Builds reported by CI providers and your own runners.</p></div>
        <div class="border-b border-border-subtle bg-surface-sunken/40 px-5 py-3.5">
            <div class="grid gap-2.5 md:grid-cols-3">
                <MultiSelectFilter label="Tags" options={tagOptions} values={tags} searchable searchPlaceholder="Search tags…" onChange={updateTags} />
                <MultiSelectFilter label="Statuses" options={statusOptions} values={statuses} onChange={updateStatuses} />
                <MultiSelectFilter label="Pipelines" options={pipelineOptions} values={selectedPipelines} searchable searchPlaceholder="Search pipelines…" onChange={updatePipelines} />
            </div>
            <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap gap-4">
                    <div>
                        <span class="label">Artifacts</span>
                        <div class="inline-flex rounded-sm bg-surface-sunken p-0.5" role="group" aria-label="Artifact filter">
                            {#each [['', 'Any'], ['with', 'Has'], ['without', 'Has not']] as option}
                                <button type="button" aria-pressed={artifacts === option[0]} class="rounded-sm px-3 py-1.5 text-xs font-semibold shadow-none transition {segmentClass(artifacts, option[0])}" on:click={() => selectArtifacts(option[0])}>{option[1]}</button>
                            {/each}
                        </div>
                    </div>
                    <div>
                        <span class="label">Reporting</span>
                        <div class="inline-flex rounded-sm bg-surface-sunken p-0.5" role="group" aria-label="Reporting mode filter">
                            {#each [['', 'All'], ['manual', 'Manual'], ['automatic', 'Automatic']] as option}
                                <button type="button" aria-pressed={reportingMode === option[0]} class="rounded-sm px-3 py-1.5 text-xs font-semibold shadow-none transition {segmentClass(reportingMode, option[0])}" on:click={() => selectReportingMode(option[0])}>{option[1]}</button>
                            {/each}
                        </div>
                    </div>
                </div>
                {#if hasFilters}<button class="ghost px-3 py-2 text-xs" on:click={clearFilters}>Clear all</button>{/if}
            </div>
            <details class="mt-3 group" open={hasAdvancedFilters}>
                <summary class="flex w-fit cursor-pointer list-none items-center gap-1.5 text-[11px] font-semibold text-muted hover:text-text"><Filter class="h-3.5 w-3.5" />Date and duration <span class="text-faint group-open:hidden">(optional)</span></summary>
                <div class="mt-2.5 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label><span class="label">Started after</span><input class="py-2 text-xs" type="date" bind:value={startedFrom} on:change={visitFilters} /></label>
                    <label><span class="label">Started before</span><input class="py-2 text-xs" type="date" bind:value={startedTo} on:change={visitFilters} /></label>
                    <label><span class="label">Min. duration (min)</span><input class="py-2 text-xs" type="number" min="0" placeholder="No minimum" bind:value={durationMin} on:change={visitFilters} /></label>
                    <label><span class="label">Max. duration (min)</span><input class="py-2 text-xs" type="number" min="0" placeholder="No maximum" bind:value={durationMax} on:change={visitFilters} /></label>
                </div>
            </details>
        </div>
        {#if builds.length}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1040px] text-left text-xs">
                    <thead class="bg-surface-sunken text-[10px] font-bold uppercase text-faint"><tr><th class="px-5 py-3">Pipeline</th><th class="px-4 py-3">Profile</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Artifacts</th><th class="px-4 py-3">Started</th><th class="px-4 py-3">Duration</th><th class="px-4 py-3">Finished</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-border-subtle">
                        {#each builds as build (build.id)}
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2"><span class="font-semibold">{build.pipeline_name || 'Manual build'}</span><span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold text-muted uppercase">{build.reporting_mode}</span></div>
                                    <div class="mt-1.5"><TagEditor tags={build.tags} url={build.tags_url} suggestions={allTags} /></div>
                                </td>
                                <td class="px-4 py-4 text-muted">{build.profile_type}</td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {buildStatusClass(build.status)}">{build.status.replaceAll('_', ' ')}</span></td>
                                <td class="px-4 py-4">
                                    {#if build.artifacts.length}
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-soft px-2.5 py-1 text-[10px] font-semibold text-brand"><Package class="h-3 w-3" />{build.artifacts.length} attached</span>
                                    {:else}
                                        <span class="inline-flex items-center gap-1.5 text-[10px] font-medium text-faint"><Package class="h-3 w-3" strokeWidth={1.8} />None</span>
                                    {/if}
                                </td>
                                <td class="px-4 py-4 text-muted">{formatDateTime(build.started_at || build.created_at)}</td>
                                <td class="px-4 py-4 font-mono text-[11px] tabular-nums {build.finished_at ? 'text-text' : 'text-faint'}">{formatDuration(build.started_at, build.finished_at)}</td>
                                <td class="px-4 py-4 text-muted">{formatDateTime(build.finished_at)}</td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <Link href={build.show_url} class="rounded-md border border-border px-2.5 py-1.5 text-[10px] font-semibold text-muted hover:bg-surface-sunken">Details</Link>
                                        {#if build.logs_pending}<span class="px-2 py-1.5 text-[10px] font-medium text-faint">Syncing logs…</span>{/if}
                                        {#if build.external_url}<a href={build.external_url} target="_blank" rel="noreferrer" class="rounded-md border border-border px-2.5 py-1.5 text-[10px] font-semibold text-muted hover:bg-surface-sunken">Open run</a>{/if}
                                        {#if build.can_cancel}<button class="ghost px-2.5 py-1.5 text-[10px] text-danger" on:click={() => cancelBuild(build)}>Cancel</button>{/if}
                                        {#if build.can_force_status}<button class="ghost px-2.5 py-1.5 text-[10px] text-warn-fill" on:click={() => openForceStatus(build)}>Force status</button>{/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {:else}<div class="px-6 py-14 text-center"><p class="text-sm font-semibold">{hasFilters ? 'No builds match these filters' : 'No builds yet'}</p><p class="mt-1 text-xs text-muted">{hasFilters ? 'Adjust or clear filters to see more builds.' : 'Run a configured pipeline to create the first build.'}</p>{#if hasFilters}<button class="ghost mt-3 px-3 py-2 text-xs" on:click={clearFilters}>Clear filters</button>{/if}</div>{/if}
    </section>
</AppLayout>

<ForceStatusModal build={forcingBuild} onClose={closeForceStatus} />
