<script>
    import { useForm } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import {
        ArrowLeft,
        ArrowRight,
        Check,
        CheckCircle2,
        CircleAlert,
        Cloud,
        FolderGit,
        GitBranch,
        HardDrive,
        KeyRound,
        LockKeyhole,
        ServerCog,
        ShieldCheck,
        Webhook,
    } from '@lucide/svelte';
    import InstallerField from './InstallerField.svelte';
    import InstallerSummary from './InstallerSummary.svelte';
    import InstallerUrlRow from './InstallerUrlRow.svelte';

    let { requirements = [], urls, defaults } = $props();
    let step = $state(0);
    let copied = $state('');
    const steps = ['System', 'Owner', 'Storage', 'Git providers', 'Webhooks'];
    const initialDefaults = untrack(() => defaults);
    const allRequirementsPassed = $derived(requirements.every((requirement) => requirement.passed));

    const form = useForm({
        instance_name: initialDefaults.instance_name,
        team_name: '',
        admin_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        storage: {
            driver: initialDefaults.storage_driver,
            s3: {
                access_key_id: '',
                secret_access_key: '',
                session_token: '',
                region: initialDefaults.storage_s3_region,
                bucket: initialDefaults.storage_s3_bucket,
                endpoint: initialDefaults.storage_s3_endpoint,
                prefix: initialDefaults.storage_s3_prefix,
                use_path_style_endpoint: initialDefaults.storage_s3_use_path_style_endpoint,
                object_acl: initialDefaults.storage_s3_object_acl,
                server_side_encryption: initialDefaults.storage_s3_server_side_encryption,
                kms_key_id: initialDefaults.storage_s3_kms_key_id,
            },
        },
        github: {
            enabled: false,
            app_id: '',
            client_id: '',
            client_secret: '',
            slug: '',
            private_key: '',
            webhook_secret: '',
            api_url: initialDefaults.github_api_url,
            web_url: initialDefaults.github_web_url,
        },
        gitlab: {
            enabled: false,
            client_id: '',
            client_secret: '',
            api_url: initialDefaults.gitlab_api_url,
            web_url: initialDefaults.gitlab_web_url,
        },
        webhooks: {
            enabled: true,
            allow_private_addresses: false,
        },
    });

    const error = (field) => form.errors[field];

    function ownerComplete() {
        return form.instance_name.trim()
            && form.team_name.trim()
            && form.admin_name.trim()
            && form.email.trim()
            && form.password.length >= 8
            && form.password === form.password_confirmation;
    }

    function providerComplete() {
        const githubComplete = !form.github.enabled
            || (form.github.app_id
                && form.github.client_id
                && form.github.client_secret
                && form.github.slug
                && form.github.private_key
                && form.github.webhook_secret
                && form.github.api_url
                && form.github.web_url);
        const gitlabComplete = !form.gitlab.enabled
            || (form.gitlab.client_id && form.gitlab.client_secret && form.gitlab.api_url && form.gitlab.web_url);

        return githubComplete && gitlabComplete;
    }

    function storageComplete() {
        if (form.storage.driver === 'local') return true;

        const hasKeyPair = (!form.storage.s3.access_key_id && !form.storage.s3.secret_access_key)
            || (form.storage.s3.access_key_id && form.storage.s3.secret_access_key);
        const hasKmsKey = form.storage.s3.server_side_encryption !== 'aws:kms'
            || form.storage.s3.kms_key_id;

        return form.storage.s3.region
            && form.storage.s3.bucket
            && hasKeyPair
            && hasKmsKey;
    }

    function next() {
        if (step < steps.length - 1) step += 1;
    }

    function previous() {
        if (step > 0) step -= 1;
    }

    async function copy(value, name) {
        await navigator.clipboard.writeText(value);
        copied = name;
        window.setTimeout(() => {
            if (copied === name) copied = '';
        }, 1600);
    }

    function submit() {
        form.post(urls.install, {
            preserveScroll: true,
            onError: (errors) => {
                if (Object.keys(errors).some((key) => ['instance_name', 'team_name', 'admin_name', 'email', 'password'].includes(key))) {
                    step = 1;
                } else if (Object.keys(errors).some((key) => key.startsWith('storage.'))) {
                    step = 2;
                } else if (Object.keys(errors).some((key) => key.startsWith('github.') || key.startsWith('gitlab.'))) {
                    step = 3;
                }
            },
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }
</script>

<svelte:head>
    <title>Install App Manager</title>
    <meta name="robots" content="noindex, nofollow" />
</svelte:head>

<main class="min-h-screen bg-[#f4f6fa] px-4 py-6 text-slate-950 sm:px-6 sm:py-10">
    <div class="mx-auto grid w-full min-w-0 max-w-6xl gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
        <aside class="min-w-0 overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-950/15 lg:min-h-[760px]">
            <div class="border-b border-white/10 p-6">
                <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-500">
                    <ServerCog size={23} strokeWidth={2.1} />
                </div>
                <p class="text-[11px] font-bold tracking-[0.18em] text-indigo-300 uppercase">Self-hosted setup</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight">App Manager</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">A private control plane for your mobile releases.</p>
            </div>

            <nav class="grid grid-cols-2 gap-2 p-4 sm:grid-cols-5 lg:grid-cols-1" aria-label="Installation progress">
                {#each steps as label, index}
                    <button
                        type="button"
                        class="flex items-center gap-3 rounded-2xl px-3 py-3 text-left shadow-none transition {index === step ? 'bg-white/10 text-white' : index < step ? 'bg-transparent text-slate-300 hover:bg-white/5' : 'cursor-default bg-transparent text-slate-600 hover:bg-transparent'}"
                        disabled={index > step}
                        onclick={() => index < step && (step = index)}
                    >
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {index < step ? 'bg-emerald-400 text-slate-950' : index === step ? 'bg-indigo-500 text-white' : 'bg-white/5 text-slate-500'}">
                            {#if index < step}<Check size={15} strokeWidth={3} />{:else}{index + 1}{/if}
                        </span>
                        <span class="text-xs font-semibold">{label}</span>
                    </button>
                {/each}
            </nav>

            <div class="mx-6 mt-4 hidden rounded-2xl border border-white/10 bg-white/[0.04] p-4 lg:block">
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-200">
                    <LockKeyhole size={15} class="text-indigo-300" />
                    One-time installer
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">After the owner is created, setup is locked and public registration is disabled.</p>
            </div>
        </aside>

        <section class="flex min-h-[680px] min-w-0 flex-col overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-900/[0.04]">
            <form class="flex min-h-full flex-1 flex-col" onsubmit={(event) => { event.preventDefault(); submit(); }}>
                <div class="flex-1 p-6 sm:p-9 lg:p-12">
                    {#if step === 0}
                        <div class="mx-auto max-w-2xl">
                            <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                                <ShieldCheck size={28} />
                            </div>
                            <p class="eyebrow text-indigo-500">Step 1 of 5</p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Let’s check your server</h2>
                            <p class="mt-3 max-w-xl text-sm leading-6 text-slate-500">The database must already be migrated. App Manager will then create the first owner, team, and encrypted operator configuration.</p>

                            <div class="mt-9 overflow-hidden rounded-2xl border border-slate-200">
                                {#each requirements as requirement}
                                    <div class="flex items-start gap-3 border-b border-slate-100 px-4 py-4 last:border-b-0 sm:px-5">
                                        {#if requirement.passed}
                                            <CheckCircle2 size={20} class="mt-0.5 shrink-0 text-emerald-500" />
                                        {:else}
                                            <CircleAlert size={20} class="mt-0.5 shrink-0 text-rose-500" />
                                        {/if}
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <p class="text-sm font-semibold">{requirement.label}</p>
                                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase {requirement.passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}">
                                                    {requirement.passed ? 'Ready' : 'Action needed'}
                                                </span>
                                            </div>
                                            <p class="mt-1 break-all text-xs leading-5 text-slate-500">{requirement.detail}</p>
                                        </div>
                                    </div>
                                {/each}
                            </div>

                            {#if !allRequirementsPassed}
                                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800">
                                    Fix the failed checks and reload this page before continuing.
                                </div>
                            {/if}
                        </div>
                    {:else if step === 1}
                        <div class="mx-auto max-w-2xl">
                            <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
                                <KeyRound size={27} />
                            </div>
                            <p class="eyebrow text-violet-500">Step 2 of 5</p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight">Create the first owner</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-500">This account owns the initial team and can invite everyone else.</p>

                            <div class="mt-9 grid gap-5 sm:grid-cols-2">
                                <label class="sm:col-span-2">
                                    <span class="label">Instance name</span>
                                    <input bind:value={form.instance_name} autocomplete="organization" required />
                                    <span class="mt-1.5 block text-xs text-slate-400">Shown in the browser title and system emails.</span>
                                    {#if error('instance_name')}<span class="error">{error('instance_name')}</span>{/if}
                                </label>
                                <label class="sm:col-span-2">
                                    <span class="label">Initial team</span>
                                    <input bind:value={form.team_name} placeholder="Acme Mobile" required />
                                    {#if error('team_name')}<span class="error">{error('team_name')}</span>{/if}
                                </label>
                                <label>
                                    <span class="label">Your name</span>
                                    <input bind:value={form.admin_name} autocomplete="name" required />
                                    {#if error('admin_name')}<span class="error">{error('admin_name')}</span>{/if}
                                </label>
                                <label>
                                    <span class="label">Work email</span>
                                    <input type="email" bind:value={form.email} autocomplete="email" required />
                                    {#if error('email')}<span class="error">{error('email')}</span>{/if}
                                </label>
                                <label>
                                    <span class="label">Password</span>
                                    <input type="password" bind:value={form.password} autocomplete="new-password" minlength="8" required />
                                    {#if error('password')}<span class="error">{error('password')}</span>{/if}
                                </label>
                                <label>
                                    <span class="label">Confirm password</span>
                                    <input type="password" bind:value={form.password_confirmation} autocomplete="new-password" minlength="8" required />
                                    {#if form.password_confirmation && form.password !== form.password_confirmation}<span class="error">Passwords do not match.</span>{/if}
                                </label>
                            </div>
                        </div>
                    {:else if step === 2}
                        <div class="mx-auto max-w-3xl">
                            <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                                {#if form.storage.driver === 's3'}<Cloud size={27} />{:else}<HardDrive size={27} />{/if}
                            </div>
                            <p class="eyebrow text-emerald-600">Step 3 of 5</p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight">Choose private file storage</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-500">Signing files, service-account keys, application assets, and build artifacts are stored on this backend. Downloads always pass through an authorized App Manager route.</p>

                            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition {form.storage.driver === 'local' ? 'border-emerald-500 bg-emerald-50/40 shadow-lg shadow-emerald-900/[0.05]' : 'border-slate-200'}">
                                    <input class="mt-1 h-4 w-4 shrink-0 accent-emerald-600" type="radio" value="local" bind:group={form.storage.driver} />
                                    <span>
                                        <strong class="flex items-center gap-2 text-sm"><HardDrive size={17} /> Local private disk</strong>
                                        <span class="mt-1.5 block text-xs leading-5 text-slate-500">Best for a single server and local development. Back up the application storage directory.</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition {form.storage.driver === 's3' ? 'border-sky-500 bg-sky-50/50 shadow-lg shadow-sky-900/[0.05]' : 'border-slate-200'}">
                                    <input class="mt-1 h-4 w-4 shrink-0 accent-sky-600" type="radio" value="s3" bind:group={form.storage.driver} />
                                    <span>
                                        <strong class="flex items-center gap-2 text-sm"><Cloud size={17} /> S3-compatible storage</strong>
                                        <span class="mt-1.5 block text-xs leading-5 text-slate-500">Amazon S3, DigitalOcean Spaces, MinIO, or another compatible private bucket.</span>
                                    </span>
                                </label>
                            </div>

                            {#if form.storage.driver === 's3'}
                                <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-5 sm:p-6">
                                    <div class="mb-5 rounded-xl border border-sky-100 bg-sky-50 p-4 text-xs leading-5 text-sky-900">
                                        Leave the endpoint empty for AWS S3. For Spaces use a regional endpoint such as <code>https://nyc3.digitaloceanspaces.com</code>. For MinIO provide its endpoint and enable path-style addressing.
                                    </div>

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <InstallerField label="Bucket" field="storage.s3.bucket" bind:value={form.storage.s3.bucket} {error} />
                                        <InstallerField label="Region" field="storage.s3.region" bind:value={form.storage.s3.region} placeholder="us-east-1" {error} />
                                        <InstallerField label="S3-compatible endpoint" field="storage.s3.endpoint" type="url" bind:value={form.storage.s3.endpoint} placeholder="https://s3.example.com" wrapperClass="sm:col-span-2" required={false} {error} />
                                        <InstallerField label="Bucket prefix" field="storage.s3.prefix" bind:value={form.storage.s3.prefix} placeholder="app-manager/production" wrapperClass="sm:col-span-2" required={false} {error} />
                                        <InstallerField label="Access key ID" field="storage.s3.access_key_id" bind:value={form.storage.s3.access_key_id} required={false} {error} />
                                        <InstallerField label="Secret access key" field="storage.s3.secret_access_key" type="password" bind:value={form.storage.s3.secret_access_key} required={false} {error} />
                                        <InstallerField label="Session token (optional)" field="storage.s3.session_token" type="password" bind:value={form.storage.s3.session_token} wrapperClass="sm:col-span-2" required={false} {error} />

                                        <label>
                                            <span class="label">Object ownership</span>
                                            <select bind:value={form.storage.s3.object_acl}>
                                                <option value="bucket-owner-full-control">AWS · bucket owner enforced</option>
                                                <option value="private">S3-compatible · private ACL</option>
                                            </select>
                                            {#if error('storage.s3.object_acl')}<span class="error">{error('storage.s3.object_acl')}</span>{/if}
                                        </label>
                                        <label>
                                            <span class="label">Server-side encryption</span>
                                            <select bind:value={form.storage.s3.server_side_encryption}>
                                                <option value="">Provider or bucket default</option>
                                                <option value="AES256">SSE-S3 / AES-256</option>
                                                <option value="aws:kms">SSE-KMS</option>
                                            </select>
                                            {#if error('storage.s3.server_side_encryption')}<span class="error">{error('storage.s3.server_side_encryption')}</span>{/if}
                                        </label>
                                        {#if form.storage.s3.server_side_encryption === 'aws:kms'}
                                            <InstallerField label="KMS key ID or ARN" field="storage.s3.kms_key_id" bind:value={form.storage.s3.kms_key_id} {error} />
                                        {/if}

                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:col-span-2">
                                            <input class="mt-0.5 h-4 w-4 shrink-0 accent-sky-600" type="checkbox" bind:checked={form.storage.s3.use_path_style_endpoint} />
                                            <span>
                                                <strong class="block text-xs">Use path-style endpoint</strong>
                                                <span class="mt-1 block text-[11px] leading-5 text-slate-500">Usually required by MinIO. Leave disabled for AWS S3 and DigitalOcean Spaces.</span>
                                            </span>
                                        </label>
                                    </div>

                                    <p class="mt-5 text-[11px] leading-5 text-slate-500">Use “bucket owner enforced” for modern AWS buckets and “private ACL” for Spaces or MinIO. Access keys may be left empty when the server receives temporary credentials from an IAM role. The installer verifies write, read, and delete access before creating the owner.</p>
                                    {#if error('storage.driver')}<span class="error mt-3 block">{error('storage.driver')}</span>{/if}
                                </div>
                            {:else}
                                <div class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 text-xs leading-5 text-emerald-900">
                                    Files are written outside the public web root with private filesystem permissions. The installer verifies that the directory is writable.
                                    {#if error('storage.driver')}<span class="error mt-3 block">{error('storage.driver')}</span>{/if}
                                </div>
                            {/if}
                        </div>
                    {:else if step === 3}
                        <div class="mx-auto max-w-3xl">
                            <p class="eyebrow text-indigo-500">Step 4 of 5</p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight">Connect your Git providers</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-500">Add one or both now, or skip them and finish configuration later. Secrets are encrypted before they are stored.</p>

                            <div class="mt-8 space-y-4">
                                <article class="overflow-hidden rounded-2xl border transition {form.github.enabled ? 'border-slate-950 shadow-lg shadow-slate-900/[0.06]' : 'border-slate-200'}">
                                    <label class="flex cursor-pointer items-center gap-4 p-5">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-950 text-white"><FolderGit size={23} /></span>
                                        <span class="min-w-0 flex-1">
                                            <strong class="block text-sm">GitHub Actions</strong>
                                            <span class="mt-1 block text-xs text-slate-500">GitHub App with Actions read/write access</span>
                                        </span>
                                        <input class="peer sr-only" type="checkbox" bind:checked={form.github.enabled} />
                                        <span class="relative h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-indigo-600 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition peer-checked:after:translate-x-5"></span>
                                    </label>
                                    {#if form.github.enabled}
                                        <div class="space-y-5 border-t border-slate-100 bg-slate-50/70 p-5 sm:p-6">
                                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-4">
                                                <p class="text-xs font-semibold text-indigo-950">Use these URLs in your GitHub App</p>
                                                <InstallerUrlRow label="Callback URL" value={urls.github_callback} name="github-callback" {copied} onCopy={copy} />
                                                <InstallerUrlRow label="Webhook URL" value={urls.github_webhook} name="github-webhook" {copied} onCopy={copy} />
                                            </div>
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <InstallerField label="App ID" field="github.app_id" bind:value={form.github.app_id} {error} />
                                                <InstallerField label="App slug" field="github.slug" bind:value={form.github.slug} placeholder="acme-app-manager" {error} />
                                                <InstallerField label="Client ID" field="github.client_id" bind:value={form.github.client_id} {error} />
                                                <InstallerField label="Client secret" field="github.client_secret" type="password" bind:value={form.github.client_secret} {error} />
                                                <InstallerField label="GitHub web URL" field="github.web_url" type="url" bind:value={form.github.web_url} {error} />
                                                <InstallerField label="GitHub API URL" field="github.api_url" type="url" bind:value={form.github.api_url} {error} />
                                                <InstallerField label="Webhook secret" field="github.webhook_secret" type="password" bind:value={form.github.webhook_secret} wrapperClass="sm:col-span-2" {error} />
                                                <label class="sm:col-span-2">
                                                    <span class="label">Private key (PEM)</span>
                                                    <textarea class="min-h-36 font-mono text-xs" bind:value={form.github.private_key} placeholder="-----BEGIN RSA PRIVATE KEY-----" required></textarea>
                                                    {#if error('github.private_key')}<span class="error">{error('github.private_key')}</span>{/if}
                                                </label>
                                            </div>
                                        </div>
                                    {/if}
                                </article>

                                <article class="overflow-hidden rounded-2xl border transition {form.gitlab.enabled ? 'border-orange-400 shadow-lg shadow-orange-900/[0.06]' : 'border-slate-200'}">
                                    <label class="flex cursor-pointer items-center gap-4 p-5">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600"><GitBranch size={24} /></span>
                                        <span class="min-w-0 flex-1">
                                            <strong class="block text-sm">GitLab CI/CD</strong>
                                            <span class="mt-1 block text-xs text-slate-500">OAuth application for GitLab.com or self-managed</span>
                                        </span>
                                        <input class="peer sr-only" type="checkbox" bind:checked={form.gitlab.enabled} />
                                        <span class="relative h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-orange-500 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition peer-checked:after:translate-x-5"></span>
                                    </label>
                                    {#if form.gitlab.enabled}
                                        <div class="space-y-5 border-t border-slate-100 bg-orange-50/30 p-5 sm:p-6">
                                            <div class="rounded-xl border border-orange-100 bg-orange-50 p-4">
                                                <p class="text-xs font-semibold text-orange-950">OAuth redirect URI</p>
                                                <InstallerUrlRow label="Redirect URI" value={urls.gitlab_callback} name="gitlab-callback" {copied} onCopy={copy} />
                                                <p class="mt-3 text-[11px] leading-5 text-orange-800">Select the <strong>api</strong> and <strong>openid</strong> scopes in GitLab.</p>
                                            </div>
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <InstallerField label="Application ID" field="gitlab.client_id" bind:value={form.gitlab.client_id} {error} />
                                                <InstallerField label="Secret" field="gitlab.client_secret" type="password" bind:value={form.gitlab.client_secret} {error} />
                                                <InstallerField label="GitLab web URL" field="gitlab.web_url" type="url" bind:value={form.gitlab.web_url} {error} />
                                                <InstallerField label="GitLab API URL" field="gitlab.api_url" type="url" bind:value={form.gitlab.api_url} {error} />
                                            </div>
                                        </div>
                                    {/if}
                                </article>
                            </div>
                        </div>
                    {:else}
                        <div class="mx-auto max-w-2xl">
                            <div class="mb-7 flex h-14 w-14 items-center justify-center rounded-2xl bg-cyan-50 text-cyan-700">
                                <Webhook size={27} />
                            </div>
                            <p class="eyebrow text-cyan-600">Step 5 of 5</p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight">Webhooks and finish</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-500">Outbound webhooks sign every payload with HMAC-SHA256 and retry transient failures. Endpoint URLs and subscriptions are added from Integrations after setup.</p>

                            <div class="mt-8 space-y-4">
                                <label class="flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition {form.webhooks.enabled ? 'border-cyan-400 bg-cyan-50/40' : 'border-slate-200'}">
                                    <input class="mt-1 h-4 w-4 shrink-0 accent-cyan-600" type="checkbox" bind:checked={form.webhooks.enabled} />
                                    <span>
                                        <strong class="block text-sm">Enable outbound webhooks</strong>
                                        <span class="mt-1 block text-xs leading-5 text-slate-500">Send build, artifact, credential, app, and team events to your services.</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-4 rounded-2xl border border-slate-200 p-5 {form.webhooks.enabled ? '' : 'pointer-events-none opacity-50'}">
                                    <input class="mt-1 h-4 w-4 shrink-0 accent-cyan-600" type="checkbox" bind:checked={form.webhooks.allow_private_addresses} disabled={!form.webhooks.enabled} />
                                    <span>
                                        <strong class="block text-sm">Allow private-network targets</strong>
                                        <span class="mt-1 block text-xs leading-5 text-slate-500">Only enable this when internal RFC1918 endpoints are intentional. Public HTTPS targets are safer.</span>
                                    </span>
                                </label>
                            </div>

                            <div class="mt-8 rounded-2xl bg-slate-950 p-5 text-white sm:p-6">
                                <p class="text-xs font-bold tracking-[0.14em] text-slate-500 uppercase">Ready to install</p>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <InstallerSummary label="Instance" value={form.instance_name || '—'} />
                                    <InstallerSummary label="Owner" value={form.email || '—'} />
                                    <InstallerSummary label="Private storage" value={form.storage.driver === 's3' ? `S3 · ${form.storage.s3.bucket || '—'}` : 'Local disk'} />
                                    <InstallerSummary label="Git providers" value={[form.github.enabled && 'GitHub', form.gitlab.enabled && 'GitLab'].filter(Boolean).join(' + ') || 'Configure later'} />
                                    <InstallerSummary label="Outbound webhooks" value={form.webhooks.enabled ? 'Enabled' : 'Disabled'} />
                                </div>
                                <div class="mt-5 flex items-start gap-2 border-t border-white/10 pt-4 text-xs leading-5 text-slate-400">
                                    <LockKeyhole size={15} class="mt-0.5 shrink-0 text-emerald-400" />
                                    Provider secrets are encrypted with APP_KEY. They are never returned to the browser after installation.
                                </div>
                            </div>
                        </div>
                    {/if}
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/80 px-6 py-5 sm:px-9">
                    <div>
                        {#if step > 0}
                            <button type="button" class="secondary gap-2" onclick={previous}>
                                <ArrowLeft size={16} /> Back
                            </button>
                        {:else}
                            <p class="text-xs text-slate-400">No telemetry is sent during setup.</p>
                        {/if}
                    </div>

                    {#if step < steps.length - 1}
                        <button
                            type="button"
                            class="inline-flex items-center gap-2"
                            disabled={(step === 0 && !allRequirementsPassed) || (step === 1 && !ownerComplete()) || (step === 2 && !storageComplete()) || (step === 3 && !providerComplete())}
                            onclick={next}
                        >
                            Continue <ArrowRight size={16} />
                        </button>
                    {:else}
                        <button type="submit" class="inline-flex min-w-36 items-center justify-center gap-2 bg-slate-950 hover:bg-slate-800" disabled={form.processing}>
                            {#if form.processing}
                                Installing…
                            {:else}
                                <CheckCircle2 size={17} /> Install App Manager
                            {/if}
                        </button>
                    {/if}
                </footer>
            </form>
        </section>
    </div>
</main>
