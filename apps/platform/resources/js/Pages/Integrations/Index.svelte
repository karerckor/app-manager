<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import {
        CheckCircle2,
        ChevronRight,
        Plus,
        Search,
        Send,
        ShieldCheck,
        Webhook,
    } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import TabSelector from '../../Components/TabSelector.svelte';
    import NotificationsPanel from './NotificationsPanel.svelte';
    import SentryPanel from './SentryPanel.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let ciCdProviders = [];
    export let selfHostedGitLab = {};
    export let expoIntegration = {};
    export let activeTab = 'ci_cd';
    export let integrationUrls = {};
    export let integrations = [];
    export let apps = [];
    export let profileTypes = [];
    export let buildStatuses = [];
    export let storeWebhookUrl;
    export let webhooksEnabled = false;
    export let webhookEventTypes = [];
    export let webhookEndpoints = [];
    export let newWebhookSecret = null;
    export let notificationsEnabled = false;
    export let storeNotificationUrl = '';
    export let notificationProviders = [];
    export let notificationEvents = [];
    export let notificationDestinations = [];
    export let selectedNotificationDestinationId = null;
    export let notificationGlobalVariables = [];
    export let sentryEnabled = false;
    export let storeSentryUrl = '';
    export let sentryDsns = [];
    export let sentryEvents = { data: [], links: [], total: 0 };
    export let sentryEventFilters = {};
    export let sentryApps = { data: [], links: [], total: 0 };
    export let sentryAppFilters = {};
    export let sentryAppsCount = 0;
    export let sentryBlueprints = [];

    let showWebhook = false;
    let editingEndpoint = null;
    let webhookTagInput = '';
    let inspectedDelivery = null;
    let inspectorLoading = false;
    let inspectorError = '';
    let inspectorRequest = 0;
    let redeliveringDeliveryId = null;
    let showSelfHostedGitLab = false;
    let showExpo = false;
    let expoClientError = '';
    let expoSubmitting = false;
    let syncingExpoIntegrationId = null;
    let expoSyncErrors = {};
    let importingExpoProjectId = null;
    let expoImportErrors = {};
    let selectedExpoProjectIds = {};
    let bulkImportingExpoIntegrationId = null;
    let expoSearch = expoIntegration.search ?? '';

    const webhookForm = useForm({
        name: '',
        url: '',
        events: webhookEventTypes.length ? [webhookEventTypes[0]] : [],
        filters: {
            mobile_app_ids: [],
            tags: [],
            build_statuses: [],
            profile_types: [],
        },
        enabled: true,
    });
    const selfHostedGitLabForm = useForm({
        instance_url: '',
        access_token: '',
    });
    const expoForm = useForm({
        access_token: '',
        account_name: '',
    });

    const openSelfHostedGitLab = () => {
        selfHostedGitLabForm.reset();
        selfHostedGitLabForm.instance_url = 'https://';
        selfHostedGitLabForm.clearErrors();
        showSelfHostedGitLab = true;
    };
    const connectSelfHostedGitLab = () => selfHostedGitLabForm.post(selfHostedGitLab.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            showSelfHostedGitLab = false;
            selfHostedGitLabForm.reset();
        },
    });
    const openExpo = () => {
        expoForm.reset();
        expoForm.clearErrors();
        expoClientError = '';
        expoSubmitting = false;
        showExpo = true;
    };
    const connectExpo = () => {
        if (expoSubmitting || expoForm.processing) return;

        expoClientError = '';
        expoSubmitting = true;

        try {
            router.post(expoIntegration.store_url, {
                access_token: expoForm.access_token,
                account_name: expoForm.account_name,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    showExpo = false;
                    expoForm.reset();
                },
                onError: (errors) => {
                    expoForm.setError(errors ?? {});
                    expoClientError = firstError(errors, 'Expo connection failed. Please try again.');
                },
                onCancel: () => {
                    expoClientError = 'Expo connection request was cancelled. Please try again.';
                },
                onFinish: () => {
                    expoSubmitting = false;
                },
            });
        } catch (error) {
            expoSubmitting = false;
            expoClientError = error?.message ?? 'Expo connection failed. Please try again.';
        }
    };
    const firstError = (errors, fallback) => Object.values(errors ?? {})
        .flat()
        .find((message) => typeof message === 'string')
        ?? fallback;
    const syncExpo = (integration) => {
        if (syncingExpoIntegrationId) return;

        syncingExpoIntegrationId = integration.id;
        expoSyncErrors = { ...expoSyncErrors, [integration.id]: '' };
        router.post(integration.sync_url, {}, {
            preserveScroll: true,
            onError: (errors) => {
                expoSyncErrors = {
                    ...expoSyncErrors,
                    [integration.id]: firstError(errors, 'Expo project sync failed. Please try again.'),
                };
            },
            onCancel: () => {
                expoSyncErrors = {
                    ...expoSyncErrors,
                    [integration.id]: 'Expo project sync was cancelled. Please try again.',
                };
            },
            onFinish: () => {
                syncingExpoIntegrationId = null;
            },
        });
    };
    const disconnectExpo = (integration) => {
        if (confirm(`Disconnect ${integration.name}? Expo projects will not be deleted.`)) {
            router.delete(integration.disconnect_url, { preserveScroll: true });
        }
    };
    const searchExpoProjects = () => {
        const search = expoSearch.trim();
        router.get(expoIntegration.index_url, {
            ...(search ? { expo_search: search } : {}),
        }, {
            preserveScroll: true,
            replace: true,
        });
    };
    const clearExpoSearch = () => {
        expoSearch = '';
        searchExpoProjects();
    };
    const importExpoProject = (project) => {
        if (importingExpoProjectId) return;

        importingExpoProjectId = project.id;
        expoImportErrors = { ...expoImportErrors, [project.id]: '' };
        router.post(project.import_url, {}, {
            preserveScroll: true,
            onError: (errors) => {
                expoImportErrors = {
                    ...expoImportErrors,
                    [project.id]: firstError(errors, 'Expo project import failed. Please try again.'),
                };
            },
            onCancel: () => {
                expoImportErrors = {
                    ...expoImportErrors,
                    [project.id]: 'Expo project import was cancelled. Please try again.',
                };
            },
            onFinish: () => {
                importingExpoProjectId = null;
            },
        });
    };
    const canBulkImportExpoProject = (project) => !project.app_url && !project.app_archived && project.exists_on_expo;
    const importableExpoProjects = (integration) => integration.projects.filter(canBulkImportExpoProject);
    const selectedExpoProjects = (integration, selection = selectedExpoProjectIds) => importableExpoProjects(integration)
        .filter((project) => selection[project.id]);
    const toggleExpoProject = (project) => {
        selectedExpoProjectIds = {
            ...selectedExpoProjectIds,
            [project.id]: !selectedExpoProjectIds[project.id],
        };
    };
    const toggleAllExpoProjects = (integration) => {
        const projects = importableExpoProjects(integration);
        const shouldSelect = projects.some((project) => !selectedExpoProjectIds[project.id]);
        selectedExpoProjectIds = {
            ...selectedExpoProjectIds,
            ...Object.fromEntries(projects.map((project) => [project.id, shouldSelect])),
        };
    };
    const bulkImportExpoProjects = (integration) => {
        const projects = selectedExpoProjects(integration);
        if (!projects.length || bulkImportingExpoIntegrationId) return;

        bulkImportingExpoIntegrationId = integration.id;
        router.post(integration.bulk_import_url, { project_ids: projects.map((project) => project.id) }, {
            preserveScroll: true,
            onSuccess: () => {
                selectedExpoProjectIds = {
                    ...selectedExpoProjectIds,
                    ...Object.fromEntries(projects.map((project) => [project.id, false])),
                };
            },
            onFinish: () => {
                bulkImportingExpoIntegrationId = null;
            },
        });
    };
    const openCreateWebhook = () => {
        editingEndpoint = null;
        webhookForm.reset();
        webhookForm.events = webhookEventTypes.length ? [webhookEventTypes[0]] : [];
        webhookForm.filters = {
            mobile_app_ids: [],
            tags: [],
            build_statuses: [],
            profile_types: [],
        };
        webhookTagInput = '';
        webhookForm.enabled = true;
        showWebhook = true;
    };
    const openEditWebhook = (endpoint) => {
        editingEndpoint = endpoint;
        webhookForm.name = endpoint.name;
        webhookForm.url = endpoint.url;
        webhookForm.events = [...endpoint.events];
        webhookForm.filters = {
            mobile_app_ids: [...(endpoint.filters?.mobile_app_ids ?? [])],
            tags: [...(endpoint.filters?.tags ?? [])],
            build_statuses: [...(endpoint.filters?.build_statuses ?? [])],
            profile_types: [...(endpoint.filters?.profile_types ?? [])],
        };
        webhookTagInput = webhookForm.filters.tags.join(', ');
        webhookForm.enabled = endpoint.status === 'active';
        webhookForm.clearErrors();
        showWebhook = true;
    };
    const toggleEvent = (eventType) => {
        webhookForm.events = webhookForm.events.includes(eventType)
            ? webhookForm.events.filter((item) => item !== eventType)
            : [...webhookForm.events, eventType];
    };
    const toggleWebhookFilter = (group, value) => {
        const current = webhookForm.filters[group] ?? [];
        webhookForm.filters = {
            ...webhookForm.filters,
            [group]: current.includes(value)
                ? current.filter((item) => item !== value)
                : [...current, value],
        };
    };
    const saveWebhook = () => {
        webhookForm.filters = {
            ...webhookForm.filters,
            tags: [...new Map(webhookTagInput
                .split(',')
                .map((tag) => tag.trim())
                .filter(Boolean)
                .map((tag) => [tag.toLocaleLowerCase(), tag])).values()],
        };
        const options = { preserveScroll: true, onSuccess: () => showWebhook = false };
        if (editingEndpoint) webhookForm.patch(editingEndpoint.update_url, options);
        else webhookForm.post(storeWebhookUrl, options);
    };
    const testWebhook = (endpoint) => router.post(endpoint.test_url, {}, { preserveScroll: true });
    const rotateSecret = (endpoint) => {
        if (confirm('Rotate the signing secret? The old secret will stop working immediately.')) {
            router.post(endpoint.rotate_secret_url, {}, { preserveScroll: true });
        }
    };
    const deleteWebhook = (endpoint) => {
        if (confirm(`Delete webhook “${endpoint.name}”?`)) router.delete(endpoint.destroy_url, { preserveScroll: true });
    };
    const inspectDelivery = async (delivery) => {
        const request = ++inspectorRequest;
        inspectedDelivery = null;
        inspectorError = '';
        inspectorLoading = true;

        try {
            const response = await fetch(delivery.inspect_url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({}));

            if (request !== inspectorRequest) return;
            if (!response.ok) throw new Error(payload?.message ?? 'Delivery details could not be loaded.');
            inspectedDelivery = payload.data;
        } catch (error) {
            if (request === inspectorRequest) {
                inspectorError = error?.message ?? 'Delivery details could not be loaded.';
            }
        } finally {
            if (request === inspectorRequest) inspectorLoading = false;
        }
    };
    const closeDeliveryInspector = () => {
        if (!redeliveringDeliveryId) {
            inspectorRequest += 1;
            inspectorLoading = false;
            inspectedDelivery = null;
            inspectorError = '';
        }
    };
    const redeliverWebhook = () => {
        if (!inspectedDelivery || redeliveringDeliveryId) return;
        redeliveringDeliveryId = inspectedDelivery.id;
        router.post(inspectedDelivery.redeliver_url, {}, {
            preserveScroll: true,
            onSuccess: () => {
                inspectedDelivery = null;
                inspectorError = '';
            },
            onFinish: () => redeliveringDeliveryId = null,
        });
    };
    const webhookFilterLabels = (endpoint) => {
        const filters = endpoint.filters ?? {};
        const labels = [];
        const selectedApps = apps.filter((app) => (filters.mobile_app_ids ?? []).includes(app.id));

        if (selectedApps.length) labels.push(`Apps: ${selectedApps.map((app) => app.name).join(', ')}`);
        if (filters.tags?.length) labels.push(`Tags: ${filters.tags.join(', ')}`);
        if (filters.build_statuses?.length) labels.push(`Status: ${filters.build_statuses.join(', ')}`);
        if (filters.profile_types?.length) labels.push(`Profile: ${filters.profile_types.join(', ')}`);

        return labels;
    };
    const prettyJson = (value) => JSON.stringify(value ?? {}, null, 2);
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
    const statusClass = (status) => ({
        active: 'bg-ok/10 text-ok',
        delivered: 'bg-ok/10 text-ok',
        retry_scheduled: 'bg-warn-text/10 text-warn-fill',
        disabled: 'bg-warn-text/10 text-warn-fill',
        degraded: 'bg-warn-text/10 text-warn-fill',
        exhausted: 'bg-danger/10 text-danger',
        disconnected: 'bg-border-subtle text-muted',
    }[status] ?? 'bg-border-subtle text-muted');

    $: integrationTabs = [
        { value: 'ci_cd', label: 'CI/CD', href: integrationUrls.ci_cd, active: activeTab === 'ci_cd', count: integrations.length },
        { value: 'expo', label: 'Expo EAS', href: integrationUrls.expo, active: activeTab === 'expo', count: expoIntegration.accounts?.length ?? 0, tone: 'violet' },
        { value: 'webhooks', label: 'Webhooks', href: integrationUrls.webhooks, active: activeTab === 'webhooks', count: webhookEndpoints.length, tone: 'emerald' },
        { value: 'notifications', label: 'Notifications', href: integrationUrls.notifications, active: activeTab === 'notifications', count: notificationDestinations.length, tone: 'amber' },
        { value: 'sentry', label: 'Sentry', href: integrationUrls.sentry, active: activeTab === 'sentry', count: sentryDsns.length, tone: 'rose' },
    ];
