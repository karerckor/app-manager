<script>
    import { router, useForm } from '@inertiajs/svelte';
    import { BellRing, Check, ChevronRight, CircleDot, Plus, Send, ShieldCheck, Trash2, Variable } from '@lucide/svelte';
    import { tick } from 'svelte';
    import ConfirmDialog from '../../Components/ui/ConfirmDialog.svelte';

    export let enabled = false;
    export let storeUrl = '';
    export let providers = [];
    export let destinations = [];
    export let events = [];
    export let initialDestinationId = null;

    const templateDefaults = () => Object.fromEntries(
        events.map((event) => [event.key, event.default_template]),
    );
    const initialDestination = destinations.find((destination) => destination.id === initialDestinationId)
        ?? destinations[0]
        ?? null;
    const initialEventKey = events.find((event) => event.key === 'build.completed')?.key
        ?? events[0]?.key
        ?? '';
    const supportedProvider = (provider) => providers.some((item) => item.value === provider)
        ? provider
        : providers[0]?.value ?? '';
    const groupEvents = (items) => {
        const groups = [];

        items.forEach((event) => {
            let group = groups.find((item) => item.label === event.group);

            if (!group) {
                group = { label: event.group, events: [] };
                groups.push(group);
            }

            group.events.push(event);
        });

        return groups;
    };

    let selectedDestinationId = initialDestination?.id ?? null;
    let selectedEventKey = initialEventKey;
    let templateInput;
    let credentialConfigured = Boolean(initialDestination?.configured)
        && supportedProvider(initialDestination?.provider) === initialDestination?.provider;
    let testSending = false;
    let testError = '';
    let pendingRemoval = null;
    let removeProcessing = false;

    const notificationForm = useForm({
        name: initialDestination?.name ?? '',
        provider: supportedProvider(initialDestination?.provider),
        destination: initialDestination?.destination ?? '',
        webhook_url: '',
        bot_token: '',
        events: initialDestination?.events ?? ['build.completed', 'artifact.created'].filter((key) => events.some((event) => event.key === key)),
        templates: { ...templateDefaults(), ...(initialDestination?.templates ?? {}) },
        enabled: initialDestination?.status !== 'disabled',
    });

    $: selectedEvent = events.find((event) => event.key === selectedEventKey) ?? events[0];
    $: eventGroups = groupEvents(events);
    $: selectedDestination = destinations.find((destination) => destination.id === selectedDestinationId) ?? null;
    $: connectedProvider = selectedDestination?.configured ? selectedDestination.provider : null;
    $: selectedProvider = providers.find((provider) => provider.value === notificationForm.provider) ?? providers[0];
    $: credentialField = notificationForm.provider === 'telegram' ? 'bot_token' : 'webhook_url';
    $: destinationField = notificationForm.provider === 'telegram'
        ? {
            label: 'Chat ID or channel',
            placeholder: '-1001234567890 or @releases',
            help: 'Telegram uses this value when sending the message.',
        }
        : {
            label: 'Channel label',
            placeholder: '#mobile-releases',
            help: 'A recognizable label; the webhook itself determines the channel.',
        };

    const loadDestination = (destination) => {
        selectedDestinationId = destination.id;
        notificationForm.name = destination.name;
        notificationForm.provider = supportedProvider(destination.provider);
        notificationForm.destination = destination.destination;
        notificationForm.webhook_url = '';
        notificationForm.bot_token = '';
        notificationForm.events = [...destination.events];
        notificationForm.templates = { ...templateDefaults(), ...destination.templates };
        notificationForm.enabled = destination.status !== 'disabled';
        credentialConfigured = Boolean(destination.configured)
            && notificationForm.provider === destination.provider;
        testError = '';
        notificationForm.clearErrors();
    };

    const createDestination = () => {
        selectedDestinationId = null;
        selectedEventKey = initialEventKey;
        notificationForm.name = '';
        notificationForm.provider = providers[0]?.value ?? '';
        notificationForm.destination = '';
        notificationForm.webhook_url = '';
        notificationForm.bot_token = '';
        notificationForm.events = ['build.completed', 'artifact.created'].filter((key) => events.some((event) => event.key === key));
        notificationForm.templates = templateDefaults();
        notificationForm.enabled = true;
        credentialConfigured = false;
        testError = '';
        notificationForm.clearErrors();
    };

    const chooseProvider = (provider) => {
        if (notificationForm.provider === provider.value) return;

        const current = destinations.find((destination) => destination.id === selectedDestinationId);
        notificationForm.provider = provider.value;
        notificationForm.destination = current?.provider === provider.value
            ? current.destination
            : '';
        notificationForm.webhook_url = '';
        notificationForm.bot_token = '';
        credentialConfigured = Boolean(current?.configured) && current?.provider === provider.value;
        notificationForm.clearErrors('provider', 'destination', 'webhook_url', 'bot_token');
        testError = '';
    };

    const refreshSelection = (page, preferredId = selectedDestinationId) => {
        const nextDestinations = page?.props?.notificationDestinations ?? destinations;
        const destination = nextDestinations.find((item) => item.id === preferredId) ?? nextDestinations[0];

        if (destination) loadDestination(destination);
        else createDestination();
    };

    const saveDestination = () => {
        testError = '';
        const current = destinations.find((destination) => destination.id === selectedDestinationId);
        const preferredId = selectedDestinationId;
        let successfulPage = null;
        const options = {
            preserveScroll: true,
            onSuccess: (page) => successfulPage = page,
            onFinish: () => {
                if (successfulPage) refreshSelection(successfulPage, preferredId);
            },
        };

        if (current) notificationForm.patch(current.update_url, options);
        else notificationForm.post(storeUrl, options);
    };

    const testDestination = () => {
        const current = destinations.find((destination) => destination.id === selectedDestinationId);

        if (!current?.test_url || !selectedEvent || testSending) return;

        testError = '';
        router.post(current.test_url, {
            event: selectedEvent.key,
            template: notificationForm.templates[selectedEvent.key] ?? '',
        }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => testSending = true,
            onFinish: () => testSending = false,
            onError: (errors) => testError = errors.test
                ?? errors.event
                ?? errors.template
                ?? 'The example notification could not be sent.',
        });
    };

    const requestRemoveDestination = () => {
        const current = destinations.find((destination) => destination.id === selectedDestinationId);

        if (current) pendingRemoval = current;
    };

    const closeRemoveDialog = () => {
        if (!removeProcessing) pendingRemoval = null;
    };

    const removeDestination = () => {
        if (!pendingRemoval || removeProcessing) return;

        let successfulPage = null;

        router.delete(pendingRemoval.destroy_url, {
            preserveScroll: true,
            onStart: () => removeProcessing = true,
            onSuccess: (page) => successfulPage = page,
            onFinish: () => {
                removeProcessing = false;

                if (successfulPage) {
                    pendingRemoval = null;
                    refreshSelection(successfulPage, null);
                }
            },
        });
    };

    const eventEnabled = (eventKey, currentEvents) => currentEvents.includes(eventKey);
    const toggleEvent = (eventKey) => {
        notificationForm.events = eventEnabled(eventKey, notificationForm.events)
            ? notificationForm.events.filter((key) => key !== eventKey)
            : [...notificationForm.events, eventKey];
    };

    const groupToggleState = (items, currentEvents) => {
        const enabledCount = items.filter((event) => eventEnabled(event.key, currentEvents)).length;

        if (enabledCount === 0) return 'none';

        return enabledCount === items.length ? 'all' : 'some';
    };

    const toggleGroup = (items) => {
        const keys = items.map((event) => event.key);
        notificationForm.events = groupToggleState(items, notificationForm.events) === 'all'
            ? notificationForm.events.filter((key) => !keys.includes(key))
            : [...new Set([...notificationForm.events, ...keys])];
    };

    const updateTemplate = (value) => {
        notificationForm.templates = {
            ...notificationForm.templates,
            [selectedEvent.key]: value,
        };
    };

    const insertVariable = async (variableKey) => {
        const token = `{{${variableKey}}}`;
        const current = notificationForm.templates[selectedEvent.key] ?? '';
        const start = templateInput?.selectionStart ?? current.length;
        const end = templateInput?.selectionEnd ?? start;
        updateTemplate(`${current.slice(0, start)}${token}${current.slice(end)}`);
        await tick();
        templateInput?.focus();
        templateInput?.setSelectionRange(start + token.length, start + token.length);
    };

    const renderPreview = (event, templates) => {
        const examples = Object.fromEntries(event.variables.map((variable) => [variable.key, variable.example]));

        return (templates[event.key] ?? '')
            .replace(/{{\s*([a-zA-Z0-9_.]+)\s*}}/g, (token, key) => examples[key] ?? token);
    };

    const eventError = (eventKey) => notificationForm.errors[`templates.${eventKey}`];
