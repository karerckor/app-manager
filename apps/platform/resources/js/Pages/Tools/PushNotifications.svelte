<script>
    import { Link } from '@inertiajs/svelte';
    import {
        BellRing,
        Braces,
        Check,
        CheckCircle2,
        CircleAlert,
        Copy,
        ExternalLink,
        Flame,
        Info,
        LoaderCircle,
        Send,
        Smartphone,
        X,
    } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import TabSelector from '../../Components/TabSelector.svelte';
    import Select from '../../Components/Select.svelte';

    export let auth;
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let canSend = false;
    export let firebaseCredentials = [];
    export let sendUrl;
    export let credentialsUrl;

    const defaultIosTemplate = JSON.stringify({
        message: {
            token: '{{recipient}}',
            notification: {
                title: 'Test notification',
                body: 'Sent from App Manager',
            },
            data: {
                source: 'app-manager',
            },
            apns: {
                headers: {
                    'apns-priority': '10',
                },
                payload: {
                    aps: {
                        sound: 'default',
                        badge: 1,
                    },
                },
            },
        },
    }, null, 2);

    const defaultAndroidTemplate = JSON.stringify({
        message: {
            token: '{{recipient}}',
            notification: {
                title: 'Test notification',
                body: 'Sent from App Manager',
            },
            data: {
                source: 'app-manager',
            },
            android: {
                priority: 'high',
                notification: {
                    sound: 'default',
                    channel_id: 'default',
                },
            },
        },
    }, null, 2);

    let provider = 'expo';
    let recipients = [];
    let recipientInput = '';
    let accessToken = '';
    let title = 'Test notification';
    let body = 'Sent from App Manager';
    let dataJson = '{\n  "source": "app-manager"\n}';
    let ttl = '';
    let priority = 'default';
    let subtitle = '';
    let badge = '';
    let sound = 'default';
    let channelId = 'default';
    let credentialId = firebaseCredentials.find((credential) => credential.ready)?.id ?? '';
    let fcmPlatform = 'ios';
    let iosTemplate = defaultIosTemplate;
    let androidTemplate = defaultAndroidTemplate;
    let sending = false;
    let error = '';
    let results = null;
    let copied = false;

    $: readyCredentials = firebaseCredentials.filter((credential) => credential.ready);
    $: activeTemplate = fcmPlatform === 'ios' ? iosTemplate : androidTemplate;
    $: canSubmit = canSend
        && recipients.length > 0
        && !sending
        && (provider === 'expo' || Boolean(credentialId));

    const addRecipients = (value = recipientInput) => {
        const additions = value
            .split(/[\s,;]+/)
            .map((recipient) => recipient.trim())
            .filter(Boolean);

        if (additions.length) {
            recipients = [...new Set([...recipients, ...additions])].slice(0, 100);
        }

        recipientInput = '';
        error = '';
    };

    const recipientKeydown = (event) => {
        if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
            event.preventDefault();
            addRecipients();
            return;
        }

        if (event.key === 'Backspace' && !recipientInput && recipients.length) {
            recipients = recipients.slice(0, -1);
        }
    };

    const recipientPaste = (event) => {
        const value = event.clipboardData?.getData('text') ?? '';
        if (/[\s,;]/.test(value.trim())) {
            event.preventDefault();
            addRecipients(value);
        }
    };

    const removeRecipient = (index) => {
        recipients = recipients.filter((_, recipientIndex) => recipientIndex !== index);
    };

    const parsedObject = (value, label) => {
        let parsed;

        try {
            parsed = JSON.parse(value);
        } catch {
            throw new Error(`${label} must contain valid JSON.`);
        }

        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error(`${label} must be a JSON object.`);
        }

        return parsed;
    };

    const requestError = (payload) => {
        const validationMessage = Object.values(payload?.errors ?? {}).flat().find(Boolean);
        return validationMessage ?? payload?.message ?? 'The notification could not be sent.';
    };

    const sendNotification = async () => {
        if (recipientInput.trim()) addRecipients();
        if (!recipients.length) {
            error = 'Add at least one recipient token.';
            return;
        }

        error = '';
        results = null;
        sending = true;

        try {
            const request = {
                provider,
                recipients,
            };

            if (provider === 'expo') {
                request.expo = {
                    access_token: accessToken || null,
                    title: title || null,
                    body: body || null,
                    data: parsedObject(dataJson, 'Data'),
                    ttl: ttl === '' ? null : Number(ttl),
                    priority,
                    subtitle: subtitle || null,
                    badge: badge === '' ? null : Number(badge),
                    sound: sound || null,
                    channel_id: channelId || null,
                };
            } else {
                request.credential_id = credentialId;
                request.platform = fcmPlatform;
                request.payload = parsedObject(activeTemplate, `${fcmPlatform === 'ios' ? 'iOS' : 'Android'} template`);
            }

            const response = await fetch(sendUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(request),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(requestError(payload));
            }

            results = payload;
        } catch (exception) {
            error = exception?.message ?? 'The notification could not be sent.';
        } finally {
            sending = false;
        }
    };

    const copyTemplate = async () => {
        await navigator.clipboard.writeText(activeTemplate);
        copied = true;
        window.setTimeout(() => copied = false, 1600);
    };

    const resultDetails = (result) => JSON.stringify(result.details, null, 2);

    const selectProvider = (value) => {
        provider = value;
        error = '';
        results = null;
    };
