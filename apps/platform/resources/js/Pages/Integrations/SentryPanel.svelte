<script>
    import { router, useForm } from '@inertiajs/svelte';
    import {
        Activity,
        AlertTriangle,
        ArrowRight,
        Braces,
        Check,
        Copy,
        Pencil,
        Plus,
        Route,
        Server,
        Trash2,
    } from '@lucide/svelte';

    export let enabled = false;
    export let storeUrl = '';
    export let dsns = [];
    export let events = [];
    export let apps = [];

    let showForm = false;
    let editingDsn = null;
    let copiedId = null;
    let eventFilter = 'all';

    const form = useForm({
        name: '',
        routing_method: 'event',
        event_source: 'auto',
        app_key: 'app.slug',
        identifier_key: 'app.identifier',
        routes: [],
        forward_enabled: false,
        upstream_dsn: '',
        enabled: true,
    });

    const targetKey = (mobileAppId, identifierId = null) => `${mobileAppId}:${identifierId ?? '*'}`;
    const routeKey = (route) => targetKey(route.mobile_app_id, route.app_identifier_id);
    const hasTarget = (mobileAppId, identifierId = null) => form.routes.some((route) => routeKey(route) === targetKey(mobileAppId, identifierId));
    const toggleTarget = (mobileAppId, identifierId = null) => {
        const key = targetKey(mobileAppId, identifierId);

        if (form.routes.some((route) => routeKey(route) === key)) {
            form.routes = form.routes.filter((route) => routeKey(route) !== key);
            return;
        }

        // An application-wide route and identifier-specific routes are mutually exclusive.
        form.routes = form.routes.filter((route) => route.mobile_app_id !== mobileAppId);
        form.routes = [...form.routes, {
            mobile_app_id: mobileAppId,
            app_identifier_id: identifierId,
        }];
    };

    const openCreate = () => {
        editingDsn = null;
        form.reset();
        form.name = '';
        form.routing_method = 'event';
        form.event_source = 'auto';
        form.app_key = 'app.slug';
        form.identifier_key = 'app.identifier';
        form.routes = apps[0] ? [{ mobile_app_id: apps[0].id, app_identifier_id: null }] : [];
        form.forward_enabled = false;
        form.upstream_dsn = '';
        form.enabled = true;
        form.clearErrors();
        showForm = true;
    };

    const openEdit = (dsn) => {
        editingDsn = dsn;
        form.name = dsn.name;
        form.routing_method = dsn.routing_method;
        form.event_source = dsn.event_source ?? 'auto';
        form.app_key = dsn.app_key;
        form.identifier_key = dsn.identifier_key ?? '';
        form.routes = dsn.routes.map((route) => ({
            mobile_app_id: route.mobile_app_id,
            app_identifier_id: route.app_identifier_id,
        }));
        form.forward_enabled = dsn.upstream_configured;
        form.upstream_dsn = '';
        form.enabled = dsn.status === 'active';
        form.clearErrors();
        showForm = true;
    };

    const save = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                showForm = false;
                editingDsn = null;
            },
        };

        if (editingDsn) form.patch(editingDsn.update_url, options);
        else form.post(storeUrl, options);
    };

    const remove = (dsn) => {
        if (confirm(`Delete Sentry DSN “${dsn.name}”? SDKs using it will stop sending events.`)) {
            router.delete(dsn.destroy_url, { preserveScroll: true });
        }
    };

    const copyDsn = async (value, key) => {
        await navigator.clipboard.writeText(value);
        copiedId = key;
        setTimeout(() => {
            if (copiedId === key) copiedId = null;
        }, 1600);
    };

    const methodLabel = (dsn) => ({
        query: `Query · ${dsn.app_key}${dsn.identifier_key ? ` + ${dsn.identifier_key}` : ''}`,
        header: `Headers · ${dsn.app_key}${dsn.identifier_key ? ` + ${dsn.identifier_key}` : ''}`,
        event: `${dsn.event_source === 'auto' ? 'Event metadata' : dsn.event_source} · ${dsn.app_key}${dsn.identifier_key ? ` + ${dsn.identifier_key}` : ''}`,
    }[dsn.routing_method] ?? dsn.routing_method);

    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
    const eventTitle = (event) => event.title || event.transaction || (event.item_type === 'transaction' ? 'Transaction' : 'Untitled event');
    const statusClass = (status) => ({
        active: 'bg-ok/10 text-ok',
        forwarded: 'bg-ok/10 text-ok',
        pending: 'bg-brand-soft text-brand',
        retry_scheduled: 'bg-warn-text/10 text-warn-fill',
        exhausted: 'bg-danger/10 text-danger',
        disabled: 'bg-warn-text/10 text-warn-fill',
        stored: 'bg-border-subtle text-muted',
    }[status] ?? 'bg-border-subtle text-muted');

    $: visibleEvents = events.filter((event) => eventFilter === 'all'
        || (eventFilter === 'errors' && event.item_type === 'event')
        || (eventFilter === 'traces' && event.item_type === 'transaction'));