</script>

<section class="space-y-4">
    <header class="card overflow-hidden p-0">
        <div class="grid gap-5 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div class="flex items-start gap-4">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-brand-soft-line bg-brand-soft text-brand">
                    <BellRing class="h-5 w-5" strokeWidth={1.8} />
                </span>
                <div>
                    <p class="eyebrow">Notification routing</p>
                    <h2 class="mt-1 font-condensed text-xl font-semibold text-text">Turn product events into messages your team can act on.</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted">Choose the moments that matter, write each message once, and route it to the destination your team already watches.</p>
                </div>
            </div>
            <div class="flex items-center gap-2 font-mono text-xs text-faint" aria-label="Notification setup flow">
                <span class="rounded-sm border border-border bg-surface-sunken px-2.5 py-1.5">event</span>
                <ChevronRight class="h-3.5 w-3.5" />
                <span class="rounded-sm border border-brand-soft-line bg-brand-soft px-2.5 py-1.5 text-brand">template</span>
                <ChevronRight class="h-3.5 w-3.5" />
                <span class="rounded-sm border border-border bg-surface-sunken px-2.5 py-1.5">destination</span>
            </div>
        </div>
    </header>

    {#if !enabled}
        <div class="card py-12 text-center">
            <BellRing class="mx-auto h-7 w-7 text-faint" strokeWidth={1.7} />
            <h2 class="mt-3 text-lg font-semibold text-text">Notifications are not enabled</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted">Enable notification integrations for this environment to configure destinations and message templates.</p>
        </div>
    {:else}
        <div class="card overflow-hidden p-0 xl:grid xl:grid-cols-[230px_minmax(0,1fr)]">
            <aside class="border-b border-border-subtle bg-surface-sunken xl:border-r xl:border-b-0">
                <div class="flex items-center justify-between gap-3 border-b border-border-subtle px-4 py-3.5">
                    <div>
                        <p class="text-sm font-semibold text-text">Destinations</p>
                        <p class="mt-0.5 font-mono text-xs text-faint">{destinations.length} configured</p>
                    </div>
                    <button type="button" class="icon-btn border border-border bg-surface-raised" aria-label="New notification destination" title="New destination" on:click={createDestination}>
                        <Plus class="h-4 w-4" />
                    </button>
                </div>

                <nav class="flex gap-2 overflow-x-auto p-2 xl:block xl:space-y-1" aria-label="Notification destinations">
                    {#each destinations as destination (destination.id)}
                        <button
                            type="button"
                            class="min-w-48 justify-start rounded-md border px-3 py-3 text-left shadow-none transition xl:w-full {selectedDestinationId === destination.id ? 'border-brand-soft-line bg-brand-soft text-text' : 'border-transparent bg-transparent text-muted hover:border-border hover:bg-surface-raised'}"
                            aria-pressed={selectedDestinationId === destination.id}
                            on:click={() => loadDestination(destination)}
                        >
                            <span class="flex min-w-0 items-center gap-2.5">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-sm border border-border bg-surface-raised font-mono text-[10px] font-bold text-muted">{providers.find((provider) => provider.value === destination.provider)?.mark ?? '—'}</span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5">
                                        <span class="block truncate text-sm font-semibold">{destination.name}</span>
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {destination.status === 'active' ? 'bg-ok' : 'bg-border-control'}"></span>
                                    </span>
                                    <span class="mt-0.5 block truncate font-mono text-xs text-faint">{destination.provider_name} · {destination.destination}</span>
                                </span>
                            </span>
                        </button>
                    {/each}

                    <button
                        type="button"
                        class="min-w-48 justify-start rounded-md border border-dashed px-3 py-3 text-left text-muted shadow-none transition xl:w-full {selectedDestinationId === null ? 'border-brand bg-brand-soft text-brand' : 'border-border bg-transparent hover:border-border-control hover:bg-surface-raised'}"
                        aria-pressed={selectedDestinationId === null}
                        on:click={createDestination}
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        <span class="text-sm font-semibold">New destination</span>
                    </button>
                </nav>
            </aside>

            <form on:submit|preventDefault={saveDestination}>
                <div class="border-b border-border-subtle px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="eyebrow">{selectedDestinationId ? 'Destination settings' : 'New destination'}</p>
                            <h2 class="mt-1 text-lg font-semibold text-text">{selectedDestinationId ? notificationForm.name || 'Untitled destination' : 'Configure where messages go'}</h2>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-medium {notificationForm.enabled ? 'text-ok' : 'text-muted'}">{notificationForm.enabled ? 'Active' : 'Paused'}</span>
                            <button
                                type="button"
                                role="switch"
                                aria-label="Enable notification destination"
                                aria-checked={notificationForm.enabled}
                                class="relative h-6 w-11 rounded-full p-0 shadow-none transition {notificationForm.enabled ? 'bg-ok hover:bg-ok' : 'bg-border-control hover:bg-border-control'}"
                                on:click={() => notificationForm.enabled = !notificationForm.enabled}
                            >
                                <span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow-sm transition-all {notificationForm.enabled ? 'left-6' : 'left-1'}"></span>
                            </button>
                        </div>
                    </div>

                    <fieldset class="mt-5">
                        <legend class="label">Provider</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            {#each providers as provider (provider.value)}
                                <button
                                    type="button"
                                    class="group flex-col items-stretch justify-start rounded-md border p-3 text-left shadow-none transition {notificationForm.provider === provider.value ? 'border-brand bg-brand-soft text-text' : 'border-border bg-surface-raised text-muted hover:border-border-control hover:bg-surface-sunken'}"
                                    aria-pressed={notificationForm.provider === provider.value}
                                    on:click={() => chooseProvider(provider)}
                                >
                                    <span class="flex items-start gap-2.5">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm border border-current/20 bg-surface-raised font-mono text-[11px] font-bold {notificationForm.provider === provider.value ? 'text-brand' : 'text-muted'}">{provider.mark}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center justify-between gap-2">
                                                <span class="block text-sm font-semibold">{provider.name}</span>
                                                {#if connectedProvider === provider.value}
                                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-ok/10 px-2 py-0.5 text-xs font-semibold text-ok">
                                                        <Check class="h-3 w-3" />Connected
                                                    </span>
                                                {/if}
                                            </span>
                                            <span class="mt-0.5 block text-xs font-normal leading-4 text-faint">{provider.description}</span>
                                        </span>
                                    </span>
                                </button>
                            {/each}
                        </div>
                        {#if notificationForm.errors.provider}<span class="error">{notificationForm.errors.provider}</span>{/if}
                    </fieldset>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <label>
                            <span class="label">Name</span>
                            <input bind:value={notificationForm.name} placeholder="Release room" required />
                            {#if notificationForm.errors.name}<span class="error">{notificationForm.errors.name}</span>{/if}
                        </label>
                        <label>
                            <span class="label">{destinationField.label}</span>
                            <input bind:value={notificationForm.destination} placeholder={destinationField.placeholder} required />
                            <span class="mt-1.5 block text-xs text-faint">{destinationField.help}</span>
                            {#if notificationForm.errors.destination}<span class="error">{notificationForm.errors.destination}</span>{/if}
                        </label>
                    </div>

                    <div class="mt-4 rounded-md border border-border bg-surface-sunken p-4">
                        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                            <label class="min-w-0 flex-1">
                                <span class="label">{notificationForm.provider === 'telegram' ? 'Bot token' : `${selectedProvider?.name ?? 'Provider'} webhook URL`}</span>
                                {#if notificationForm.provider === 'telegram'}
                                    <input type="password" bind:value={notificationForm.bot_token} autocomplete="off" placeholder={credentialConfigured ? 'Stored securely — enter a new token to replace it' : '123456789:AA…'} required={!credentialConfigured} />
                                {:else}
                                    <input type="password" bind:value={notificationForm.webhook_url} autocomplete="off" placeholder={credentialConfigured ? 'Stored securely — enter a new URL to replace it' : notificationForm.provider === 'discord' ? 'https://discord.com/api/webhooks/…' : 'https://hooks.slack.com/services/…'} required={!credentialConfigured} />
                                {/if}
                                <span class="mt-1.5 block text-xs text-faint">Encrypted at rest and never shown again. Leave blank to keep the current credential.</span>
                                {#if notificationForm.errors[credentialField]}<span class="error">{notificationForm.errors[credentialField]}</span>{/if}
                            </label>
                            <span class="inline-flex items-center gap-1.5 justify-self-start rounded-full px-2.5 py-1 text-xs font-semibold md:mt-8 {credentialConfigured ? 'bg-ok/10 text-ok' : 'bg-brand-soft text-brand'}">
                                <ShieldCheck class="h-3.5 w-3.5" />{credentialConfigured ? 'Connected' : 'Credential required'}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid min-h-[540px] lg:grid-cols-[250px_minmax(0,1fr)]">
                    <section class="border-b border-border-subtle lg:border-r lg:border-b-0" aria-labelledby="notification-events-heading">
                        <div class="border-b border-border-subtle px-4 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <h3 id="notification-events-heading" class="text-sm font-semibold text-text">Events</h3>
                                <span class="rounded-full bg-brand-soft px-2 py-0.5 font-mono text-xs text-brand">{notificationForm.events.length} on</span>
                            </div>
                            <p class="mt-1 text-xs text-faint">Toggle delivery, then select an event to edit its message.</p>
                        </div>

                        <div>
                            {#each eventGroups as group (group.label)}
                                {@const groupState = groupToggleState(group.events, notificationForm.events)}
                                <section class="border-b border-border-subtle last:border-b-0" aria-label={group.label}>
                                    <div class="flex items-center justify-between gap-3 border-b border-border-subtle bg-surface-sunken px-4 py-2">
                                        <h4 class="font-mono text-xs font-semibold uppercase leading-4 tracking-wider text-muted">{group.label}</h4>
                                        <div class="flex shrink-0 items-center gap-2.5">
                                            <span class="font-mono text-xs text-faint">
                                                {group.events.filter((event) => eventEnabled(event.key, notificationForm.events)).length}/{group.events.length}
                                            </span>
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-label={`${groupState === 'all' ? 'Disable' : 'Enable'} all ${group.label} notifications`}
                                                aria-checked={groupState === 'all'}
                                                class="relative h-5 w-9 shrink-0 rounded-full p-0 shadow-none {groupState !== 'none' ? 'bg-ok hover:bg-ok' : 'bg-border-control hover:bg-border-control'}"
                                                on:click={() => toggleGroup(group.events)}
                                            >
                                                <span class="absolute sr-only">{groupState === 'all' ? 'Enabled' : groupState === 'some' ? 'Partially enabled' : 'Disabled'}</span>
                                                <span class="absolute top-1 h-3 w-3 rounded-full bg-white transition-all {groupState === 'all' ? 'left-5' : groupState === 'some' ? 'left-3' : 'left-1'}"></span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-border-subtle">
                                        {#each group.events as event (event.key)}
                                            <div class="flex items-start gap-2 px-2 py-2 {selectedEventKey === event.key ? 'bg-brand-soft' : ''}">
                                                <button
                                                    type="button"
                                                    class="min-w-0 flex-1 flex-col items-stretch justify-start bg-transparent px-2 py-1.5 text-left text-text shadow-none hover:bg-surface-sunken"
                                                    aria-pressed={selectedEventKey === event.key}
                                                    on:click={() => selectedEventKey = event.key}
                                                >
                                                    <span class="block text-sm font-semibold leading-5">{event.label}</span>
                                                    <span class="mt-0.5 block truncate text-xs font-normal text-faint">{event.description}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-label={`${eventEnabled(event.key, notificationForm.events) ? 'Disable' : 'Enable'} ${event.label} notifications`}
                                                    aria-checked={eventEnabled(event.key, notificationForm.events)}
                                                    class="relative mt-1.5 h-5 w-9 shrink-0 rounded-full p-0 shadow-none {eventEnabled(event.key, notificationForm.events) ? 'bg-ok hover:bg-ok' : 'bg-border-control hover:bg-border-control'}"
                                                    on:click={() => toggleEvent(event.key)}
                                                >
                                                    <span class="absolute sr-only">{eventEnabled(event.key, notificationForm.events) ? 'Enabled' : 'Disabled'}</span>
                                                    <span class="absolute top-1 h-3 w-3 rounded-full bg-white transition-all {eventEnabled(event.key, notificationForm.events) ? 'left-5' : 'left-1'}"></span>
                                                </button>
                                            </div>
                                        {/each}
                                    </div>
                                </section>
                            {/each}
                        </div>
                        {#if notificationForm.errors.events}<span class="error px-4">{notificationForm.errors.events}</span>{/if}
                    </section>

                    {#if selectedEvent}
                        <section class="min-w-0 px-5 py-5" aria-labelledby="template-heading">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="grid h-7 w-7 place-items-center rounded-sm bg-brand-soft text-brand"><Variable class="h-3.5 w-3.5" /></span>
                                        <h3 id="template-heading" class="text-lg font-semibold text-text">{selectedEvent.label}</h3>
                                    </div>
                                    <p class="mt-1.5 text-sm text-muted">{selectedEvent.description}</p>
                                </div>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {eventEnabled(selectedEvent.key, notificationForm.events) ? 'bg-ok/10 text-ok' : 'bg-surface-sunken text-muted'}">
                                    {#if eventEnabled(selectedEvent.key, notificationForm.events)}<Check class="h-3.5 w-3.5" />{:else}<CircleDot class="h-3.5 w-3.5" />{/if}
                                    {eventEnabled(selectedEvent.key, notificationForm.events) ? 'Will send' : 'Not sending'}
                                </span>
                            </div>

                            <div class="mt-5 grid gap-5 2xl:grid-cols-[minmax(0,1.1fr)_minmax(280px,0.9fr)]">
                                <div>
                                    <label for="notification-template" class="label">Message template</label>
                                    <textarea
                                        id="notification-template"
                                        class="min-h-36 font-mono text-sm leading-6"
                                        bind:this={templateInput}
                                        value={notificationForm.templates[selectedEvent.key] ?? ''}
                                        maxlength={notificationForm.provider === 'discord' ? 2000 : 4000}
                                        on:input={(event) => updateTemplate(event.currentTarget.value)}
                                        required
                                    ></textarea>
                                    {#if eventError(selectedEvent.key)}<span class="error">{eventError(selectedEvent.key)}</span>{/if}
                                    <div class="mt-3 flex flex-wrap gap-1.5" aria-label={`Available values for ${selectedEvent.label}`}>
                                        {#each selectedEvent.variables as variable (variable.key)}
                                            <button
                                                type="button"
                                                class="chip border-border bg-surface-sunken font-mono text-xs text-text hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand"
                                                title={variable.description}
                                                on:click={() => insertVariable(variable.key)}
                                            >
                                                <Plus class="h-3 w-3" />{`{{${variable.key}}}`}
                                            </button>
                                        {/each}
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <span class="label mb-0">Rendered example</span>
                                        {#if selectedDestinationId}
                                            <button
                                                type="button"
                                                class="secondary min-h-0 px-2.5 py-1 text-xs"
                                                aria-label={`Send ${selectedEvent.label} example`}
                                                disabled={testSending || !credentialConfigured}
                                                on:click={testDestination}
                                            >
                                                <Send class="mr-1.5 h-3.5 w-3.5" />{testSending ? 'Sending…' : 'Send example'}
                                            </button>
                                        {/if}
                                    </div>
                                    <div class="min-h-36 rounded-md border border-border bg-surface-sunken p-4">
                                        <div class="flex items-center gap-2 border-b border-border-subtle pb-3">
                                            <span class="grid h-7 w-7 place-items-center rounded-sm bg-text text-xs font-bold text-white">AM</span>
                                            <div>
                                                <p class="text-sm font-semibold text-text">App Manager</p>
                                                <p class="font-mono text-xs text-faint">just now · {notificationForm.destination || 'your destination'}</p>
                                            </div>
                                        </div>
                                        <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-text">{renderPreview(selectedEvent, notificationForm.templates) || 'Your message preview will appear here.'}</p>
                                    </div>
                                    {#if testError}<span class="error mt-1">{testError}</span>{/if}
                                </div>
                            </div>

                            <div class="mt-6 border-t border-border-subtle pt-5">
                                <h4 class="text-sm font-semibold text-text">Values available for this notification</h4>
                                <p class="mt-1 text-xs text-faint">Values change with the selected event. Click one to insert it into the template.</p>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2 2xl:grid-cols-3">
                                    {#each selectedEvent.variables as variable (variable.key)}
                                        <button
                                            type="button"
                                            class="group justify-start rounded-md border border-border bg-surface-raised p-3 text-left text-text shadow-none hover:border-brand-soft-line hover:bg-brand-soft"
                                            on:click={() => insertVariable(variable.key)}
                                        >
                                            <span class="min-w-0">
                                                <span class="block truncate font-mono text-xs text-brand">{`{{${variable.key}}}`}</span>
                                                <span class="mt-1 block text-sm font-semibold">{variable.label}</span>
                                                <span class="mt-0.5 block text-xs font-normal leading-4 text-faint">{variable.description}</span>
                                                {#if variable.options?.length}
                                                    <span class="mt-2 flex flex-wrap gap-1.5" aria-label={`Possible values for ${variable.label}`}>
                                                        {#each variable.options as option (`${option.label}-${option.value}`)}
                                                            <span class="inline-flex items-center gap-1.5 rounded-sm border border-border bg-surface-sunken px-2 py-1 font-mono text-xs font-normal text-muted">
                                                                <span class="text-sm leading-none" aria-hidden="true">{option.value}</span>
                                                                <span>{option.label}</span>
                                                            </span>
                                                        {/each}
                                                    </span>
                                                {:else}
                                                    <span class="mt-2 block truncate font-mono text-xs font-normal text-muted">e.g. {variable.example}</span>
                                                {/if}
                                            </span>
                                        </button>
                                    {/each}
                                </div>
                            </div>
                        </section>
                    {/if}
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle bg-surface-sunken px-5 py-4">
                    <div>
                        <p class="text-sm font-medium text-text">{notificationForm.events.length} of {events.length} events enabled</p>
                        <p class="mt-0.5 text-xs text-faint">Templates are saved even when their event is paused.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        {#if selectedDestinationId}
                            <button type="button" class="ghost px-3 text-danger" on:click={requestRemoveDestination}>
                                <Trash2 class="mr-1.5 h-4 w-4" />Remove
                            </button>
                        {/if}
                        <button type="submit" disabled={notificationForm.processing}>
                            {notificationForm.processing ? 'Saving…' : selectedDestinationId ? 'Save changes' : 'Create destination'}
                        </button>
                    </div>
                </footer>
            </form>
        </div>
    {/if}
</section>

<ConfirmDialog
    open={Boolean(pendingRemoval)}
    title="Remove notification destination?"
    description={pendingRemoval ? `“${pendingRemoval.name}” will stop receiving notifications. Its delivery history will be preserved.` : ''}
    confirmLabel="Remove destination"
    busyLabel="Removing…"
    busy={removeProcessing}
    onConfirm={removeDestination}
    onCancel={closeRemoveDialog}
/>
