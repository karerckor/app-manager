<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { ExternalLink, RefreshCw, Unlink, Cloud, KeyRound, PackageCheck, ArrowRight, Check, AlertTriangle, Download } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import { formatDateTime } from '../lib.js';
    import Select from '../../../Components/Select.svelte';

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
    let selectedBuildIds = [];

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
    const automaticSyncForm = useForm({
        enabled: expo.project?.automatic_sync?.enabled ?? false,
    });
    const importForm = useForm({
        build_ids: [],
    });

    $: selectedAccount = expo.accounts?.find((account) => account.id === setupForm.integration_id);
    $: availableProjects = selectedAccount?.projects ?? [];
    $: androidCredentials = expo.remote?.credentials?.android ?? [];
    $: iosCredentials = expo.remote?.credentials?.ios ?? [];
    $: automaticSync = expo.project?.automatic_sync ?? {};
    $: automaticSyncLabel = automaticSync.enabled
        ? ({ queued: 'Queued', syncing: 'Syncing', synced: 'Up to date', failed: 'Needs attention' }[automaticSync.status] ?? 'On')
        : 'Off';
    $: availableBuilds = expo.remote?.builds ?? [];
    $: importableBuilds = availableBuilds.filter((build) => !build.already_imported);
    $: allBuildsSelected = importableBuilds.length > 0 && selectedBuildIds.length === importableBuilds.length;
    $: someBuildsSelected = selectedBuildIds.length > 0 && selectedBuildIds.length < importableBuilds.length;

    const setup = () => setupForm.post(expo.setup_url);
    const sync = () => router.post(expo.sync_url, {}, { preserveScroll: true });
    const updateAutomaticSync = () => automaticSyncForm.patch(automaticSync.update_url, {
        preserveScroll: true,
        onError: () => automaticSyncForm.enabled = automaticSync.enabled,
    });
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
        FINISHED: 'bg-ok/10 text-ok',
        IN_PROGRESS: 'bg-brand-soft text-brand',
        IN_QUEUE: 'bg-brand-soft text-brand',
        NEW: 'bg-brand-soft text-brand',
        AWAITING_BUILD: 'bg-brand-soft text-brand',
        PENDING_CANCEL: 'bg-warn-text/10 text-warn-fill',
        ERRORED: 'bg-danger/10 text-danger',
        CANCELED: 'bg-border-subtle text-muted',
    }[status] ?? 'bg-border-subtle text-muted');
    const canCancel = (status) => ['NEW', 'IN_QUEUE', 'IN_PROGRESS', 'PENDING_CANCEL', 'AWAITING_BUILD'].includes(status);
    const firstGoogleKey = () => androidCredentials
        .map((item) => item.googleServiceAccountKeyForSubmissions)
        .find(Boolean)?.id ?? '';
    const firstAscKey = () => iosCredentials
        .map((item) => item.appStoreConnectApiKeyForSubmissions)
        .find(Boolean)?.id ?? '';

    const toggleBuild = (buildId) => {
        selectedBuildIds = selectedBuildIds.includes(buildId)
            ? selectedBuildIds.filter((id) => id !== buildId)
            : [...selectedBuildIds, buildId];
    };
    const toggleAllBuilds = () => {
        selectedBuildIds = allBuildsSelected ? [] : importableBuilds.map((b) => b.id);
    };
    const importSelectedBuilds = () => {
        if (selectedBuildIds.length === 0) return;
        importForm.build_ids = selectedBuildIds;
        importForm.post(expo.import_builds_url, {
            preserveScroll: true,
            onSuccess: () => {
                selectedBuildIds = [];
            },
        });
    };

    $: if (expo.remote?.builds) {
        const currentIds = expo.remote.builds.filter((b) => !b.already_imported).map((b) => b.id);
        selectedBuildIds = selectedBuildIds.filter((id) => currentIds.includes(id));
    }

    onMount(() => {
        const timer = setInterval(() => {
            const waitingBuild = expo.remote?.builds?.some((build) => canCancel(build.status));
            const waitingSubmission = expo.remote?.submissions?.some((submission) => canCancel(submission.status));
            const waitingAutomaticSync = automaticSync.enabled
                && ['queued', 'syncing'].includes(automaticSync.status);
            if (waitingBuild || waitingSubmission || waitingAutomaticSync) {
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
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-text text-xs font-black text-white">EAS</span>
                <div>
                    <h2 class="text-lg font-bold">{expo.inactive_project ? 'Expo link needs attention' : 'Set up on Expo'}</h2>
                    <p class="mt-1 text-sm leading-6 text-muted">{expo.inactive_project ? 'This application is already linked to Expo, but the account connection is no longer available.' : "Link an existing Expo project, or create one together with this application's identifiers and supported EAS credentials."}</p>
                </div>
            </div>

            {#if expo.inactive_project}
                <div class="mt-6 rounded-md border border-warn-fill/40 bg-warn-fill/10 p-4" role="status">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-surface-raised text-warn-text ring-1 ring-inset ring-warn-fill/30"><AlertTriangle class="h-4 w-4" strokeWidth={1.9} /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <strong class="text-sm">{expo.inactive_project.name}</strong>
                                <span class="rounded-full bg-warn-fill/15 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-warn-text">Account {expo.inactive_project.connection_status === 'removed' ? 'removed' : expo.inactive_project.connection_status}</span>
                            </div>
                            <code class="mt-1 block truncate text-xs text-muted">{expo.inactive_project.full_name}</code>
                            <p class="mt-3 text-xs leading-5 text-warn-text">The local project link is preserved. Reconnect <strong>@{expo.inactive_project.account_name}</strong> to restore EAS data, or use another account below. Nothing will be deleted from Expo.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href={expo.inactive_project.url} target="_blank" rel="noreferrer" class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"><ExternalLink class="h-3.5 w-3.5" /> Open Expo project</a>
                                <Link href={expo.integrations_url} class="secondary px-3 py-2 text-xs">Manage Expo accounts</Link>
                            </div>
                        </div>
                    </div>
                </div>
            {/if}

            {#if expo.accounts?.length}
                <form class="mt-6 space-y-5" on:submit|preventDefault={setup}>
                    {#if expo.inactive_project}<div><p class="text-sm font-bold">Use another Expo account</p><p class="mt-1 text-xs leading-5 text-muted">Choose where this application should be linked next.</p></div>{/if}
                    <label><span class="label">Expo account</span><Select bind:value={setupForm.integration_id} options={(expo.accounts ?? []).map((account) => ({ value: account.id, label: account.name }))} ariaLabel="Expo account" on:change={() => setupForm.expo_project_id = ''} />{#if setupForm.errors.integration_id}<span class="error">{setupForm.errors.integration_id}</span>{/if}</label>
                    <fieldset><legend class="label">Project</legend><div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border p-4 {setupForm.mode === 'create' ? 'border-indigo-400 bg-brand-soft' : 'border-border'}"><input class="mt-0.5 h-4 w-4" type="radio" bind:group={setupForm.mode} value="create" /><span><strong class="block text-sm">Create a new project</strong><span class="mt-1 block text-xs text-muted">Creates <code>@{selectedAccount?.name}/{app.slug}</code>, app identifiers, and supported credentials.</span></span></label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border p-4 {setupForm.mode === 'existing' ? 'border-indigo-400 bg-brand-soft' : 'border-border'}"><input class="mt-0.5 h-4 w-4" type="radio" bind:group={setupForm.mode} value="existing" disabled={!availableProjects.length} /><span><strong class="block text-sm">Link an existing project</strong><span class="mt-1 block text-xs text-muted">{availableProjects.length ? `${availableProjects.length} unlinked project${availableProjects.length === 1 ? '' : 's'}` : 'No unlinked projects in this account.'}</span></span></label>
                    </div></fieldset>
                    {#if setupForm.mode === 'existing'}<label><span class="label">Expo project</span><Select bind:value={setupForm.expo_project_id} options={[{ value: '', label: 'Select a project', disabled: true }, ...availableProjects.map((project) => ({ value: project.id, label: `${project.name} · ${project.full_name}` }))]} ariaLabel="Expo project" required />{#if setupForm.errors.expo_project_id}<span class="error">{setupForm.errors.expo_project_id}</span>{/if}</label>{/if}
                    <label class="flex items-start gap-3 rounded-md border border-border p-4"><input class="mt-0.5 h-4 w-4" type="checkbox" bind:checked={setupForm.sync_metadata} /><span><strong class="block text-sm">Sync metadata from Expo</strong><span class="mt-1 block text-xs text-muted">Expo display name and description update the local application during an explicit sync.</span></span></label>
                    {#if expo.inactive_project}<p class="rounded-md border border-border-subtle bg-surface-sunken px-4 py-3 text-xs leading-5 text-muted">Completing setup replaces the local link to <code>{expo.inactive_project.full_name}</code>. The previous Expo project and its data stay untouched.</p>{/if}
                    {#if setupForm.errors.expo}<span class="error">{setupForm.errors.expo}</span>{/if}
                    <div class="flex justify-end"><button disabled={setupForm.processing || (setupForm.mode === 'existing' && !setupForm.expo_project_id)}>{setupForm.processing ? 'Setting up…' : expo.inactive_project ? 'Set up with this account' : 'Set up on Expo'}</button></div>
                </form>
            {:else}
                <div class="mt-6 rounded-md border border-warn-fill/40 bg-warn-fill/10 p-4"><p class="text-sm font-semibold text-warn-text">{expo.inactive_project ? `Reconnect @${expo.inactive_project.account_name} or connect another Expo account.` : 'Connect an Expo account first.'}</p><p class="mt-1 text-xs leading-5 text-muted">{expo.inactive_project ? 'The preserved project link will remain in place until you reconnect or choose a different account.' : 'After connecting, this application can be linked without changing any other apps.'}</p><Link href={expo.integrations_url} class="secondary mt-3 px-3 py-2 text-xs">Manage Expo accounts</Link></div>
            {/if}
        </section>
    {:else}
        <div class="card mb-5 flex flex-wrap items-center justify-between gap-4 border-brand-soft-line/80 bg-brand-soft/40 px-4 py-3.5">
            <div class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-soft-line text-[9px] font-black text-brand">EAS</span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold">{expo.project.name}</h2><span class="rounded-full bg-ok/10 px-2 py-0.5 text-[9px] font-bold text-ok">LINKED</span></div><code class="mt-0.5 block text-[11px] text-muted">{expo.project.full_name}</code></div></div>
            <div class="flex flex-wrap gap-2"><a href={expo.project.url} target="_blank" rel="noreferrer" class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"><ExternalLink class="h-3.5 w-3.5" /> Open Expo</a>{#if expo.can_manage && expo.can_manage_credentials}<button class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={sync}><RefreshCw class="h-3.5 w-3.5" /> Pull from Expo</button>{/if}{#if expo.can_manage}<button class="ghost inline-flex items-center gap-1.5 px-3 py-2 text-xs text-danger" on:click={unlink}><Unlink class="h-3.5 w-3.5" /> Unlink</button>{/if}</div>
        </div>

        <section class="card mb-5 overflow-hidden p-0">
            <div class="grid lg:grid-cols-[minmax(0,1fr)_auto]">
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="text-sm font-bold">Automatic sync with Expo</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold {automaticSync.status === 'failed' ? 'bg-danger/10 text-danger' : automaticSync.enabled ? 'bg-ok/10 text-ok' : 'bg-border-subtle text-muted'}">
                            {#if automaticSync.status === 'syncing'}<RefreshCw class="h-3 w-3 animate-spin" />{:else if automaticSync.status === 'failed'}<AlertTriangle class="h-3 w-3" />{:else if automaticSync.enabled}<Check class="h-3 w-3" />{/if}
                            {automaticSyncLabel}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] font-semibold">
                        <span class="rounded-md border border-border bg-surface-raised px-2.5 py-1.5">App Manager</span>
                        <span class="inline-flex items-center gap-1 text-brand"><ArrowRight class="h-3.5 w-3.5" /> automatic push</span>
                        <span class="rounded-md border border-border bg-surface-raised px-2.5 py-1.5">Expo</span>
                    </div>
                    <p class="mt-3 max-w-2xl text-xs leading-5 text-muted">Name, app icon, iOS and Android identifiers, and supported credentials are pushed after local changes. Expo never overwrites App Manager automatically; use <strong>Pull from Expo</strong> when you want to import remote changes.</p>
                    <div class="mt-3 flex flex-wrap gap-1.5" aria-label="Automatically synchronized fields">
                        {#each ['Name', 'App icon', 'Identifiers', 'Credentials'] as field}<span class="rounded-full bg-surface-sunken px-2.5 py-1 text-[10px] font-medium text-muted ring-1 ring-border-subtle">{field}</span>{/each}
                    </div>
                    {#if automaticSync.error}<p class="mt-3 flex items-start gap-2 text-xs leading-5 text-danger"><AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {automaticSync.error}</p>{:else if automaticSync.last_pushed_at}<p class="mt-3 text-[11px] text-faint">Last pushed {formatDateTime(automaticSync.last_pushed_at)}</p>{:else if automaticSync.enabled}<p class="mt-3 text-[11px] text-faint">The first push is queued.</p>{/if}
                    {#if automaticSyncForm.errors.enabled}<span class="error mt-2">{automaticSyncForm.errors.enabled}</span>{/if}
                </div>
                <div class="flex items-center justify-between gap-5 border-t border-border-subtle bg-surface-sunken px-5 py-4 lg:min-w-[230px] lg:border-l lg:border-t-0">
                    <div><p class="text-xs font-semibold">Automatic push</p><p class="mt-1 text-[10px] text-muted">Per application</p></div>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input
                            class="peer sr-only"
                            type="checkbox"
                            role="switch"
                            aria-label="Automatic sync with Expo"
                            bind:checked={automaticSyncForm.enabled}
                            on:change={updateAutomaticSync}
                            disabled={!expo.can_manage || !expo.can_manage_credentials || automaticSyncForm.processing || (!automaticSyncForm.enabled && (expo.project.connection_status !== 'active' || !expo.project.exists_on_expo))}
                        />
                        <span class="h-6 w-11 rounded-full bg-border transition peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition peer-checked:after:translate-x-5"></span>
                    </label>
                </div>
            </div>
        </section>

        {#if !expo.project.exists_on_expo}<div class="mb-6 rounded-md border border-danger bg-danger/10 px-4 py-3 text-sm text-danger">This project was not found during the last Expo account sync. The local link is preserved.</div>{:else if expo.project.connection_status !== 'active'}<div class="mb-6 rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3 text-sm text-warn-fill">Reconnect the Expo account to load credentials, builds, and submissions.</div>{:else if expo.error}<div class="mb-6 rounded-md border border-danger bg-danger/10 px-4 py-3 text-sm text-danger">{expo.error}</div>{/if}
        {#if setupForm.errors.expo}<div class="mb-6 rounded-md border border-danger bg-danger/10 px-4 py-3 text-sm leading-6 text-danger">{setupForm.errors.expo}</div>{/if}
        {#if actionForm.errors.expo}<div class="mb-6 rounded-md border border-danger bg-danger/10 px-4 py-3 text-sm text-danger">{actionForm.errors.expo}</div>{/if}

        {#if expo.remote}
            <section class="card mb-5 grid overflow-clip p-0 sm:grid-cols-3 sm:divide-x sm:divide-border-subtle">
                <div class="flex items-center gap-3 px-4 py-3.5"><span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-soft text-brand"><Cloud class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{expo.remote.builds.length}</p><p class="text-[11px] text-muted">Builds on this page</p></div></div>
                <div class="flex items-center gap-3 border-t border-border-subtle px-4 py-3.5 sm:border-t-0"><span class="grid h-8 w-8 place-items-center rounded-lg bg-ok/10 text-ok"><PackageCheck class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{expo.remote.submissions.length}</p><p class="text-[11px] text-muted">Submissions on this page</p></div></div>
                <div class="flex items-center gap-3 border-t border-border-subtle px-4 py-3.5 sm:border-t-0"><span class="grid h-8 w-8 place-items-center rounded-lg bg-warn-text/10 text-warn-fill"><KeyRound class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold tabular-nums">{androidCredentials.length + iosCredentials.length}</p><p class="text-[11px] text-muted">App credential sets</p></div></div>
            </section>

            <section class="card mb-5 p-0">
                <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-3.5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold">EAS Workflows</h2>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {expo.remote.automation.state === 'ready' ? 'bg-ok/10 text-ok' : 'bg-warn-text/10 text-warn-fill'}">{expo.remote.automation.state === 'ready' ? 'Ready' : 'Setup required'}</span>
                        </div>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-muted">{expo.remote.automation.message}</p>
                        {#if expo.remote.automation.github_repository?.name}<p class="mt-2 text-[11px] text-faint">Source: <strong class="text-muted">{expo.remote.automation.github_repository.name}</strong>{#if expo.remote.automation.github_repository.default_branch} · {expo.remote.automation.github_repository.default_branch}{/if}</p>{/if}
                    </div>
                    {#if expo.remote.automation.action_external}<a href={expo.remote.automation.action_url} target="_blank" rel="noreferrer" class="inline-flex items-center justify-center rounded-md bg-brand px-4 py-2.5 text-sm font-semibold text-brand-fg shadow-sm transition hover:bg-brand/90">{expo.remote.automation.state === 'github_not_connected' ? 'Configure GitHub in Expo' : 'Configure workflows in Expo'}</a>{:else}<Link href={expo.remote.automation.action_url} class="inline-flex items-center justify-center rounded-md bg-brand px-4 py-2.5 text-sm font-semibold text-brand-fg shadow-sm transition hover:bg-brand/90">{expo.remote.automation.state === 'ready' ? 'Open builds' : 'Configure pipeline'}</Link>{/if}
                </div>
                {#if expo.remote.automation.workflows.length}<div class="flex flex-wrap gap-2 border-t border-border-subtle bg-surface-sunken px-5 py-3">{#each expo.remote.automation.workflows as workflow}<span class="rounded-lg bg-surface-raised px-2.5 py-1.5 text-[10px] font-semibold text-muted ring-1 ring-border">{workflow.name} · <code>{workflow.file_name}</code></span>{/each}</div>{/if}
            </section>

            <section class="card mb-5 p-0">
                <div class="border-b border-border-subtle px-5 py-3.5"><h2 class="text-xs font-bold">EAS credentials</h2><p class="mt-1 text-[11px] text-muted">Metadata only. Secret key material is never returned to this page.</p></div>
                <div class="grid gap-4 p-4 lg:grid-cols-2">
                    <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-faint">Android</p>{#if androidCredentials.length}<div class="space-y-2">{#each androidCredentials as item}<div class="rounded-md border border-border p-3"><code class="text-xs font-semibold">{item.applicationIdentifier || 'No application identifier'}</code><p class="mt-1 text-[11px] text-muted">{item.androidAppBuildCredentialsList?.length ?? 0} signing profile{item.androidAppBuildCredentialsList?.length === 1 ? '' : 's'} · {item.googleServiceAccountKeyForSubmissions ? 'Submit configured' : 'No submit key'}</p></div>{/each}</div>{:else}<p class="text-xs text-muted">No Android credentials.</p>{/if}</div>
                    <div><p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-faint">iOS</p>{#if iosCredentials.length}<div class="space-y-2">{#each iosCredentials as item}<div class="rounded-md border border-border p-3"><code class="text-xs font-semibold">{item.appleAppIdentifier?.bundleIdentifier}</code><p class="mt-1 text-[11px] text-muted">{item.iosAppBuildCredentialsList?.length ?? 0} signing profile{item.iosAppBuildCredentialsList?.length === 1 ? '' : 's'} · {item.appStoreConnectApiKeyForSubmissions ? 'Submit configured' : 'No ASC key'}</p></div>{/each}</div>{:else}<p class="text-xs text-muted">No iOS credentials.</p>{/if}</div>
                </div>
                <div class="border-t border-border-subtle bg-surface-sunken px-5 py-3 text-xs text-muted">To upload a local credential, open an app identifier and use <strong>Expo</strong> on that credential.</div>
            </section>

            <section class="card overflow-hidden p-0">
                <div class="border-b border-border px-5 pt-4">
                    <h2 class="text-sm font-bold">EAS activity</h2>
                    <div class="mt-3 flex gap-6" role="tablist" aria-label="EAS activity">
                        <Link
                            href={expo.remote.activity.builds_url}
                            preserveScroll
                            role="tab"
                            aria-selected={expo.remote.activity.active === 'builds'}
                            class="border-b-2 pb-3 text-sm font-semibold {expo.remote.activity.active === 'builds' ? 'border-indigo-600 text-brand' : 'border-transparent text-muted hover:text-text'}"
                        >Builds</Link>
                        <Link
                            href={expo.remote.activity.submissions_url}
                            preserveScroll
                            role="tab"
                            aria-selected={expo.remote.activity.active === 'submissions'}
                            class="border-b-2 pb-3 text-sm font-semibold {expo.remote.activity.active === 'submissions' ? 'border-indigo-600 text-brand' : 'border-transparent text-muted hover:text-text'}"
                        >Submissions</Link>
                    </div>
                </div>

                {#if expo.remote.activity.active === 'builds'}
                    <div role="tabpanel" aria-label="Builds">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4">
                            <p class="text-xs text-muted">Builds are created by EAS CLI, EAS triggers, or an App Manager pipeline; from here you can inspect, cancel, retry, and submit them.</p>
                            {#if availableBuilds.length && expo.can_manage}
                                <button
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand px-3 py-2 text-xs font-semibold text-brand-fg shadow-sm transition hover:bg-brand/90 disabled:cursor-not-allowed disabled:opacity-50"
                                    disabled={selectedBuildIds.length === 0 || importForm.processing}
                                    on:click={importSelectedBuilds}
                                >
                                    <Download class="h-3.5 w-3.5" />
                                    {importForm.processing ? 'Importing…' : `Import selected builds from EAS${selectedBuildIds.length ? ` (${selectedBuildIds.length})` : ''}`}
                                </button>
                            {/if}
                        </div>
                        {#if importForm.errors.builds}<div class="border-b border-danger/20 bg-danger/5 px-5 py-3 text-xs text-danger">{importForm.errors.builds}</div>{/if}
                        {#if importForm.errors.expo}<div class="border-b border-danger/20 bg-danger/5 px-5 py-3 text-xs text-danger">{importForm.errors.expo}</div>{/if}
                        {#if availableBuilds.length}<div class="overflow-x-auto"><table class="w-full min-w-[820px] text-left text-xs"><thead class="bg-surface-sunken text-[10px] uppercase text-faint"><tr>{#if expo.can_manage}<th class="w-10 px-3 py-3"><input type="checkbox" class="h-3.5 w-3.5 disabled:cursor-not-allowed disabled:opacity-40" checked={allBuildsSelected} disabled={importableBuilds.length === 0} aria-label="Select all builds" on:change={toggleAllBuilds} /></th>{/if}<th class="px-5 py-3">Build</th><th class="px-4 py-3">Platform</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Version</th><th class="px-4 py-3">Created</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-border-subtle">{#each availableBuilds as build}<tr class="{selectedBuildIds.includes(build.id) ? 'bg-brand-soft/30' : ''}">{#if expo.can_manage}<td class="px-3 py-3.5"><input type="checkbox" class="h-3.5 w-3.5 disabled:cursor-not-allowed disabled:opacity-40" checked={selectedBuildIds.includes(build.id)} disabled={build.already_imported} aria-label={build.already_imported ? `Build ${build.id} already imported` : `Select build ${build.id}`} on:change={() => toggleBuild(build.id)} /></td>{/if}<td class="px-5 py-3.5"><p class="flex items-center gap-2 font-semibold">{build.buildProfile || 'EAS build'}{#if build.already_imported}<span class="inline-flex items-center gap-1 rounded-full bg-ok/10 px-2 py-0.5 text-[10px] font-bold text-ok"><Check class="h-3 w-3" /> Imported</span>{/if}</p><code class="mt-1 block max-w-[220px] truncate text-[10px] text-faint">{build.id}</code></td><td class="px-4 py-3.5">{build.platform}</td><td class="px-4 py-3.5"><span class="rounded-full px-2 py-1 text-[10px] font-bold {statusClass(build.status)}">{build.status}</span></td><td class="px-4 py-3.5 text-muted">{build.appVersion || '—'}{build.appBuildVersion ? ` (${build.appBuildVersion})` : ''}</td><td class="px-4 py-3.5 text-muted">{formatDateTime(build.createdAt)}</td><td class="px-5 py-3.5"><div class="flex justify-end gap-2"><a href={build.expo_url} target="_blank" rel="noreferrer" class="rounded-lg border border-border px-2.5 py-1.5 text-[10px] font-semibold">Open</a>{#if canCancel(build.status)}<button class="ghost px-2.5 py-1.5 text-[10px] text-danger" on:click={() => cancelBuild(build)}>Cancel</button>{/if}{#if build.canRetry}<button class="secondary px-2.5 py-1.5 text-[10px]" on:click={() => retryBuild(build)}>Retry</button>{/if}{#if build.status === 'FINISHED' && build.artifacts?.applicationArchiveUrl}<button class="px-2.5 py-1.5 text-[10px]" on:click={() => openSubmission(build)}>Submit</button>{/if}</div></td></tr>{/each}</tbody></table></div>{:else}<div class="px-5 py-10 text-center text-sm text-muted">No EAS builds on this page.</div>{/if}
                        <div class="flex items-center justify-between border-t border-border-subtle bg-surface-sunken px-5 py-3">
                            {#if expo.remote.pagination.builds.previous_url}<Link href={expo.remote.pagination.builds.previous_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                            <span class="text-xs font-medium text-muted">Page {expo.remote.pagination.builds.page}</span>
                            {#if expo.remote.pagination.builds.next_url}<Link href={expo.remote.pagination.builds.next_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                        </div>
                    </div>
                {:else}
                    <div role="tabpanel" aria-label="Submissions">
                        <div class="border-b border-border-subtle px-5 py-4"><p class="text-xs text-muted">Google Play and App Store submission history.</p></div>
                        {#if expo.remote.submissions.length}<div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-xs"><thead class="bg-surface-sunken text-[10px] uppercase text-faint"><tr><th class="px-5 py-3">Submission</th><th class="px-4 py-3">Platform</th><th class="px-4 py-3">Target</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Created</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-border-subtle">{#each expo.remote.submissions as submission}<tr><td class="px-5 py-3.5"><code class="text-[10px] text-muted">{submission.id}</code></td><td class="px-4 py-3.5">{submission.platform}</td><td class="px-4 py-3.5 text-muted">{submission.androidConfig?.track || submission.iosConfig?.ascAppIdentifier || '—'}</td><td class="px-4 py-3.5"><span class="rounded-full px-2 py-1 text-[10px] font-bold {statusClass(submission.status)}">{submission.status}</span></td><td class="px-4 py-3.5 text-muted">{formatDateTime(submission.createdAt)}</td><td class="px-5 py-3.5"><div class="flex justify-end gap-2"><a href={submission.expo_url} target="_blank" rel="noreferrer" class="rounded-lg border border-border px-2.5 py-1.5 text-[10px] font-semibold">Open</a>{#if canCancel(submission.status)}<button class="ghost px-2.5 py-1.5 text-[10px] text-danger" on:click={() => cancelSubmission(submission)}>Cancel</button>{/if}{#if submission.canRetry}<button class="secondary px-2.5 py-1.5 text-[10px]" on:click={() => retrySubmission(submission)}>Retry</button>{/if}</div></td></tr>{/each}</tbody></table></div>{:else}<div class="px-5 py-10 text-center text-sm text-muted">No EAS submissions on this page.</div>{/if}
                        <div class="flex items-center justify-between border-t border-border-subtle bg-surface-sunken px-5 py-3">
                            {#if expo.remote.pagination.submissions.previous_url}<Link href={expo.remote.pagination.submissions.previous_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                            <span class="text-xs font-medium text-muted">Page {expo.remote.pagination.submissions.page}</span>
                            {#if expo.remote.pagination.submissions.next_url}<Link href={expo.remote.pagination.submissions.next_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
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
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">Submit {submittingBuild.platform} build</h2><p class="mt-1 text-sm text-muted">EAS Submit will upload this finished build to the store.</p></div><button type="button" class="ghost p-2" on:click={() => showSubmission = false}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">{submittingBuild.platform === 'IOS' ? 'Bundle identifier' : 'Application identifier'}</span><input bind:value={submissionForm.application_identifier} required />{#if submissionForm.errors.application_identifier}<span class="error">{submissionForm.errors.application_identifier}</span>{/if}</label>
                {#if submittingBuild.platform === 'ANDROID'}
                    <div class="grid gap-4 sm:grid-cols-2"><label><span class="label">Track</span><Select bind:value={submissionForm.track} options={['INTERNAL', 'ALPHA', 'BETA', 'PRODUCTION'].map((value) => ({ value, label: value[0] + value.slice(1).toLowerCase() }))} ariaLabel="Track" /></label><label><span class="label">Release status</span><Select bind:value={submissionForm.release_status} options={[{ value: 'COMPLETED', label: 'Completed' }, { value: 'DRAFT', label: 'Draft' }, { value: 'IN_PROGRESS', label: 'In progress' }, { value: 'HALTED', label: 'Halted' }]} ariaLabel="Release status" /></label></div>
                    <label><span class="label">Google service account key</span><Select bind:value={submissionForm.google_service_account_key_id} options={[{ value: '', label: 'Use the key attached in EAS' }, ...androidCredentials.filter((item) => item.googleServiceAccountKeyForSubmissions).map((item) => ({ value: item.googleServiceAccountKeyForSubmissions.id, label: item.googleServiceAccountKeyForSubmissions.clientEmail }))]} ariaLabel="Google service account key" /></label>
                {:else}
                    <label><span class="label">App Store Connect API key</span><Select bind:value={submissionForm.asc_api_key_id} options={[{ value: '', label: 'Use the key attached in EAS' }, ...iosCredentials.filter((item) => item.appStoreConnectApiKeyForSubmissions).map((item) => ({ value: item.appStoreConnectApiKeyForSubmissions.id, label: item.appStoreConnectApiKeyForSubmissions.name || item.appStoreConnectApiKeyForSubmissions.keyIdentifier }))]} ariaLabel="App Store Connect API key" /></label>
                {/if}
                {#if submissionForm.errors.expo}<span class="error">{submissionForm.errors.expo}</span>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showSubmission = false}>Cancel</button><button disabled={submissionForm.processing}>{submissionForm.processing ? 'Starting…' : 'Start submission'}</button></div>
        </form>
    </div>
{/if}