</script>

<svelte:head><title>Integrations · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="integrations" eyebrow="Workspace settings" title="Integrations" {flash}>
        {#if newWebhookSecret}
            <div class="mb-6 rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3.5">
                <div class="flex items-start gap-3">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-warn-text/10 text-warn-fill"><ShieldCheck class="h-4 w-4" strokeWidth={1.9} /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-warn-fill">Copy this signing secret now</p>
                        <p class="mt-0.5 text-[11px] text-warn-fill">It will not be shown again.</p>
                        <code class="mt-2 block overflow-x-auto rounded-lg bg-text px-3 py-2 font-mono text-[11px] text-warn-text/10">{newWebhookSecret.secret}</code>
                    </div>
                </div>
            </div>
        {/if}

        <div class="mb-4">
            <TabSelector variant="pills" ariaLabel="Integration areas" items={integrationTabs} />
        </div>

        {#if activeTab === 'ci_cd'}
            <section class="space-y-6">
                <section class="card overflow-clip p-0">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-3.5">
                        <div>
                            <h2 class="text-xs font-bold text-text">Connected providers</h2>
                            <p class="mt-0.5 text-xs text-muted">Open a connection to manage linked applications and pipelines.</p>
                        </div>
                        <span class="font-mono text-2xs font-semibold text-faint">{integrations.length} connected</span>
                    </div>

                    {#if integrations.length}
                        <div class="divide-y divide-border-subtle">
                            {#each integrations as integration}
                                <Link
                                    href={integration.detail_url}
                                    class="group grid gap-3 px-5 py-4 transition hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                                    data-testid={`cicd-integration-${integration.id}`}
                                >
                                    <div class="flex min-w-0 items-center gap-3.5">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md font-mono text-2xs font-black text-white {integration.provider === 'gitlab' ? 'bg-warn-fill' : integration.provider === 'expo' ? 'bg-brand' : 'bg-text'}">
                                            {integration.provider === 'github' ? 'GH' : integration.provider === 'gitlab' ? 'GL' : 'EAS'}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                                <h3 class="truncate text-sm font-bold text-text">{integration.name}</h3>
                                                <span class="rounded-full px-2 py-0.5 text-2xs font-bold uppercase {statusClass(integration.status)}">{integration.status}</span>
                                                {#if integration.connection_type === 'self_hosted'}<span class="rounded-full bg-warn-text/10 px-2 py-0.5 text-2xs font-bold uppercase text-warn-fill">self-hosted</span>{/if}
                                            </div>
                                            <p class="mt-1 truncate font-mono text-2xs text-faint">
                                                {integration.provider_name}{#if integration.account_name} · {integration.account_name}{/if}{#if integration.instance_url} · {integration.instance_url}{/if}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 justify-self-start sm:justify-self-end">
                                        <dl class="flex items-center divide-x divide-border-subtle">
                                            <div class="min-w-14 pr-3 text-right">
                                                <dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Apps</dt>
                                                <dd class="mt-0.5 font-mono text-sm font-semibold text-text">{integration.linked_apps_count}</dd>
                                            </div>
                                            <div class="min-w-18 pl-3 text-right">
                                                <dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Pipelines</dt>
                                                <dd class="mt-0.5 font-mono text-sm font-semibold text-text">{integration.pipelines_count}</dd>
                                            </div>
                                        </dl>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted transition group-hover:text-brand">
                                            Manage
                                            <ChevronRight class="h-4 w-4 transition group-hover:translate-x-0.5" strokeWidth={2} />
                                        </span>
                                    </div>
                                </Link>
                            {/each}
                        </div>
                    {:else}
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm font-semibold text-text">No CI/CD providers connected</p>
                            <p class="mt-1 text-xs text-muted">Choose a provider below to connect your first account.</p>
                        </div>
                    {/if}
                </section>

                <section>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xs font-bold text-text">Add a connection</h2>
                            <p class="mt-0.5 text-xs text-muted">Connect another account or CI/CD provider.</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-ok/10 px-2.5 py-1 text-2xs font-semibold text-ok"><ShieldCheck class="h-3.5 w-3.5" strokeWidth={1.8} />Credentials encrypted</span>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        {#each ciCdProviders as provider}
                            <article class="card flex min-h-44 flex-col p-5">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-text font-mono text-2xs font-black text-white">{provider.provider === 'github' ? 'GH' : 'GL'}</span>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-bold text-text">{provider.name}</h3>
                                        <p class="mt-1 text-xs leading-5 text-muted">{provider.description}</p>
                                    </div>
                                </div>
                                <div class="mt-auto pt-5">
                                    {#if provider.enabled}
                                        <a href={provider.connect_url} class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs"><Plus class="h-3.5 w-3.5" />{provider.connected_accounts_count ? 'Add account' : 'Connect'}</a>
                                    {:else}
                                        <span class="rounded-full bg-border-subtle px-2.5 py-1 text-2xs font-semibold text-muted">Not configured</span>
                                    {/if}
                                </div>
                            </article>
                        {/each}

                        <article class="card flex min-h-44 flex-col p-5">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-warn-fill font-mono text-2xs font-black text-white">GL</span>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-bold text-text">Self-hosted GitLab</h3>
                                    <p class="mt-1 text-xs leading-5 text-muted">Connect your own GitLab URL with a personal access token.</p>
                                </div>
                            </div>
                            <div class="mt-auto pt-5">
                                {#if selfHostedGitLab.enabled}
                                    <button class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openSelfHostedGitLab}><Plus class="h-3.5 w-3.5" />{selfHostedGitLab.connected_accounts_count ? 'Add instance' : 'Connect'}</button>
                                {:else}
                                    <span class="rounded-full bg-border-subtle px-2.5 py-1 text-2xs font-semibold text-muted">Not enabled</span>
                                {/if}
                            </div>
                        </article>
                    </div>
                </section>
            </section>
        {:else if activeTab === 'expo'}
            <section class="space-y-4">
                <section class="card flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-text text-[9px] font-black text-white">EAS</span>
                        <div class="min-w-0">
                            <h2 class="text-xs font-bold text-text">Expo Application Services</h2>
                            <p class="mt-0.5 text-[11px] text-muted">Link Expo projects, credentials, builds, and submissions.</p>
                        </div>
                    </div>
                    {#if expoIntegration.enabled}
                        <button class="inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openExpo}><Plus class="h-3.5 w-3.5" />{expoIntegration.accounts?.length ? 'Add account' : 'Connect Expo'}</button>
                    {:else}
                        <span class="rounded-full bg-border-subtle px-2.5 py-1 text-[10px] font-semibold text-muted">Not configured</span>
                    {/if}
                </section>

                {#if expoIntegration.accounts?.length}
                    <form class="grid gap-2 sm:grid-cols-[minmax(240px,1fr)_auto_auto]" on:submit|preventDefault={searchExpoProjects}>
                        <label class="relative min-w-0">
                            <span class="sr-only">Search Expo projects</span>
                            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-faint" />
                            <input class="py-2 pl-9" bind:value={expoSearch} type="search" placeholder="Search Expo projects by name, slug, or project ID…" />
                        </label>
                        <button type="submit" class="px-3 py-2 text-xs">Search</button>
                        {#if expoIntegration.search}
                            <button type="button" class="ghost px-3 py-2 text-xs" on:click={clearExpoSearch}>Clear</button>
                        {/if}
                    </form>
                {/if}

                {#each expoIntegration.accounts ?? [] as integration}
                    <article class="card overflow-clip p-0">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2"><h2 class="truncate text-xs font-bold text-text">{integration.name}</h2><span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {statusClass(integration.status)}">{integration.status}</span></div>
                                <p class="mt-1 font-mono text-[10px] text-faint">{integration.pagination.total} projects · Last sync {formatDate(integration.last_synced_at)}</p>
                            </div>
                            <div class="flex gap-1.5">
                                {#if expoIntegration.enabled}<button class="secondary px-3 py-2 text-xs" disabled={integration.status === 'disconnected' || syncingExpoIntegrationId !== null} on:click={() => syncExpo(integration)}>{syncingExpoIntegrationId === integration.id ? 'Syncing…' : 'Sync projects'}</button>{/if}
                                <button class="ghost px-2.5 py-2 text-xs text-danger" on:click={() => disconnectExpo(integration)}>Disconnect</button>
                            </div>
                        </div>
                        {#if expoSyncErrors[integration.id] || integration.last_sync_error}
                            <div class="border-b border-danger/10 bg-danger/10 px-5 py-3 text-xs font-medium text-danger">{expoSyncErrors[integration.id] || integration.last_sync_error}</div>
                        {/if}
                        {#if integration.projects.length}
                            {#if importableExpoProjects(integration).length}
                                <div class="flex flex-wrap items-center justify-between gap-3 border-y border-border-subtle bg-surface-sunken px-5 py-2.5">
                                    <label class="inline-flex cursor-pointer items-center gap-2.5 text-xs font-semibold text-muted">
                                        <input
                                            class="h-4 w-4 shrink-0 rounded border-control p-0 accent-brand shadow-none focus:ring-2 focus:ring-brand/20"
                                            type="checkbox"
                                            checked={selectedExpoProjects(integration, selectedExpoProjectIds).length === importableExpoProjects(integration).length}
                                            on:change={() => toggleAllExpoProjects(integration)}
                                        />
                                        <span>Select visible</span>
                                    </label>
                                    <button class="px-3 py-2 text-xs" disabled={!selectedExpoProjects(integration, selectedExpoProjectIds).length || bulkImportingExpoIntegrationId !== null} on:click={() => bulkImportExpoProjects(integration)}>{bulkImportingExpoIntegrationId === integration.id ? 'Queueing…' : `Import selected (${selectedExpoProjects(integration, selectedExpoProjectIds).length})`}</button>
                                </div>
                            {/if}
                            <div class="divide-y divide-border-subtle">
                                {#each integration.projects as project}
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 transition hover:bg-surface-sunken">
                                        <div class="flex min-w-0 items-center gap-3">
                                            {#if canBulkImportExpoProject(project)}
                                                <input
                                                    class="h-4 w-4 shrink-0 rounded border-control p-0 accent-brand shadow-none focus:ring-2 focus:ring-brand/20"
                                                    aria-label={`Select ${project.name} for import`}
                                                    type="checkbox"
                                                    checked={Boolean(selectedExpoProjectIds[project.id])}
                                                    on:change={() => toggleExpoProject(project)}
                                                />
                                            {/if}
                                            <span class="relative grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-lg bg-gradient-to-br from-brand to-brand text-[10px] font-bold text-white ring-1 ring-border">
                                                {project.name?.slice(0, 2).toUpperCase() || 'EX'}
                                                {#if project.icon_url}
                                                    <img src={project.icon_url} alt={`${project.name} icon`} loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 h-full w-full bg-surface-raised object-cover" on:error={(event) => event.currentTarget.remove()} />
                                                {/if}
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2"><p class="truncate text-[12px] font-semibold text-text">{project.name}</p>{#if project.app_url}<span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-ok/10 px-1.5 py-0.5 text-[9px] font-bold uppercase text-ok"><CheckCircle2 class="h-3 w-3" />In App Manager</span>{:else if project.app_archived}<span class="inline-flex shrink-0 items-center rounded-full bg-warn-text/10 px-1.5 py-0.5 text-[9px] font-bold uppercase text-warn-fill">In trash</span>{/if}</div>
                                                <code class="mt-0.5 block truncate text-[10px] text-faint">{project.full_name}</code>
                                                <p class="mt-0.5 truncate font-mono text-[9px] text-faint">Slug {project.slug} · Project ID {project.project_id}</p>
                                                {#if expoImportErrors[project.id]}<p class="mt-1 text-xs font-medium text-danger">{expoImportErrors[project.id]}</p>{/if}
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            {#if !project.exists_on_expo}<span class="rounded-full bg-danger/10 px-2 py-1 text-[10px] font-semibold text-danger">Missing on Expo</span>{/if}
                                            {#if project.app_url}<Link href={project.app_url} class="secondary border-ok px-3 py-2 text-xs text-ok hover:bg-ok/10">{project.app_name}</Link>{:else if project.exists_on_expo}<button class="secondary px-3 py-2 text-xs" disabled={importingExpoProjectId !== null} on:click={() => importExpoProject(project)}>{importingExpoProjectId === project.id ? (project.app_archived ? 'Restoring…' : 'Importing…') : (project.app_archived ? 'Restore & import' : 'Import as application')}</button>{/if}
                                        </div>
                                    </div>
                                {/each}
                                {#if integration.pagination.last_page > 1}
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                        <p class="text-xs font-medium text-muted">
                                            Showing {integration.pagination.from}–{integration.pagination.to} of {integration.pagination.total} projects
                                        </p>
                                        <nav class="flex items-center gap-2" aria-label={`Projects for ${integration.name}`}>
                                            {#if integration.pagination.previous_url}
                                                <Link href={integration.pagination.previous_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>
                                            {:else}
                                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>
                                            {/if}
                                            <span class="min-w-20 text-center text-xs font-medium text-muted">Page {integration.pagination.page} of {integration.pagination.last_page}</span>
                                            {#if integration.pagination.next_url}
                                                <Link href={integration.pagination.next_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>
                                            {:else}
                                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>
                                            {/if}
                                        </nav>
                                    </div>
                                {/if}
                            </div>
                        {:else}
                            <div class="px-5 py-9 text-center text-sm text-muted">
                                {expoIntegration.search ? `No Expo projects match “${expoIntegration.search}” for this account.` : 'No Expo projects found for this account.'}
                            </div>
                        {/if}
                    </article>
                {/each}
            </section>
        {:else if activeTab === 'webhooks'}
            <section class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-xs font-bold text-text">
                            Outbound endpoints
                            <span class="ml-1 font-mono text-[11px] font-medium text-faint">{webhookEndpoints.length} in {navigationTeam?.name}</span>
                        </h2>
                        <p class="mt-1 text-[11px] text-muted">HMAC-SHA256 signed and retried automatically.</p>
                    </div>
                    {#if webhooksEnabled}
                        <button class="inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateWebhook}><Plus class="h-3.5 w-3.5" />New endpoint</button>
                    {:else}
                        <span class="rounded-full bg-border-subtle px-2.5 py-1 text-[10px] font-semibold text-muted">Not enabled</span>
                    {/if}
                </div>
                {#if webhookEndpoints.length}
                    {#each webhookEndpoints as endpoint}
                        <article class="card overflow-clip p-0">
                            <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-3.5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-ok/10 text-ok ring-1 ring-inset ring-ok"><Webhook class="h-4 w-4" strokeWidth={1.9} /></span>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2"><h3 class="truncate text-xs font-bold text-text">{endpoint.name}</h3><span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {statusClass(endpoint.status)}">{endpoint.status}</span></div>
                                        <code class="mt-0.5 block max-w-xl truncate text-[10px] text-faint">{endpoint.url}</code>
                                        <p class="mt-1 font-mono text-[9px] text-faint">Secret ••••{endpoint.secret_hint} · Last delivery {formatDate(endpoint.last_delivered_at)}</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <button class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => testWebhook(endpoint)}><Send class="h-3.5 w-3.5" />Send test</button>
                                    <button class="secondary px-3 py-2 text-xs" on:click={() => openEditWebhook(endpoint)}>Edit</button>
                                    <button class="ghost px-2.5 py-2 text-xs" on:click={() => rotateSecret(endpoint)}>Rotate secret</button>
                                    <button class="ghost px-2 py-2 text-xs text-danger" on:click={() => deleteWebhook(endpoint)}>Delete</button>
                                </div>
                            </div>
                            <div class="border-t border-border-subtle px-5 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    {#each endpoint.events.slice(0, 6) as event}<span class="rounded-md bg-brand-soft px-2 py-1 text-[9px] font-semibold text-brand">{event}</span>{/each}
                                    {#if endpoint.events.length > 6}
                                        <details class="relative">
                                            <summary class="cursor-pointer list-none rounded-md bg-border-subtle px-2 py-1 text-[9px] font-semibold text-muted hover:bg-border">+{endpoint.events.length - 6} events</summary>
                                            <div class="mt-2 flex flex-wrap gap-1.5 rounded-md border border-border bg-surface-sunken p-3">
                                                {#each endpoint.events.slice(6) as event}<span class="rounded-md bg-surface-raised px-2 py-1 text-[9px] font-semibold text-brand ring-1 ring-inset ring-brand-soft-line">{event}</span>{/each}
                                            </div>
                                        </details>
                                    {/if}
                                </div>
                                {#if webhookFilterLabels(endpoint).length}
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        {#each webhookFilterLabels(endpoint) as filter}
                                            <span class="rounded-md border border-warn-fill bg-warn-text/10 px-2 py-1 text-[9px] font-semibold text-warn-fill">{filter}</span>
                                        {/each}
                                    </div>
                                {:else}
                                    <p class="mt-2 text-[10px] text-faint">No filters · all matching events are delivered</p>
                                {/if}
                            </div>
                            {#if endpoint.deliveries.length}
                                <div class="hidden grid-cols-[minmax(0,1fr)_90px_70px_115px_145px] items-center gap-3 border-y border-border-subtle bg-surface-sunken/80 px-5 py-2 text-[9px] font-bold tracking-[0.1em] text-faint uppercase lg:grid">
                                    <span>Delivery</span>
                                    <span>Status</span>
                                    <span>Attempts</span>
                                    <span>HTTP / duration</span>
                                    <span>Created</span>
                                </div>
                                <div class="divide-y divide-border-subtle">
                                    {#each endpoint.deliveries as delivery}
                                        <button type="button" class="grid w-full gap-3 rounded-none bg-transparent px-5 py-3 text-left text-text shadow-none transition hover:bg-surface-sunken lg:grid-cols-[minmax(0,1fr)_90px_70px_115px_145px] lg:items-center" on:click={() => inspectDelivery(delivery)}>
                                            <span class="flex min-w-0 items-center gap-3">
                                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand ring-1 ring-inset ring-brand-soft-line"><Send class="h-3.5 w-3.5" strokeWidth={1.9} /></span>
                                                <span class="truncate text-[11px] font-semibold text-text">{delivery.event}</span>
                                            </span>
                                            <span><span class="rounded-full px-2 py-0.5 text-[9px] font-bold {statusClass(delivery.status)}">{delivery.status}</span></span>
                                            <span class="font-mono text-[10px] text-muted">{delivery.attempt_count}</span>
                                            <span class="font-mono text-[10px] text-muted">{delivery.response_status ?? '—'} · {delivery.duration_ms === null ? '—' : `${delivery.duration_ms} ms`}</span>
                                            <span class="text-[10px] text-faint">{formatDate(delivery.created_at)}</span>
                                        </button>
                                    {/each}
                                </div>
                            {:else}
                                <div class="border-t border-border-subtle px-5 py-8 text-center">
                                    <span class="mx-auto grid h-9 w-9 place-items-center rounded-md bg-border-subtle text-faint"><Send class="h-4 w-4" strokeWidth={1.8} /></span>
                                    <p class="mt-2 text-xs font-semibold text-muted">No deliveries yet</p>
                                </div>
                            {/if}
                        </article>
                    {/each}
                {:else}
                    <div class="card py-16 text-center">
                        <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-md bg-ok/10 text-ok"><Webhook class="h-6 w-6" strokeWidth={1.7} /></span>
                        <h3 class="text-sm font-bold">No webhook endpoints</h3>
                        <p class="mx-auto mt-2 max-w-sm text-sm text-muted">{webhooksEnabled ? 'Create an endpoint and choose the events it should receive.' : 'Outbound webhooks are disabled in this environment.'}</p>
                        {#if webhooksEnabled}<button class="mt-5 inline-flex items-center gap-1.5" on:click={openCreateWebhook}><Plus class="h-4 w-4" />New endpoint</button>{/if}
                    </div>
                {/if}
            </section>
        {:else if activeTab === 'sentry'}
            <SentryPanel
                enabled={sentryEnabled}
                storeUrl={storeSentryUrl}
                dsns={sentryDsns}
                events={sentryEvents}
                eventFilters={sentryEventFilters}
                apps={sentryApps}
                appFilters={sentryAppFilters}
                appsCount={sentryAppsCount}
                blueprints={sentryBlueprints}
                indexUrl={integrationUrls.sentry}
            />
        {:else if activeTab === 'notifications'}
            <NotificationsPanel
                enabled={notificationsEnabled}
                storeUrl={storeNotificationUrl}
                providers={notificationProviders}
                destinations={notificationDestinations}
                events={notificationEvents}
                initialDestinationId={selectedNotificationDestinationId}
                globalVariables={notificationGlobalVariables}
            />
        {/if}
</WorkspacePage>

{#if showSelfHostedGitLab}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showSelfHostedGitLab = false)}>
        <form class="modal max-w-lg" on:submit|preventDefault={connectSelfHostedGitLab}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">Connect self-hosted GitLab</h2><p class="mt-1 text-sm text-muted">Use the root URL of your GitLab instance and a personal access token.</p></div><button type="button" class="ghost p-2" on:click={() => showSelfHostedGitLab = false}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">GitLab instance URL</span><input type="url" bind:value={selfHostedGitLabForm.instance_url} placeholder="https://gitlab.example.com" required />{#if selfHostedGitLabForm.errors.instance_url}<span class="error">{selfHostedGitLabForm.errors.instance_url}</span>{/if}</label>
                <label><span class="label">Personal access token</span><input type="password" bind:value={selfHostedGitLabForm.access_token} autocomplete="off" placeholder="glpat-…" required /><span class="mt-1 block text-xs text-muted">Create the token in GitLab with the <code>api</code> scope. It is encrypted at rest and is never shown again.</span>{#if selfHostedGitLabForm.errors.access_token}<span class="error">{selfHostedGitLabForm.errors.access_token}</span>{/if}</label>
                <div class="rounded-md border border-brand-soft-line bg-brand-soft px-4 py-3 text-xs leading-5 text-brand">The API endpoint is detected as <code>&lt;instance URL&gt;/api/v4</code>. You can connect multiple instances alongside GitLab.com.</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showSelfHostedGitLab = false}>Cancel</button><button disabled={selfHostedGitLabForm.processing}>{selfHostedGitLabForm.processing ? 'Connecting…' : 'Connect GitLab'}</button></div>
        </form>
    </div>
{/if}

{#if showExpo}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showExpo = false)}>
        <form class="modal max-w-lg" on:submit|preventDefault={connectExpo}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">Connect Expo account</h2><p class="mt-1 text-sm text-muted">Use a robot-user token for production or a personal token for testing.</p></div><button type="button" class="ghost p-2" on:click={() => showExpo = false}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Expo access token</span><input type="password" bind:value={expoForm.access_token} autocomplete="off" placeholder="Expo token" required /><span class="mt-1 block text-xs text-muted">The token is encrypted at rest and is never shown again.</span>{#if expoForm.errors.access_token}<span class="error">{expoForm.errors.access_token}</span>{/if}</label>
                <label><span class="label">Expo account or organization <span class="font-normal text-faint">(optional for a single account)</span></span><input bind:value={expoForm.account_name} placeholder="acme" />{#if expoForm.errors.account_name}<span class="error">{expoForm.errors.account_name}</span>{/if}</label>
                {#if expoForm.errors.expo}<span class="error">{expoForm.errors.expo}</span>{/if}
                {#if expoClientError}<span class="error">{expoClientError}</span>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showExpo = false}>Cancel</button><button type="button" data-testid="expo-connect-submit" disabled={expoSubmitting || expoForm.processing} on:click={connectExpo}>{expoSubmitting || expoForm.processing ? 'Connecting…' : 'Connect Expo'}</button></div>
        </form>
    </div>
{/if}

{#if showWebhook}
    <div class="modal-backdrop" role="presentation">
        <form class="modal max-w-3xl" on:submit|preventDefault={saveWebhook}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><h2 class="text-lg font-bold">{editingEndpoint ? 'Edit webhook endpoint' : 'New webhook endpoint'}</h2><p class="mt-1 text-sm text-muted">Choose events and optionally narrow them by application or build context.</p></div>
                <button type="button" class="ghost p-2" on:click={() => showWebhook = false}>✕</button>
            </div>
            <div class="space-y-6 p-6">
                <label><span class="label">Name</span><input bind:value={webhookForm.name} required />{#if webhookForm.errors.name}<span class="error">{webhookForm.errors.name}</span>{/if}</label>
                <label><span class="label">Endpoint URL</span><input type="url" bind:value={webhookForm.url} placeholder="https://example.com/webhooks/app-manager" required />{#if webhookForm.errors.url}<span class="error">{webhookForm.errors.url}</span>{/if}</label>
                <fieldset>
                    <legend class="label">Events</legend>
                    <div class="grid gap-2 rounded-md border border-border p-4 sm:grid-cols-2">
                        {#each webhookEventTypes as eventType}
                            <label class="flex cursor-pointer items-center gap-2 text-xs"><input class="h-4 w-4" type="checkbox" checked={webhookForm.events.includes(eventType)} on:change={() => toggleEvent(eventType)} /><span>{eventType}</span></label>
                        {/each}
                    </div>
                    {#if webhookForm.errors.events}<span class="error">{webhookForm.errors.events}</span>{/if}
                </fieldset>
                <fieldset class="space-y-5 rounded-md border border-border bg-surface-sunken p-5">
                    <div>
                        <legend class="text-sm font-bold text-text">Delivery filters</legend>
                        <p class="mt-1 text-xs leading-5 text-muted">Empty groups match everything. Populated groups use AND; values inside a group use OR. Events without the required context are skipped.</p>
                    </div>
                    <div>
                        <span class="label">Applications</span>
                        <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border bg-surface-raised p-2">
                            {#each apps as app}
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg px-3 py-2.5 text-sm transition hover:bg-surface-sunken">
                                    <input class="mt-0.5 h-4 w-4 shrink-0 accent-brand shadow-none" type="checkbox" checked={webhookForm.filters.mobile_app_ids.includes(app.id)} on:change={() => toggleWebhookFilter('mobile_app_ids', app.id)} />
                                    <span class="min-w-0"><span class="block truncate font-medium text-text">{app.name}</span>{#if app.tags?.length}<span class="mt-0.5 block truncate text-[10px] text-faint">{app.tags.join(', ')}</span>{/if}</span>
                                </label>
                            {/each}
                        </div>
                        {#if webhookForm.errors['filters.mobile_app_ids'] || webhookForm.errors['filters.mobile_app_ids.0']}<span class="error">{webhookForm.errors['filters.mobile_app_ids'] ?? webhookForm.errors['filters.mobile_app_ids.0']}</span>{/if}
                    </div>
                    <label>
                        <span class="label">Tags</span>
                        <input bind:value={webhookTagInput} placeholder="release, customer-a" />
                        <span class="mt-1 block text-xs text-muted">Comma-separated. A tag may come from the application, build, artifact, or credential captured by the event.</span>
                        {#if webhookForm.errors['filters.tags']}<span class="error">{webhookForm.errors['filters.tags']}</span>{/if}
                    </label>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <fieldset>
                            <legend class="label">Build status</legend>
                            <div class="space-y-2 rounded-md border border-border bg-surface-raised p-3">
                                {#each buildStatuses as status}
                                    <label class="flex cursor-pointer items-center gap-2 text-xs capitalize"><input class="h-4 w-4" type="checkbox" checked={webhookForm.filters.build_statuses.includes(status)} on:change={() => toggleWebhookFilter('build_statuses', status)} /><span>{status.replaceAll('_', ' ')}</span></label>
                                {/each}
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="label">Build profile</legend>
                            <div class="space-y-2 rounded-md border border-border bg-surface-raised p-3">
                                {#each profileTypes as profile}
                                    <label class="flex cursor-pointer items-center gap-2 text-xs capitalize"><input class="h-4 w-4" type="checkbox" checked={webhookForm.filters.profile_types.includes(profile)} on:change={() => toggleWebhookFilter('profile_types', profile)} /><span>{profile}</span></label>
                                {/each}
                            </div>
                        </fieldset>
                    </div>
                    {#if webhookForm.errors.filters}<span class="error">{webhookForm.errors.filters}</span>{/if}
                </fieldset>
                {#if editingEndpoint}<label class="flex items-center gap-2 text-sm"><input class="h-4 w-4" type="checkbox" bind:checked={webhookForm.enabled} /><span>Endpoint enabled</span></label>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showWebhook = false}>Cancel</button><button disabled={webhookForm.processing || !webhookForm.events.length}>{webhookForm.processing ? 'Saving…' : 'Save endpoint'}</button></div>
        </form>
    </div>
{/if}

{#if inspectorLoading || inspectedDelivery || inspectorError}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeDeliveryInspector()}>
        <div class="modal max-w-4xl" role="dialog" aria-modal="true" aria-labelledby="delivery-inspector-title">
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><p class="eyebrow mb-1">Delivery inspector</p><h2 id="delivery-inspector-title" class="text-lg font-bold">{inspectedDelivery?.event_type ?? 'Loading delivery…'}</h2>{#if inspectedDelivery}<code class="mt-1 block text-[11px] text-faint">{inspectedDelivery.id}</code>{/if}</div>
                <button type="button" class="ghost p-2" aria-label="Close delivery inspector" on:click={closeDeliveryInspector}>✕</button>
            </div>
            {#if inspectorLoading}
                <div class="p-12 text-center text-sm text-muted">Loading delivery details…</div>
            {:else if inspectorError}
                <div class="p-6"><div class="rounded-md border border-danger bg-danger/10 px-4 py-3 text-sm text-danger">{inspectorError}</div></div>
            {:else if inspectedDelivery}
                <div class="space-y-6 p-6">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-md border border-border p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-faint">Status</span><p class="mt-1"><span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {statusClass(inspectedDelivery.status)}">{inspectedDelivery.status}</span></p></div>
                        <div class="rounded-md border border-border p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-faint">Attempts</span><p class="mt-1 text-sm font-semibold">{inspectedDelivery.attempt_count}</p></div>
                        <div class="rounded-md border border-border p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-faint">HTTP / duration</span><p class="mt-1 text-sm font-semibold">{inspectedDelivery.response_status ?? '—'} · {inspectedDelivery.duration_ms === null ? '—' : `${inspectedDelivery.duration_ms} ms`}</p></div>
                        <div class="rounded-md border border-border p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-faint">Created</span><p class="mt-1 text-xs font-semibold">{formatDate(inspectedDelivery.created_at)}</p></div>
                    </div>
                    <div>
                        <span class="label">Endpoint</span>
                        <code class="block overflow-x-auto rounded-md border border-border bg-surface-sunken px-4 py-3 text-xs text-text">{inspectedDelivery.endpoint_url}</code>
                    </div>
                    {#if inspectedDelivery.last_error}
                        <div><span class="label">Last error</span><div class="rounded-md border border-danger bg-danger/10 px-4 py-3 text-xs leading-5 text-danger">{inspectedDelivery.last_error}</div></div>
                    {/if}
                    <div class="grid gap-5 lg:grid-cols-2">
                        <div><span class="label">Request payload</span><pre class="max-h-96 overflow-auto rounded-md border border-border bg-surface-sunken p-4 text-[11px] leading-5 text-text">{prettyJson(inspectedDelivery.payload)}</pre></div>
                        <div><span class="label">Response body</span><pre class="max-h-96 min-h-32 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-surface-sunken p-4 text-[11px] leading-5 text-text">{inspectedDelivery.response_body || 'No response body captured.'}</pre></div>
                    </div>
                    <dl class="grid gap-3 text-xs sm:grid-cols-2">
                        <div><dt class="text-faint">Event ID</dt><dd class="mt-1 break-all font-mono text-text">{inspectedDelivery.event_id}</dd></div>
                        <div><dt class="text-faint">Schema / signature</dt><dd class="mt-1 font-mono text-text">v{inspectedDelivery.schema_version} / {inspectedDelivery.signature_version}</dd></div>
                        <div><dt class="text-faint">Next attempt</dt><dd class="mt-1 text-text">{formatDate(inspectedDelivery.next_attempt_at)}</dd></div>
                        <div><dt class="text-faint">Delivered / exhausted</dt><dd class="mt-1 text-text">{formatDate(inspectedDelivery.delivered_at)} / {formatDate(inspectedDelivery.exhausted_at)}</dd></div>
                    </dl>
                </div>
                <div class="flex flex-wrap justify-between gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4">
                    <button type="button" class="secondary" on:click={closeDeliveryInspector}>Close</button>
                    <button type="button" disabled={Boolean(redeliveringDeliveryId) || !['delivered', 'exhausted'].includes(inspectedDelivery.status)} on:click={redeliverWebhook}>{redeliveringDeliveryId ? 'Queueing…' : 'Redeliver'}</button>
                </div>
            {/if}
        </div>
    </div>
{/if}
