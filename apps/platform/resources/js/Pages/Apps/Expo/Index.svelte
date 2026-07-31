<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { ExternalLink, RefreshCw, Unlink, Cloud, KeyRound, PackageCheck } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import { formatDateTime } from '../lib.js';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let tag_suggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'expo';
    export let expo = {};

    let selectedIntegrationId = expo.accounts?.[0]?.id ?? '';
    let setupMode = 'create';
    let showSubmission = false;
    let submittingBuild = null;

    const setupForm = useForm({
        integration_id: selectedIntegrationId,
        mode: setupMode,
        expo_project_id: '',
        sync_metadata: true,
    });
    const submissionForm = useForm({
        build_id: '',
        platform: '',
        application_identifier: '',
        track: 'INTERNAL',
        release_status: 'COMPLETED',
        google_service_account_key_id: '',
        asc_api_key_id: '',
    });
    const actionForm = useForm({});

    $: selectedAccount = expo.accounts?.find((account) => account.id === setupForm.integration_id);
    $: availableProjects = selectedAccount?.projects ?? [];
    $: androidCredentials = expo.remote?.credentials?.android ?? [];
    $: iosCredentials = expo.remote?.credentials?.ios ?? [];

    const setup = () => setupForm.post(expo.setup_url);
    const sync = () => router.post(expo.sync_url, {}, { preserveScroll: true });
    const unlink = () => {
        if (confirm('Unlink this application? The Expo project and its data will not be deleted.')) {
            router.delete(expo.unlink_url, { preserveScroll: true });
        }
    };
    const cancelBuild = (build) => actionForm.post(build.cancel_url, { preserveScroll: true });
    const retryBuild = (build) => actionForm.post(build.retry_url, { preserveScroll: true });
    const cancelSubmission = (submission) => actionForm.post(submission.cancel_url, { preserveScroll: true });
    const retrySubmission = (submission) => actionForm.post(submission.retry_url, { preserveScroll: true });
    const openSubmission = (build) => {
        submittingBuild = build;
        submissionForm.reset();
        submissionForm.build_id = build.id;
        submissionForm.platform = build.platform;
        submissionForm.application_identifier = build.appIdentifier
            || identifiers.find((identifier) => identifier.platform === build.platform.toLowerCase())?.identifier
            || '';
        submissionForm.track = 'INTERNAL';
        submissionForm.release_status = 'COMPLETED';
        showSubmission = true;
    };
    const submit = () => submissionForm.post(submittingBuild.submit_url, {
        preserveScroll: true,
        onSuccess: () => {
            showSubmission = false;
            submittingBuild = null;
        },
    });
    const statusClass = (status) => ({
        FINISHED: 'bg-emerald-50 text-emerald-700',
        IN_PROGRESS: 'bg-sky-50 text-sky-700',
        IN_QUEUE: 'bg-indigo-50 text-indigo-700',
        NEW: 'bg-indigo-50 text-indigo-700',
        AWAITING_BUILD: 'bg-indigo-50 text-indigo-700',
        PENDING_CANCEL: 'bg-amber-50 text-amber-700',
        ERRORED: 'bg-rose-50 text-rose-700',
        CANCELED: 'bg-slate-100 text-slate-600',
    }[status] ?? 'bg-slate-100 text-slate-600');
    const canCancel = (status) => ['NEW', 'IN_QUEUE', 'IN_PROGRESS', 'PENDING_CANCEL', 'AWAITING_BUILD'].includes(status);
    const firstGoogleKey = () => androidCredentials
        .map((item) => item.googleServiceAccountKeyForSubmissions)
        .find(Boolean)?.id ?? '';
    const firstAscKey = () => iosCredentials
        .map((item) => item.appStoreConnectApiKeyForSubmissions)
        .find(Boolean)?.id ?? '';

    onMount(() => {
        const timer = setInterval(() => {
            const waitingBuild = expo.remote?.builds?.some((build) => canCancel(build.status));
            const waitingSubmission = expo.remote?.submissions?.some((submission) => canCancel(submission.status));
            if (waitingBuild || waitingSubmission) {
                router.reload({ only: ['expo'], preserveScroll: true, preserveState: true });
            }
        }, 10000);

        return () => clearInterval(timer);
    });
</script>