</script>

<svelte:head><title>Push Notifications · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="push-notifications" title="Push Notifications">
    {#snippet breadcrumbs()}
        <nav class="mb-4 flex items-center gap-2 text-[11px] font-medium text-faint">
            <span class="text-faint">Tools</span>
            <span class="text-faint">/</span>
            <span class="text-muted">Push Notifications</span>
        </nav>
    {/snippet}

    {#snippet heading()}
        <div class="flex items-center gap-2">
            <h1 class="truncate text-2xl font-extrabold tracking-tight text-text">Push Notifications</h1>
            <span class="inline-flex items-center gap-2 rounded-full border border-brand-soft-line bg-brand-soft px-2.5 py-0.5 text-[10px] font-bold text-brand uppercase">
                Debug tool
            </span>
        </div>
        <p class="mt-1.5 text-xs text-muted max-w-2xl">Send debug notifications through Expo Push Service or Firebase Cloud Messaging HTTP v1 and inspect each provider response.</p>
    {/snippet}

        {#if !canSend}
            <div class="mb-6 flex items-start gap-3 rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3 text-sm text-warn-fill">
                <CircleAlert class="mt-0.5 h-4.5 w-4.5 shrink-0" />
                <div><strong class="font-semibold">Read-only access.</strong> Ask a workspace owner or admin for credential management permission to send test notifications.</div>
            </div>
        {/if}

        <div class="mb-5">
            <TabSelector
                variant="segmented"
                ariaLabel="Notification provider"
                value={provider}
                onChange={selectProvider}
                items={[
                    { value: 'expo', label: 'Expo Push Service', icon: Send },
                    { value: 'firebase', label: 'Firebase (FCM)', icon: Flame },
                ]}
            />
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(340px,0.8fr)] xl:items-start">
            <div class="space-y-6">
                <section class="card overflow-clip p-0">
                    <div class="border-b border-border-subtle px-5 py-3.5 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xs font-bold text-text">Recipients</h2>
                            <p class="mt-0.5 text-[11px] text-muted">Add up to 100 device tokens. Paste a comma-, space-, or line-separated list.</p>
                        </div>
                        <span class="rounded-full bg-border-subtle px-2.5 py-1 text-[11px] font-semibold text-muted">{recipients.length}/100</span>
                    </div>
                    <div class="p-5">
                        <div class="min-h-24 rounded-md border border-border bg-surface-raised p-2.5 shadow-sm transition focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10">
                            <div class="flex flex-wrap gap-2">
                                {#each recipients as recipient, index (recipient)}
                                    <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-brand-soft-line bg-brand-soft py-1 pr-1 pl-2.5 text-xs font-medium text-brand">
                                        <span class="max-w-[520px] truncate" title={recipient}>{recipient}</span>
                                        <button type="button" class="ghost grid h-6 w-6 shrink-0 place-items-center rounded-md p-0 text-brand hover:bg-brand-soft-line hover:text-brand" aria-label={`Remove ${recipient}`} on:click={() => removeRecipient(index)}>
                                            <X class="h-3.5 w-3.5" />
                                        </button>
                                    </span>
                                {/each}
                                <input
                                    id="recipient"
                                    class="min-w-[240px] flex-1 border-0 px-1.5 py-1.5 shadow-none focus:ring-0"
                                    placeholder={provider === 'expo' ? 'ExponentPushToken[…] then Enter' : 'FCM registration token then Enter'}
                                    bind:value={recipientInput}
                                    on:keydown={recipientKeydown}
                                    on:paste={recipientPaste}
                                    on:blur={() => recipientInput.trim() && addRecipients()}
                                    disabled={!canSend || recipients.length >= 100}
                                />
                            </div>
                        </div>
                    </div>
                </section>

                {#if provider === 'expo'}
                    <section class="card overflow-clip p-0">
                        <div class="border-b border-border-subtle px-5 py-3.5 flex items-center justify-between gap-4">
                            <div>
                                <h2 class="text-xs font-bold text-text">Message payload</h2>
                                <p class="mt-0.5 text-[11px] text-muted">Fields match the Expo push notification tester.</p>
                            </div>
                            <a class="secondary gap-1.5 px-2.5 py-1.5 text-xs" href="https://expo.dev/notifications" target="_blank" rel="noreferrer">
                                Expo tester
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="md:col-span-2">
                                    <span class="label">Access token <span class="font-normal text-faint">· optional</span></span>
                                    <input type="password" placeholder="Required only when Expo push security is enabled" bind:value={accessToken} disabled={!canSend} />
                                </label>
                                <label>
                                    <span class="label">Message title</span>
                                    <input type="text" placeholder="Notification title" bind:value={title} disabled={!canSend} />
                                </label>
                                <label>
                                    <span class="label">Message body</span>
                                    <input type="text" placeholder="Notification body" bind:value={body} disabled={!canSend} />
                                </label>
                                <label class="md:col-span-2">
                                    <span class="label">Data <span class="font-normal text-faint">· JSON object</span></span>
                                    <textarea class="min-h-32 font-mono text-xs leading-5" spellcheck="false" bind:value={dataJson} disabled={!canSend}></textarea>
                                </label>
                                <label>
                                    <span class="label">TTL <span class="font-normal text-faint">· seconds</span></span>
                                    <input type="number" min="0" max="2419200" placeholder="Provider default" bind:value={ttl} disabled={!canSend} />
                                </label>
                                <label>
                                    <span class="label">Priority</span>
                                    <Select bind:value={priority} options={[{ value: 'default', label: 'Default' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' }]} ariaLabel="Priority" disabled={!canSend} />
                                </label>
                            </div>

                            <div class="mt-6 grid gap-6 border-t border-border-subtle pt-6 md:grid-cols-2 md:gap-x-8">
                                <div class="space-y-4">
                                    <div class="flex items-center gap-2 text-xs font-bold tracking-wider text-faint uppercase">
                                        <Smartphone class="h-4 w-4 text-faint" />
                                        iOS Options
                                    </div>
                                    <div class="space-y-4">
                                        <label>
                                            <span class="label">Message subtitle</span>
                                            <input type="text" placeholder="Optional subtitle" bind:value={subtitle} disabled={!canSend} />
                                        </label>
                                        <label>
                                            <span class="label">Badge count</span>
                                            <input type="number" min="0" placeholder="No change" bind:value={badge} disabled={!canSend} />
                                        </label>
                                        <label>
                                            <span class="label">Sound name</span>
                                            <input type="text" placeholder="bells_sound.wav" bind:value={sound} disabled={!canSend} />
                                        </label>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex items-center gap-2 text-xs font-bold tracking-wider text-faint uppercase">
                                        <Smartphone class="h-4 w-4 text-faint" />
                                        Android Options
                                    </div>
                                    <div class="space-y-4">
                                        <label>
                                            <span class="label">Channel ID</span>
                                            <input type="text" placeholder="default" bind:value={channelId} disabled={!canSend} />
                                        </label>
                                        <div class="mt-4 flex gap-2 rounded-md bg-brand-soft p-3.5 text-xs leading-5 text-brand">
                                            <Info class="mt-0.5 h-4 w-4 shrink-0" />
                                            <span>The channel must already exist in the application or Android may not display the notification.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                {:else}
                    <section class="card overflow-clip p-0">
                        <div class="border-b border-border-subtle px-5 py-3.5">
                            <h2 class="text-xs font-bold text-text">Firebase HTTP v1</h2>
                            <p class="mt-0.5 text-[11px] text-muted">Mints a short-lived OAuth token from the selected service account. The private key never reaches the browser.</p>
                        </div>

                        <div class="p-5 space-y-5">
                            <label>
                                <span class="label">FCM HTTP v1 credential</span>
                                <Select bind:value={credentialId} options={[{ value: '', label: 'Select a service account', disabled: true }, ...firebaseCredentials.map((credential) => ({ value: credential.id, label: `${credential.name}${credential.ready ? '' : ' · file missing'}`, disabled: !credential.ready }))]} ariaLabel="FCM HTTP v1 credential" disabled={!canSend || !readyCredentials.length} />
                            </label>

                            {#if !firebaseCredentials.length}
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3 text-xs text-warn-fill">
                                    <span>Add a Firebase HTTP v1 service-account JSON in Credentials Vault first.</span>
                                    <Link href={credentialsUrl} class="font-bold text-warn-fill underline underline-offset-2">Open vault</Link>
                                </div>
                            {:else if !readyCredentials.length}
                                <div class="rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3 text-xs text-warn-fill">Upload the service-account JSON file for an FCM credential before sending.</div>
                            {/if}

                            <div class="border-t border-border-subtle pt-5">
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-xs font-bold text-text">JSON template</h3>
                                        <p class="mt-0.5 text-[11px] text-muted">The recipient placeholder is replaced with every token at send time.</p>
                                    </div>
                                    <button type="button" class="secondary gap-1.5 px-2.5 py-1.5 text-xs" on:click={copyTemplate}>
                                        {#if copied}<Check class="h-3.5 w-3.5" />Copied{:else}<Copy class="h-3.5 w-3.5" />Copy JSON{/if}
                                    </button>
                                </div>

                                <div class="mb-4">
                                    <TabSelector
                                        variant="segmented"
                                        ariaLabel="FCM template platform"
                                        value={fcmPlatform}
                                        onChange={(value) => fcmPlatform = value}
                                        items={[
                                            { value: 'ios', label: 'iOS template' },
                                            { value: 'android', label: 'Android template' },
                                        ]}
                                    />
                                </div>

                                {#if fcmPlatform === 'ios'}
                                    <textarea class="min-h-[430px] font-mono text-xs leading-5" spellcheck="false" bind:value={iosTemplate} disabled={!canSend}></textarea>
                                {:else}
                                    <textarea class="min-h-[430px] font-mono text-xs leading-5" spellcheck="false" bind:value={androidTemplate} disabled={!canSend}></textarea>
                                {/if}
                            </div>
                        </div>
                    </section>
                {/if}
            </div>

            <aside class="space-y-6 xl:sticky xl:top-6">
                <section class="card">
                    <div class="flex items-center gap-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg {provider === 'expo' ? 'bg-brand-soft text-brand' : 'bg-warn-text/10 text-warn-fill'}">
                            {#if provider === 'expo'}<Send class="h-4 w-4" />{:else}<Flame class="h-4 w-4" />{/if}
                        </span>
                        <div>
                            <h3 class="text-xs font-bold text-text">Ready to send</h3>
                            <p class="mt-0.5 text-[11px] text-muted">{provider === 'expo' ? 'Expo Push Service' : `Firebase · ${fcmPlatform === 'ios' ? 'iOS' : 'Android'}`}</p>
                        </div>
                    </div>

                    <dl class="mt-4 space-y-3 border-y border-border-subtle py-3.5 text-[11px]">
                        <div class="flex items-center justify-between gap-4"><dt class="text-muted font-medium">Recipients</dt><dd class="font-bold text-text">{recipients.length}</dd></div>
                        <div class="flex items-center justify-between gap-4"><dt class="text-muted font-medium">Provider</dt><dd class="font-bold text-text">{provider === 'expo' ? 'Expo' : 'FCM HTTP v1'}</dd></div>
                        {#if provider === 'firebase'}
                            <div class="flex items-center justify-between gap-4"><dt class="text-muted font-medium">Template</dt><dd class="font-bold text-text uppercase">{fcmPlatform}</dd></div>
                        {/if}
                    </dl>

                    {#if error}
                        <div class="mt-4 flex gap-2 rounded-md border border-danger bg-danger/10 p-3 text-[11px] leading-5 text-danger">
                            <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{error}</span>
                        </div>
                    {/if}

                    <button type="button" class="mt-4 flex w-full items-center justify-center gap-2" disabled={!canSubmit} on:click={sendNotification}>
                        {#if sending}
                            <LoaderCircle class="h-4 w-4 animate-spin" />
                            Sending…
                        {:else}
                            <Send class="h-4 w-4" />
                            Send notification
                        {/if}
                    </button>
                    {#if !recipients.length}
                        <p class="mt-2 text-center text-[10px] text-faint">Add at least one recipient to continue.</p>
                    {/if}
                </section>

                {#if results}
                    <section class="card overflow-clip p-0">
                        <div class="border-b border-border-subtle px-5 py-3.5 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-xs font-bold text-text">Provider response</h2>
                                <p class="mt-0.5 text-[11px] text-muted">{results.successful} of {results.sent} accepted</p>
                            </div>
                            <span class="grid h-8 w-8 place-items-center rounded-full {results.successful === results.sent ? 'bg-ok/10 text-ok' : 'bg-warn-text/10 text-warn-fill'}">
                                {#if results.successful === results.sent}<CheckCircle2 class="h-4 w-4" />{:else}<CircleAlert class="h-4 w-4" />{/if}
                            </span>
                        </div>
                        <div class="p-5">
                            <div class="max-h-[480px] space-y-2 overflow-y-auto pr-1">
                                {#each results.results as result}
                                    <details class="rounded-md border p-3 {result.status === 'ok' ? 'border-ok bg-ok/10' : 'border-danger bg-danger/10'}">
                                        <summary class="cursor-pointer list-none">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2 w-2 shrink-0 rounded-full {result.status === 'ok' ? 'bg-ok' : 'bg-danger'}"></span>
                                                <span class="min-w-0 flex-1 truncate text-xs font-semibold text-text" title={result.recipient}>{result.recipient}</span>
                                                <span class="text-[10px] font-bold uppercase {result.status === 'ok' ? 'text-ok' : 'text-danger'}">{result.status}</span>
                                            </div>
                                        </summary>
                                        {#if result.id}<p class="mt-2 break-all font-mono text-[10px] leading-4 text-muted">{result.id}</p>{/if}
                                        {#if result.message}<p class="mt-2 text-xs leading-5 text-danger">{result.message}</p>{/if}
                                        {#if result.details}<pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-text p-2.5 text-[10px] leading-4 text-faint">{resultDetails(result)}</pre>{/if}
                                    </details>
                                {/each}
                            </div>
                        </div>
                    </section>
                {:else}
                    <section class="card border-dashed bg-surface-sunken/50 text-center py-10">
                        <Braces class="mx-auto h-6 w-6 text-faint" />
                        <h3 class="mt-3 text-sm font-semibold text-muted">Responses appear here</h3>
                        <p class="mt-1.5 text-xs text-faint max-w-xs mx-auto">Each recipient gets an individual status and provider message for easier debugging.</p>
                    </section>
                {/if}
            </aside>
        </div>
</WorkspacePage>