</script>

<section class="space-y-6">
    <section class="card overflow-hidden p-0">
        <div class="grid gap-5 border-b border-border-subtle px-5 py-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="flex items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-brand-soft font-mono text-xs font-black text-brand ring-1 ring-inset ring-brand-soft-line">SE</span>
                <div>
                    <h2 class="text-sm font-bold text-text">Sentry event relay</h2>
                    <p class="mt-1 max-w-2xl text-xs leading-5 text-muted">Use one compatible DSN across applications, route errors and traces to the right app, then optionally relay the untouched envelope to your own Sentry project.</p>
                </div>
            </div>
            {#if enabled}
                <button class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs" disabled={!apps.length} on:click={openCreate}>
                    <Plus class="h-3.5 w-3.5" />New routed DSN
                </button>
            {/if}
        </div>

        <div class="grid divide-y divide-border-subtle bg-surface-sunken/70 md:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr] md:divide-x md:divide-y-0">
            <div class="px-4 py-3.5"><span class="text-[9px] font-bold tracking-[0.12em] text-faint uppercase">01 · SDK</span><p class="mt-1 text-xs font-semibold">Sentry envelope</p></div>
            <span class="hidden place-items-center px-2 text-faint md:grid"><ArrowRight class="h-3.5 w-3.5" /></span>
            <div class="px-4 py-3.5"><span class="text-[9px] font-bold tracking-[0.12em] text-faint uppercase">02 · Signal</span><p class="mt-1 text-xs font-semibold">Query, header, or scope</p></div>
            <span class="hidden place-items-center px-2 text-faint md:grid"><ArrowRight class="h-3.5 w-3.5" /></span>
            <div class="px-4 py-3.5"><span class="text-[9px] font-bold tracking-[0.12em] text-faint uppercase">03 · Route</span><p class="mt-1 text-xs font-semibold">App + package / domain</p></div>
            <span class="hidden place-items-center px-2 text-faint md:grid"><ArrowRight class="h-3.5 w-3.5" /></span>
            <div class="px-4 py-3.5"><span class="text-[9px] font-bold tracking-[0.12em] text-faint uppercase">04 · Relay</span><p class="mt-1 text-xs font-semibold">Optional upstream DSN</p></div>
        </div>
    </section>

    {#if dsns.length}
        <div class="space-y-4">
            {#each dsns as dsn (dsn.id)}
                <article class="card overflow-hidden p-0">
                    <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-text">{dsn.name}</h3>
                                <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {statusClass(dsn.status)}">{dsn.status}</span>
                                {#if dsn.upstream_configured}<span class="rounded-full bg-brand-soft px-2 py-0.5 text-[9px] font-bold uppercase text-brand">relay on</span>{/if}
                                {#if dsn.consecutive_forward_failures}<span class="rounded-full bg-danger/10 px-2 py-0.5 text-[9px] font-bold text-danger">{dsn.consecutive_forward_failures} relay failures</span>{/if}
                            </div>
                            <p class="mt-1 text-[11px] text-muted">{methodLabel(dsn)} · {dsn.routes.length} target{dsn.routes.length === 1 ? '' : 's'} · {dsn.events_count} captured</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" class="ghost p-2" title="Edit routed DSN" aria-label={`Edit ${dsn.name}`} on:click={() => openEdit(dsn)}><Pencil class="h-3.5 w-3.5" /></button>
                            <button type="button" class="ghost p-2 text-danger" title="Delete routed DSN" aria-label={`Delete ${dsn.name}`} on:click={() => remove(dsn)}><Trash2 class="h-3.5 w-3.5" /></button>
                        </div>
                    </div>

                    <div class="border-y border-border-subtle bg-text px-4 py-3 text-white">
                        <div class="flex items-center gap-3">
                            <code class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap font-mono text-[11px] text-white">{dsn.dsn}</code>
                            <button type="button" class="grid h-8 w-8 shrink-0 place-items-center bg-white/10 p-0 text-white shadow-none hover:bg-white/20" aria-label="Copy base DSN" on:click={() => copyDsn(dsn.dsn, dsn.id)}>
                                {#if copiedId === dsn.id}<Check class="h-3.5 w-3.5" />{:else}<Copy class="h-3.5 w-3.5" />{/if}
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_220px]">
                        <div class="border-b border-border-subtle px-5 py-4 lg:border-r lg:border-b-0">
                            <span class="text-[9px] font-bold tracking-[0.12em] text-faint uppercase">Application routes · each has a direct SDK DSN</span>
                            <div class="mt-2 flex flex-wrap gap-2">
                                {#each dsn.routes as target}
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-raised px-2.5 py-1 text-[10px] text-muted">
                                        <Route class="h-3 w-3 text-brand" />
                                        <strong class="font-semibold text-text">{target.app_slug}</strong>
                                        {#if target.identifier}<span class="font-mono text-faint">{target.identifier}</span>{:else}<span class="text-faint">any target</span>{/if}
                                        <button type="button" class="ghost -mr-1 ml-0.5 grid h-5 w-5 place-items-center p-0" title="Copy direct routed DSN" aria-label={`Copy direct DSN for ${target.app_slug}`} on:click={() => copyDsn(target.dsn, `${dsn.id}:${target.mobile_app_id}:${target.app_identifier_id ?? '*'}`)}>
                                            {#if copiedId === `${dsn.id}:${target.mobile_app_id}:${target.app_identifier_id ?? '*'}`}<Check class="h-2.5 w-2.5 text-ok" />{:else}<Copy class="h-2.5 w-2.5" />{/if}
                                        </button>
                                    </span>
                                {/each}
                            </div>
                        </div>
                        <dl class="grid grid-cols-2 gap-3 bg-surface-sunken/60 px-5 py-4 text-[10px] lg:grid-cols-1">
                            <div><dt class="text-faint">Last event</dt><dd class="mt-0.5 font-semibold text-text">{formatDate(dsn.last_event_at)}</dd></div>
                            <div><dt class="text-faint">Last relay</dt><dd class="mt-0.5 font-semibold text-text">{dsn.upstream_configured ? formatDate(dsn.last_forwarded_at) : 'Not configured'}</dd></div>
                        </dl>
                    </div>
                </article>
            {/each}
        </div>
    {:else}
        <div class="card py-14 text-center">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><Activity class="h-6 w-6" strokeWidth={1.7} /></span>
            <h3 class="mt-4 text-sm font-bold">No routed Sentry DSNs</h3>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">Create a DSN, attach one or more application targets, and point a Sentry SDK at it.</p>
            {#if enabled && apps.length}<button class="mt-5 inline-flex items-center gap-1.5" on:click={openCreate}><Plus class="h-4 w-4" />New routed DSN</button>{/if}
            {#if !apps.length}<p class="mt-4 text-xs font-medium text-warn-fill">Create an application before configuring a routed DSN.</p>{/if}
        </div>
    {/if}

    <section class="card overflow-hidden p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-3.5">
            <div><h2 class="text-xs font-bold text-text">Recent telemetry</h2><p class="mt-0.5 text-xs text-muted">Errors and transactions already resolved to an application.</p></div>
            <div class="inline-flex rounded-md bg-surface-sunken p-0.5">
                {#each [['all', 'All'], ['errors', 'Errors'], ['traces', 'Traces']] as filter}
                    <button type="button" class="rounded-sm px-2.5 py-1 text-[10px] font-semibold shadow-none {eventFilter === filter[0] ? 'bg-surface-raised text-text' : 'bg-transparent text-muted'}" on:click={() => eventFilter = filter[0]}>{filter[1]}</button>
                {/each}
            </div>
        </div>
        {#if visibleEvents.length}
            <div class="divide-y divide-border-subtle">
                {#each visibleEvents as event (event.id)}
                    <div class="grid gap-3 px-5 py-3.5 lg:grid-cols-[minmax(0,1fr)_180px_120px_150px] lg:items-center">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-md {event.item_type === 'transaction' ? 'bg-brand-soft text-brand' : 'bg-danger/10 text-danger'}">
                                {#if event.item_type === 'transaction'}<Activity class="h-3.5 w-3.5" />{:else}<AlertTriangle class="h-3.5 w-3.5" />{/if}
                            </span>
                            <div class="min-w-0"><p class="truncate text-xs font-semibold text-text">{eventTitle(event)}</p><p class="mt-0.5 truncate font-mono text-[9px] text-faint">{event.trace_id ?? event.event_id ?? event.dsn_name}</p></div>
                        </div>
                        <div class="min-w-0"><p class="truncate text-[10px] font-semibold text-text">{event.app_name}</p><p class="truncate font-mono text-[9px] text-faint">{event.identifier ?? event.app_slug}</p></div>
                        <div><span class="rounded-full px-2 py-0.5 text-[9px] font-bold {statusClass(event.forward_status)}">{event.forward_status}</span></div>
                        <time class="text-[10px] text-faint">{formatDate(event.occurred_at)}</time>
                    </div>
                {/each}
            </div>
        {:else}
            <div class="px-5 py-12 text-center"><Activity class="mx-auto h-5 w-5 text-faint" /><p class="mt-2 text-xs font-semibold text-muted">No matching telemetry yet</p></div>
        {/if}
    </section>
</section>

{#if showForm}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showForm = false)}>
        <form class="modal max-w-4xl" on:submit|preventDefault={save}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><p class="eyebrow mb-1">Sentry relay</p><h2 class="text-lg font-bold">{editingDsn ? 'Edit routed DSN' : 'New routed DSN'}</h2><p class="mt-1 text-sm text-muted">Choose how an incoming envelope identifies its application target.</p></div>
                <button type="button" class="ghost p-2" aria-label="Close" on:click={() => showForm = false}>✕</button>
            </div>

            <div class="space-y-6 p-6">
                <label><span class="label">Name</span><input bind:value={form.name} placeholder="Production telemetry" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>

                <fieldset>
                    <legend class="label">Routing signal</legend>
                    <div class="grid gap-2 md:grid-cols-3">
                        {#each [
                            ['event', Braces, 'Event metadata', 'setContext, setExtra, tags, or attributes'],
                            ['query', Route, 'Query parameters', 'Values attached to the envelope request'],
                            ['header', Server, 'Request headers', 'Custom SDK transport headers'],
                        ] as method}
                            <label class="cursor-pointer rounded-md border p-3 transition {form.routing_method === method[0] ? 'border-brand-soft-line bg-brand-soft' : 'border-border bg-surface-raised hover:bg-surface-sunken'}">
                                <input class="sr-only" type="radio" bind:group={form.routing_method} value={method[0]} />
                                <span class="flex items-center gap-2 text-xs font-bold text-text"><svelte:component this={method[1]} class="h-3.5 w-3.5 text-brand" />{method[2]}</span>
                                <span class="mt-1 block text-[10px] leading-4 text-muted">{method[3]}</span>
                            </label>
                        {/each}
                    </div>
                    {#if form.errors.routing_method}<span class="error">{form.errors.routing_method}</span>{/if}
                </fieldset>

                {#if form.routing_method === 'event'}
                    <label>
                        <span class="label">Metadata source</span>
                        <select bind:value={form.event_source}>
                            <option value="auto">Auto-detect across contexts, extra, tags, attributes</option>
                            <option value="contexts">Contexts · setContext()</option>
                            <option value="extra">Extra · setExtra() / setExtras()</option>
                            <option value="tags">Tags · setTag() / setTags()</option>
                            <option value="attributes">Attributes · setAttribute() / setAttributes()</option>
                        </select>
                        {#if form.errors.event_source}<span class="error">{form.errors.event_source}</span>{/if}
                    </label>
                {/if}

                <div class="grid gap-5 md:grid-cols-2">
                    <label><span class="label">Application key</span><input bind:value={form.app_key} placeholder={form.routing_method === 'header' ? 'X-App-Slug' : form.routing_method === 'query' ? 'app_slug' : 'app.slug'} required /><span class="mt-1 block text-[10px] text-muted">Value must equal the App Manager application slug.</span>{#if form.errors.app_key}<span class="error">{form.errors.app_key}</span>{/if}</label>
                    <label><span class="label">Package, platform, or domain key <span class="font-normal text-faint">(optional)</span></span><input bind:value={form.identifier_key} placeholder={form.routing_method === 'header' ? 'X-App-Identifier' : form.routing_method === 'query' ? 'app_identifier' : 'app.identifier'} /><span class="mt-1 block text-[10px] text-muted">Matches package identifier, web domain, or platform value.</span>{#if form.errors.identifier_key}<span class="error">{form.errors.identifier_key}</span>{/if}</label>
                </div>

                <fieldset>
                    <legend class="label">Application targets</legend>
                    <div class="max-h-72 space-y-2 overflow-y-auto rounded-md border border-border bg-surface-sunken p-2">
                        {#each apps as app}
                            <div class="rounded-md border border-border-subtle bg-surface-raised p-3">
                                <div class="flex items-center justify-between gap-3"><div><p class="text-xs font-bold text-text">{app.name}</p><code class="text-[9px] text-faint">{app.slug}</code></div><label class="flex cursor-pointer items-center gap-2 text-[10px] font-semibold text-muted"><input class="h-4 w-4" type="checkbox" checked={hasTarget(app.id)} on:change={() => toggleTarget(app.id)} />Any target</label></div>
                                {#if app.identifiers.length}
                                    <div class="mt-3 grid gap-1.5 border-t border-border-subtle pt-3 sm:grid-cols-2">
                                        {#each app.identifiers as identifier}
                                            <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-[10px] hover:bg-surface-sunken"><input class="mt-0.5 h-3.5 w-3.5" type="checkbox" checked={hasTarget(app.id, identifier.id)} on:change={() => toggleTarget(app.id, identifier.id)} /><span class="min-w-0"><strong class="block truncate font-semibold text-text">{identifier.name}</strong><span class="block truncate font-mono text-faint">{identifier.identifier} · {identifier.platform}</span></span></label>
                                        {/each}
                                    </div>
                                {/if}
                            </div>
                        {/each}
                    </div>
                    {#if form.errors.routes}<span class="error">{form.errors.routes}</span>{/if}
                    {#if form.errors['routes.0.mobile_app_id']}<span class="error">{form.errors['routes.0.mobile_app_id']}</span>{/if}
                </fieldset>

                <fieldset class="rounded-md border border-border bg-surface-sunken p-4">
                    <label class="flex cursor-pointer items-start gap-3"><input class="mt-0.5 h-4 w-4" type="checkbox" bind:checked={form.forward_enabled} /><span><strong class="block text-xs font-bold text-text">Relay events to an upstream Sentry DSN</strong><span class="mt-1 block text-[10px] leading-4 text-muted">App Manager keeps its routed copy and asynchronously forwards the original envelope, including attachments.</span></span></label>
                    {#if form.forward_enabled}
                        <label class="mt-4 block"><span class="label">Upstream DSN</span><input type="password" autocomplete="off" bind:value={form.upstream_dsn} placeholder={editingDsn?.upstream_configured ? 'Leave blank to keep the current DSN' : 'https://public-key@o0.ingest.sentry.io/project-id'} /><span class="mt-1 block text-[10px] text-muted">Encrypted at rest and never displayed again.</span>{#if form.errors.upstream_dsn}<span class="error">{form.errors.upstream_dsn}</span>{/if}</label>
                    {/if}
                </fieldset>

                {#if editingDsn}<label class="flex items-center gap-2 text-xs font-semibold"><input class="h-4 w-4" type="checkbox" bind:checked={form.enabled} />Accept new events on this DSN</label>{/if}
            </div>

            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showForm = false}>Cancel</button><button disabled={form.processing || !form.routes.length}>{form.processing ? 'Saving…' : 'Save routed DSN'}</button></div>
        </form>
    </div>
{/if}