<svelte:head><title>Expo EAS · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    {#if !expo.linked}
        <section class="card mx-auto max-w-2xl">
            <div class="flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-slate-950 text-xs font-black text-white">EAS</span>
                <div><h2 class="text-lg font-bold">Set up on Expo</h2><p class="mt-1 text-sm leading-6 text-slate-500">Link an existing Expo project, or create one together with this application's identifiers and supported EAS credentials.</p></div>
            </div>

            {#if expo.accounts?.length}
                <form class="mt-6 space-y-5" on:submit|preventDefault={setup}>
                    <label><span class="label">Expo account</span><select bind:value={setupForm.integration_id} on:change={() => setupForm.expo_project_id = ''}>{#each expo.accounts as account}<option value={account.id}>{account.name}</option>{/each}</select>{#if setupForm.errors.integration_id}<span class="error">{setupForm.errors.integration_id}</span>{/if}</label>
                    <fieldset><legend class="label">Project</legend><div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 {setupForm.mode === 'create' ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200'}"><input class="mt-0.5 h-4 w-4" type="radio" bind:group={setupForm.mode} value="create" /><span><strong class="block text-sm">Create a new project</strong><span class="mt-1 block text-xs text-slate-500">Creates <code>@{selectedAccount?.name}/{app.slug}</code>, app identifiers, and supported credentials.</span></span></label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 {setupForm.mode === 'existing' ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200'}"><input class="mt-0.5 h-4 w-4" type="radio" bind:group={setupForm.mode} value="existing" disabled={!availableProjects.length} /><span><strong class="block text-sm">Link an existing project</strong><span class="mt-1 block text-xs text-slate-500">{availableProjects.length ? `${availableProjects.length} unlinked project${availableProjects.length === 1 ? '' : 's'}` : 'No unlinked projects in this account.'}</span></span></label>
                    </div></fieldset>
                    {#if setupForm.mode === 'existing'}<label><span class="label">Expo project</span><select bind:value={setupForm.expo_project_id} required><option value="" disabled>Select a project</option>{#each availableProjects as project}<option value={project.id}>{project.name} · {project.full_name}</option>{/each}</select>{#if setupForm.errors.expo_project_id}<span class="error">{setupForm.errors.expo_project_id}</span>{/if}</label>{/if}
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4"><input class="mt-0.5 h-4 w-4" type="checkbox" bind:checked={setupForm.sync_metadata} /><span><strong class="block text-sm">Sync metadata from Expo</strong><span class="mt-1 block text-xs text-slate-500">Expo display name and description update the local application during an explicit sync.</span></span></label>
                    {#if setupForm.errors.expo}<span class="error">{setupForm.errors.expo}</span>{/if}
                    <div class="flex justify-end"><button disabled={setupForm.processing || (setupForm.mode === 'existing' && !setupForm.expo_project_id)}>{setupForm.processing ? 'Setting up…' : 'Set up on Expo'}</button></div>
                </form>
            {:else}
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4"><p class="text-sm font-semibold text-amber-900">Connect an Expo account first.</p><p class="mt-1 text-xs text-amber-700">After connecting, this application can be linked without changing any other apps.</p><Link href={expo.integrations_url} class="mt-3 inline-flex rounded-lg bg-amber-900 px-3 py-2 text-xs font-semibold text-white">Open integrations</Link></div>
            {/if}
        </section>
    {:else}
        <div class="card mb-5 flex flex-wrap items-center justify-between gap-4 border-violet-200/80 bg-violet-50/40 px-4 py-3.5">
            <div class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-lg bg-violet-100 text-[9px] font-black text-violet-700">EAS</span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold">{expo.project.name}</h2><span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">LINKED</span></div><code class="mt-0.5 block text-[11px] text-slate-500">{expo.project.full_name}</code></div></div>
            <div class="flex flex-wrap gap-2"><a href={expo.project.url} target="_blank" rel="noreferrer" class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"><ExternalLink class="h-3.5 w-3.5" /> Open Expo</a>{#if expo.can_manage && expo.can_manage_credentials}<button class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={sync}><RefreshCw class="h-3.5 w-3.5" /> Pull from Expo</button>{/if}{#if expo.can_manage}<button class="ghost inline-flex items-center gap-1.5 px-3 py-2 text-xs text-rose-600" on:click={unlink}><Unlink class="h-3.5 w-3.5" /> Unlink</button>{/if}</div>
        </div>

        {#if !expo.project.exists_on_expo}<div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">This project was not found during the last Expo account sync. The local link is preserved.</div>{:else if expo.project.connection_status !== 'active'}<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Reconnect the Expo account to load credentials, builds, and submissions.</div>{:else if expo.error}<div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{expo.error}</div>{/if}
        {#if setupForm.errors.expo}<div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">{setupForm.errors.expo}</div>{/if}
        {#if actionForm.errors.expo}<div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{actionForm.errors.expo}</div>{/if}

        {#if expo.remote}
            <section class="card mb-5 grid overflow-clip p-0 sm:grid-cols-3 sm:divide-x sm:divide-slate-100">
                <div class="flex items-center gap-3 px-4 py-3.5"><span class="grid h-8 w-8 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><Cloud class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{expo.remote.builds.length}</p><p class="text-[11px] text-slate-500">Builds on this page</p></div></div>
                <div class="flex items-center gap-3 border-t border-slate-100 px-4 py-3.5 sm:border-t-0"><span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-50 text-emerald-600"><PackageCheck class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{expo.remote.submissions.length}</p><p class="text-[11px] text-slate-500">Submissions on this page</p></div></div>
                <div class="flex items-center gap-3 border-t border-slate-100 px-4 py-3.5 sm:border-t-0"><span class="grid h-8 w-8 place-items-center rounded-lg bg-amber-50 text-amber-600"><KeyRound class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{androidCredentials.length + iosCredentials.length}</p><p class="text-[11px] text-slate-500">App credential sets</p></div></div>
            </section>

            <section class="card mb-5 p-0">
                <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-3.5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold">EAS Workflows</h2>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {expo.remote.automation.state === 'ready' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}">{expo.remote.automation.state === 'ready' ? 'Ready' : 'Setup required'}</span>
                        </div>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">{expo.remote.automation.message}</p>
                        {#if expo.remote.automation.github_repository?.name}<p class="mt-2 text-[11px] text-slate-400">Source: <strong class="text-slate-600">{expo.remote.automation.github_repository.name}</strong>{#if expo.remote.automation.github_repository.default_branch} · {expo.remote.automation.github_repository.default_branch}{/if}</p>{/if}
                    </div>
                    {#if expo.remote.automation.action_external}<a href={expo.remote.automation.action_url} target="_blank" rel="noreferrer" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">{expo.remote.automation.state === 'github_not_connected' ? 'Configure GitHub in Expo' : 'Configure workflows in Expo'}</a>{:else}<Link href={expo.remote.automation.action_url} class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">{expo.remote.automation.state === 'ready' ? 'Open builds' : 'Configure pipeline'}</Link>{/if}
                </div>
                {#if expo.remote.automation.workflows.length}<div class="flex flex-wrap gap-2 border-t border-slate-100 bg-slate-50 px-5 py-3">{#each expo.remote.automation.workflows as workflow}<span class="rounded-lg bg-white px-2.5 py-1.5 text-[10px] font-semibold text-slate-600 ring-1 ring-slate-200">{workflow.name} · <code>{workflow.file_name}</code></span>{/each}</div>{/if}
            </section>

            <section class="card mb-5 p-0">
                <div class="border-b border-slate-100 px-5 py-3.5"><h2 class="text-xs font-bold">EAS credentials</h2><p class="mt-1 text-[11px] text-slate-500">Metadata only. Secret key material is never returned to this page.</p></div>
                <div class="grid gap-4 p-4 lg:grid-cols-2">
                    <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">Android</p>{#if androidCredentials.length}<div class="space-y-2">{#each androidCredentials as item}<div class="rounded-xl border border-slate-200 p-3"><code class="text-xs font-semibold">{item.applicationIdentifier || 'No application identifier'}</code><p class="mt-1 text-[11px] text-slate-500">{item.androidAppBuildCredentialsList?.length ?? 0} signing profile{item.androidAppBuildCredentialsList?.length === 1 ? '' : 's'} · {item.googleServiceAccountKeyForSubmissions ? 'Submit configured' : 'No submit key'}</p></div>{/each}</div>{:else}<p class="text-xs text-slate-500">No Android credentials.</p>{/if}</div>
                    <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">iOS</p>{#if iosCredentials.length}<div class="space-y-2">{#each iosCredentials as item}<div class="rounded-xl border border-slate-200 p-3"><code class="text-xs font-semibold">{item.appleAppIdentifier?.bundleIdentifier}</code><p class="mt-1 text-[11px] text-slate-500">{item.iosAppBuildCredentialsList?.length ?? 0} signing profile{item.iosAppBuildCredentialsList?.length === 1 ? '' : 's'} · {item.appStoreConnectApiKeyForSubmissions ? 'Submit configured' : 'No ASC key'}</p></div>{/each}</div>{:else}<p class="text-xs text-slate-500">No iOS credentials.</p>{/if}</div>
                </div>
                <div class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-xs text-slate-500">To upload a local credential, open an app identifier and use <strong>Expo</strong> on that credential.</div>
            </section>

            <section class="card overflow-hidden p-0">
                <div class="border-b border-slate-200 px-5 pt-4">
                    <h2 class="text-sm font-bold">EAS activity</h2>
                    <div class="mt-3 flex gap-6" role="tablist" aria-label="EAS activity">
                        <Link
                            href={expo.remote.activity.builds_url}
                            preserveScroll
                            role="tab"
                            aria-selected={expo.remote.activity.active === 'builds'}
                            class="border-b-2 pb-3 text-sm font-semibold {expo.remote.activity.active === 'builds' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}"
                        >Builds</Link>
                        <Link
                            href={expo.remote.activity.submissions_url}
                            preserveScroll
                            role="tab"
                            aria-selected={expo.remote.activity.active === 'submissions'}
                            class="border-b-2 pb-3 text-sm font-semibold {expo.remote.activity.active === 'submissions' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}"
                        >Submissions</Link>
                    </div>
                </div>

                {#if expo.remote.activity.active === 'builds'}
                    <div role="tabpanel" aria-label="Builds">
                        <div class="border-b border-slate-100 px-5 py-4"><p class="text-xs text-slate-500">Builds are created by EAS CLI, EAS triggers, or an App Manager pipeline; from here you can inspect, cancel, retry, and submit them.</p></div>
                        {#if expo.remote.builds.length}<div class="overflow-x-auto"><table class="w-full min-w-[780px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-400"><tr><th class="px-5 py-3">Build</th><th class="px-4 py-3">Platform</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Version</th><th class="px-4 py-3">Created</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-slate-100">{#each expo.remote.builds as build}<tr><td class="px-5 py-3.5"><p class="font-semibold">{build.buildProfile || 'EAS build'}</p><code class="mt-1 block max-w-[220px] truncate text-[10px] text-slate-400">{build.id}</code></td><td class="px-4 py-3.5">{build.platform}</td><td class="px-4 py-3.5"><span class="rounded-full px-2 py-1 text-[10px] font-bold {statusClass(build.status)}">{build.status}</span></td><td class="px-4 py-3.5 text-slate-500">{build.appVersion || '—'}{build.appBuildVersion ? ` (${build.appBuildVersion})` : ''}</td><td class="px-4 py-3.5 text-slate-500">{formatDateTime(build.createdAt)}</td><td class="px-5 py-3.5"><div class="flex justify-end gap-2"><a href={build.expo_url} target="_blank" rel="noreferrer" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold">Open</a>{#if canCancel(build.status)}<button class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => cancelBuild(build)}>Cancel</button>{/if}{#if build.canRetry}<button class="secondary px-2.5 py-1.5 text-[10px]" on:click={() => retryBuild(build)}>Retry</button>{/if}{#if build.status === 'FINISHED' && build.artifacts?.applicationArchiveUrl}<button class="px-2.5 py-1.5 text-[10px]" on:click={() => openSubmission(build)}>Submit</button>{/if}</div></td></tr>{/each}</tbody></table></div>{:else}<div class="px-5 py-10 text-center text-sm text-slate-500">No EAS builds on this page.</div>{/if}
                        <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50 px-5 py-3">
                            {#if expo.remote.pagination.builds.previous_url}<Link href={expo.remote.pagination.builds.previous_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300">Previous</span>{/if}
                            <span class="text-xs font-medium text-slate-500">Page {expo.remote.pagination.builds.page}</span>
                            {#if expo.remote.pagination.builds.next_url}<Link href={expo.remote.pagination.builds.next_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300">Next</span>{/if}
                        </div>
                    </div>
                {:else}
                    <div role="tabpanel" aria-label="Submissions">
                        <div class="border-b border-slate-100 px-5 py-4"><p class="text-xs text-slate-500">Google Play and App Store submission history.</p></div>
                        {#if expo.remote.submissions.length}<div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-400"><tr><th class="px-5 py-3">Submission</th><th class="px-4 py-3">Platform</th><th class="px-4 py-3">Target</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Created</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-slate-100">{#each expo.remote.submissions as submission}<tr><td class="px-5 py-3.5"><code class="text-[10px] text-slate-500">{submission.id}</code></td><td class="px-4 py-3.5">{submission.platform}</td><td class="px-4 py-3.5 text-slate-500">{submission.androidConfig?.track || submission.iosConfig?.ascAppIdentifier || '—'}</td><td class="px-4 py-3.5"><span class="rounded-full px-2 py-1 text-[10px] font-bold {statusClass(submission.status)}">{submission.status}</span></td><td class="px-4 py-3.5 text-slate-500">{formatDateTime(submission.createdAt)}</td><td class="px-5 py-3.5"><div class="flex justify-end gap-2"><a href={submission.expo_url} target="_blank" rel="noreferrer" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold">Open</a>{#if canCancel(submission.status)}<button class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => cancelSubmission(submission)}>Cancel</button>{/if}{#if submission.canRetry}<button class="secondary px-2.5 py-1.5 text-[10px]" on:click={() => retrySubmission(submission)}>Retry</button>{/if}</div></td></tr>{/each}</tbody></table></div>{:else}<div class="px-5 py-10 text-center text-sm text-slate-500">No EAS submissions on this page.</div>{/if}
                        <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50 px-5 py-3">
                            {#if expo.remote.pagination.submissions.previous_url}<Link href={expo.remote.pagination.submissions.previous_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300">Previous</span>{/if}
                            <span class="text-xs font-medium text-slate-500">Page {expo.remote.pagination.submissions.page}</span>
                            {#if expo.remote.pagination.submissions.next_url}<Link href={expo.remote.pagination.submissions.next_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-300">Next</span>{/if}
                        </div>
                    </div>
                {/if}
            </section>
        {/if}
    {/if}
</AppLayout>

{#if showSubmission && submittingBuild}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showSubmission = false)}>
        <form class="modal max-w-lg" on:submit|preventDefault={submit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Submit {submittingBuild.platform} build</h2><p class="mt-1 text-sm text-slate-500">EAS Submit will upload this finished build to the store.</p></div><button type="button" class="ghost p-2" on:click={() => showSubmission = false}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">{submittingBuild.platform === 'IOS' ? 'Bundle identifier' : 'Application identifier'}</span><input bind:value={submissionForm.application_identifier} required />{#if submissionForm.errors.application_identifier}<span class="error">{submissionForm.errors.application_identifier}</span>{/if}</label>
                {#if submittingBuild.platform === 'ANDROID'}
                    <div class="grid gap-4 sm:grid-cols-2"><label><span class="label">Track</span><select bind:value={submissionForm.track}><option value="INTERNAL">Internal</option><option value="ALPHA">Alpha</option><option value="BETA">Beta</option><option value="PRODUCTION">Production</option></select></label><label><span class="label">Release status</span><select bind:value={submissionForm.release_status}><option value="COMPLETED">Completed</option><option value="DRAFT">Draft</option><option value="IN_PROGRESS">In progress</option><option value="HALTED">Halted</option></select></label></div>
                    <label><span class="label">Google service account key</span><select bind:value={submissionForm.google_service_account_key_id} on:focus={() => !submissionForm.google_service_account_key_id && (submissionForm.google_service_account_key_id = firstGoogleKey())}><option value="">Use the key attached in EAS</option>{#each androidCredentials.filter((item) => item.googleServiceAccountKeyForSubmissions) as item}<option value={item.googleServiceAccountKeyForSubmissions.id}>{item.googleServiceAccountKeyForSubmissions.clientEmail}</option>{/each}</select></label>
                {:else}
                    <label><span class="label">App Store Connect API key</span><select bind:value={submissionForm.asc_api_key_id} on:focus={() => !submissionForm.asc_api_key_id && (submissionForm.asc_api_key_id = firstAscKey())}><option value="">Use the key attached in EAS</option>{#each iosCredentials.filter((item) => item.appStoreConnectApiKeyForSubmissions) as item}<option value={item.appStoreConnectApiKeyForSubmissions.id}>{item.appStoreConnectApiKeyForSubmissions.name || item.appStoreConnectApiKeyForSubmissions.keyIdentifier}</option>{/each}</select></label>
                {/if}
                {#if submissionForm.errors.expo}<span class="error">{submissionForm.errors.expo}</span>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" class="secondary" on:click={() => showSubmission = false}>Cancel</button><button disabled={submissionForm.processing}>{submissionForm.processing ? 'Starting…' : 'Start submission'}</button></div>
        </form>
    </div>
{/if}
