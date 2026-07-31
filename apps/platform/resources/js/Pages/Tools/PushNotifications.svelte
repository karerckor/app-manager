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
    <svelte:fragment slot="breadcrumbs">
        <nav class="mb-4 flex items-center gap-2 text-[11px] font-medium text-slate-400">
            <span class="text-slate-400">Tools</span>
            <span class="text-slate-300">/</span>
            <span class="text-slate-600">Push Notifications</span>
        </nav>
    </svelte:fragment>

    <svelte:fragment slot="heading">
        <div class="flex items-center gap-2">
            <h1 class="truncate text-2xl font-extrabold tracking-tight text-slate-950">Push Notifications</h1>
            <span class="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-0.5 text-[10px] font-bold text-indigo-700 uppercase">
                Debug tool
            </span>
        </div>
        <p class="mt-1.5 text-xs text-slate-500 max-w-2xl">Send debug notifications through Expo Push Service or Firebase Cloud Messaging HTTP v1 and inspect each provider response.</p>
    </svelte:fragment>

        {#if !canSend}
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
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
                    <div class="border-b border-slate-100 px-5 py-3.5 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xs font-bold text-slate-900">Recipients</h2>
                            <p class="mt-0.5 text-[11px] text-slate-500">Add up to 100 device tokens. Paste a comma-, space-, or line-separated list.</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600">{recipients.length}/100</span>
                    </div>
                    <div class="p-5">
                        <div class="min-h-24 rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm transition focus-within:border-indigo-500 focus-within:ring-4 focus-within:ring-indigo-500/10">
                            <div class="flex flex-wrap gap-2">
                                {#each recipients as recipient, index (recipient)}
                                    <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-indigo-100 bg-indigo-50 py-1 pr-1 pl-2.5 text-xs font-medium text-indigo-800">
                                        <span class="max-w-[520px] truncate" title={recipient}>{recipient}</span>
                                        <button type="button" class="ghost grid h-6 w-6 shrink-0 place-items-center rounded-md p-0 text-indigo-500 hover:bg-indigo-100 hover:text-indigo-800" aria-label={`Remove ${recipient}`} on:click={() => removeRecipient(index)}>
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
                        <div class="border-b border-slate-100 px-5 py-3.5 flex items-center justify-between gap-4">
                            <div>
                                <h2 class="text-xs font-bold text-slate-900">Message payload</h2>
                                <p class="mt-0.5 text-[11px] text-slate-500">Fields match the Expo push notification tester.</p>
                            </div>
                            <a class="secondary gap-1.5 px-2.5 py-1.5 text-xs" href="https://expo.dev/notifications" target="_blank" rel="noreferrer">
                                Expo tester
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="md:col-span-2">
                                    <span class="label">Access token <span class="font-normal text-slate-400">· optional</span></span>
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
                                    <span class="label">Data <span class="font-normal text-slate-400">· JSON object</span></span>
                                    <textarea class="min-h-32 font-mono text-xs leading-5" spellcheck="false" bind:value={dataJson} disabled={!canSend}></textarea>
                                </label>
                                <label>
                                    <span class="label">TTL <span class="font-normal text-slate-400">· seconds</span></span>
                                    <input type="number" min="0" max="2419200" placeholder="Provider default" bind:value={ttl} disabled={!canSend} />
                                </label>
                                <label>
                                    <span class="label">Priority</span>
                                    <select bind:value={priority} disabled={!canSend}>
                                        <option value="default">Default</option>
                                        <option value="normal">Normal</option>
                                        <option value="high">High</option>
                                    </select>
                                </label>
                            </div>

                            <div class="mt-6 grid gap-6 border-t border-slate-100 pt-6 md:grid-cols-2 md:gap-x-8">
                                <div class="space-y-4">
                                    <div class="flex items-center gap-2 text-xs font-bold tracking-wider text-slate-400 uppercase">
                                        <Smartphone class="h-4 w-4 text-slate-400" />
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
                                    <div class="flex items-center gap-2 text-xs font-bold tracking-wider text-slate-400 uppercase">
                                        <Smartphone class="h-4 w-4 text-slate-400" />
                                        Android Options
                                    </div>
                                    <div class="space-y-4">
                                        <label>
                                            <span class="label">Channel ID</span>
                                            <input type="text" placeholder="default" bind:value={channelId} disabled={!canSend} />
                                        </label>
                                        <div class="mt-4 flex gap-2 rounded-xl bg-blue-50 p-3.5 text-xs leading-5 text-blue-800">
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
                        <div class="border-b border-slate-100 px-5 py-3.5">
                            <h2 class="text-xs font-bold text-slate-900">Firebase HTTP v1</h2>
                            <p class="mt-0.5 text-[11px] text-slate-500">Mints a short-lived OAuth token from the selected service account. The private key never reaches the browser.</p>
                        </div>

                        <div class="p-5 space-y-5">
                            <label>
                                <span class="label">FCM HTTP v1 credential</span>
                                <select bind:value={credentialId} disabled={!canSend || !readyCredentials.length}>
                                    <option value="" disabled>Select a service account</option>
                                    {#each firebaseCredentials as credential}
                                        <option value={credential.id} disabled={!credential.ready}>{credential.name}{credential.ready ? '' : ' · file missing'}</option>
                                    {/each}
                                </select>
                            </label>

                            {#if !firebaseCredentials.length}
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                                    <span>Add a Firebase HTTP v1 service-account JSON in Credentials Vault first.</span>
                                    <Link href={credentialsUrl} class="font-bold text-amber-900 underline underline-offset-2">Open vault</Link>
                                </div>
                            {:else if !readyCredentials.length}
                                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">Upload the service-account JSON file for an FCM credential before sending.</div>
                            {/if}

                            <div class="border-t border-slate-100 pt-5">
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-xs font-bold text-slate-900">JSON template</h3>
                                        <p class="mt-0.5 text-[11px] text-slate-500">The recipient placeholder is replaced with every token at send time.</p>
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
                        <span class="grid h-9 w-9 place-items-center rounded-lg {provider === 'expo' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-700'}">
                            {#if provider === 'expo'}<Send class="h-4 w-4" />{:else}<Flame class="h-4 w-4" />{/if}
                        </span>
                        <div>
                            <h3 class="text-xs font-bold text-slate-900">Ready to send</h3>
                            <p class="mt-0.5 text-[11px] text-slate-500">{provider === 'expo' ? 'Expo Push Service' : `Firebase · ${fcmPlatform === 'ios' ? 'iOS' : 'Android'}`}</p>
                        </div>
                    </div>

                    <dl class="mt-4 space-y-3 border-y border-slate-100 py-3.5 text-[11px]">
                        <div class="flex items-center justify-between gap-4"><dt class="text-slate-500 font-medium">Recipients</dt><dd class="font-bold text-slate-900">{recipients.length}</dd></div>
                        <div class="flex items-center justify-between gap-4"><dt class="text-slate-500 font-medium">Provider</dt><dd class="font-bold text-slate-900">{provider === 'expo' ? 'Expo' : 'FCM HTTP v1'}</dd></div>
                        {#if provider === 'firebase'}
                            <div class="flex items-center justify-between gap-4"><dt class="text-slate-500 font-medium">Template</dt><dd class="font-bold text-slate-900 uppercase">{fcmPlatform}</dd></div>
                        {/if}
                    </dl>

                    {#if error}
                        <div class="mt-4 flex gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-[11px] leading-5 text-rose-800">
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
                        <p class="mt-2 text-center text-[10px] text-slate-400">Add at least one recipient to continue.</p>
                    {/if}
                </section>

                {#if results}
                    <section class="card overflow-clip p-0">
                        <div class="border-b border-slate-100 px-5 py-3.5 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-xs font-bold text-slate-900">Provider response</h2>
                                <p class="mt-0.5 text-[11px] text-slate-500">{results.successful} of {results.sent} accepted</p>
                            </div>
                            <span class="grid h-8 w-8 place-items-center rounded-full {results.successful === results.sent ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-700'}">
                                {#if results.successful === results.sent}<CheckCircle2 class="h-4 w-4" />{:else}<CircleAlert class="h-4 w-4" />{/if}
                            </span>
                        </div>
                        <div class="p-5">
                            <div class="max-h-[480px] space-y-2 overflow-y-auto pr-1">
                                {#each results.results as result}
                                    <details class="rounded-xl border p-3 {result.status === 'ok' ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60'}">
                                        <summary class="cursor-pointer list-none">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2 w-2 shrink-0 rounded-full {result.status === 'ok' ? 'bg-emerald-500' : 'bg-rose-500'}"></span>
                                                <span class="min-w-0 flex-1 truncate text-xs font-semibold text-slate-900" title={result.recipient}>{result.recipient}</span>
                                                <span class="text-[10px] font-bold uppercase {result.status === 'ok' ? 'text-emerald-700' : 'text-rose-700'}">{result.status}</span>
                                            </div>
                                        </summary>
                                        {#if result.id}<p class="mt-2 break-all font-mono text-[10px] leading-4 text-slate-600">{result.id}</p>{/if}
                                        {#if result.message}<p class="mt-2 text-xs leading-5 text-rose-800">{result.message}</p>{/if}
                                        {#if result.details}<pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-2.5 text-[10px] leading-4 text-slate-200">{resultDetails(result)}</pre>{/if}
                                    </details>
                                {/each}
                            </div>
                        </div>
                    </section>
                {:else}
                    <section class="card border-dashed bg-slate-50/50 text-center py-10">
                        <Braces class="mx-auto h-6 w-6 text-slate-300" />
                        <h3 class="mt-3 text-sm font-semibold text-slate-600">Responses appear here</h3>
                        <p class="mt-1.5 text-xs text-slate-400 max-w-xs mx-auto">Each recipient gets an individual status and provider message for easier debugging.</p>
                    </section>
                {/if}
            </aside>
        </div>
</WorkspacePage>
