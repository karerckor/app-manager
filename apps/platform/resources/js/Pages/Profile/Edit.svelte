<script>
    import { onDestroy } from 'svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import {
        Check,
        CheckCircle2,
        Copy,
        Eye,
        EyeOff,
        KeyRound,
        LockKeyhole,
        RefreshCw,
        ShieldCheck,
        Smartphone,
        Trash2,
        TriangleAlert,
        UserRound,
        X,
    } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let twoFactor = {
        enabled: false,
        pending: false,
        passwordConfirmed: false,
        qrCode: null,
        setupKey: null,
    };
    export let ownedTeamsCount = 0;

    const profileForm = useForm({
        name: auth.user.name,
        email: auth.user.email,
    });
    const enableTwoFactorForm = useForm({});
    const confirmTwoFactorForm = useForm({ code: '' });
    const recoveryCodesForm = useForm({});
    const disableTwoFactorForm = useForm({});
    const deleteAccountForm = useForm({ password: '' });

    let showDeleteModal = false;
    let copied = false;
    let setupKeyCopied = false;
    let showRecoveryModal = false;
    let recoveryCodes = [];
    let recoveryCodesPassword = '';
    let recoveryCodesError = '';
    let recoveryCodesLoading = false;
    let recoveryCodesTimer;

    const statusMessages = {
        'profile-information-updated': 'Profile information saved.',
        'two-factor-authentication-enabled': 'Scan the QR code and enter a code to finish setup.',
        'two-factor-authentication-confirmed': 'Two-factor authentication is now active.',
        'two-factor-authentication-disabled': 'Two-factor authentication has been disabled.',
        'recovery-codes-generated': 'New recovery codes generated.',
    };

    $: statusMessage = statusMessages[flash?.status] ?? flash?.status;
    $: formattedSetupKey = twoFactor.setupKey?.match(/.{1,4}/g)?.join(' ') ?? '';

    const saveProfile = () => profileForm.put('/user/profile-information', {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
    });

    const startTwoFactorSetup = () => enableTwoFactorForm.post('/user/two-factor-authentication', {
        preserveScroll: true,
    });

    const confirmTwoFactorSetup = () => confirmTwoFactorForm.post('/user/confirmed-two-factor-authentication', {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        onSuccess: () => confirmTwoFactorForm.reset(),
    });

    const regenerateRecoveryCodes = () => recoveryCodesForm.post('/user/two-factor-recovery-codes', {
        preserveScroll: true,
        onSuccess: hideRecoveryCodes,
    });

    const disableTwoFactor = () => disableTwoFactorForm.delete('/user/two-factor-authentication', {
        preserveScroll: true,
    });

    const copyRecoveryCodes = async () => {
        await navigator.clipboard.writeText(recoveryCodes.join('\n'));
        copied = true;
        setTimeout(() => copied = false, 1800);
    };

    const hideRecoveryCodes = () => {
        clearTimeout(recoveryCodesTimer);
        recoveryCodes = [];
        copied = false;
    };

    const openRecoveryCodesModal = () => {
        recoveryCodesPassword = '';
        recoveryCodesError = '';
        showRecoveryModal = true;
    };

    const closeRecoveryCodesModal = () => {
        if (recoveryCodesLoading) return;

        showRecoveryModal = false;
        recoveryCodesPassword = '';
        recoveryCodesError = '';
    };

    const revealRecoveryCodes = async () => {
        recoveryCodesLoading = true;
        recoveryCodesError = '';

        try {
            const response = await fetch('/profile/recovery-codes', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ password: recoveryCodesPassword }),
            });
            const payload = await response.json();

            if (!response.ok) {
                recoveryCodesError = payload?.errors?.password?.[0] ?? 'Unable to show recovery codes. Please try again.';
                return;
            }

            recoveryCodes = payload.codes ?? [];
            showRecoveryModal = false;
            recoveryCodesPassword = '';
            clearTimeout(recoveryCodesTimer);
            recoveryCodesTimer = setTimeout(hideRecoveryCodes, 60_000);
        } catch {
            recoveryCodesError = 'Unable to show recovery codes. Please try again.';
        } finally {
            recoveryCodesLoading = false;
        }
    };

    const copySetupKey = async () => {
        await navigator.clipboard.writeText(twoFactor.setupKey);
        setupKeyCopied = true;
        setTimeout(() => setupKeyCopied = false, 1800);
    };

    const openDeleteModal = () => {
        deleteAccountForm.reset();
        deleteAccountForm.clearErrors();
        showDeleteModal = true;
    };

    const closeDeleteModal = () => {
        showDeleteModal = false;
        deleteAccountForm.reset();
        deleteAccountForm.clearErrors();
    };

    const deleteAccount = () => deleteAccountForm.delete('/profile', {
        errorBag: 'deleteAccount',
        onSuccess: closeDeleteModal,
        onFinish: () => deleteAccountForm.reset('password'),
    });

    onDestroy(hideRecoveryCodes);
</script>

<svelte:head><title>Profile settings · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="profile">
    <main class="mx-auto max-w-[1040px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <header class="mb-8">
            <p class="eyebrow mb-2">Personal account</p>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950">Profile settings</h1>
            <p class="mt-2 text-sm text-slate-500">Manage your personal details, sign-in security, and account.</p>
        </header>

        {#if statusMessage}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><Check class="h-3 w-3" strokeWidth={2.5} /></span>
                {statusMessage}
            </div>
        {/if}

        <div class="space-y-6">
            <section class="card overflow-hidden p-0">
                <div class="flex items-start gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><UserRound class="h-5 w-5" strokeWidth={1.9} /></span>
                    <div>
                        <h2 class="text-base font-bold text-slate-950">Personal information</h2>
                        <p class="mt-1 text-sm text-slate-500">Your name and email are used across every workspace you belong to.</p>
                    </div>
                </div>

                <form on:submit|preventDefault={saveProfile}>
                    <div class="grid gap-5 px-5 py-6 sm:grid-cols-2 sm:px-6">
                        <label>
                            <span class="label">Full name</span>
                            <input bind:value={profileForm.name} autocomplete="name" required />
                            {#if profileForm.errors.name}<span class="error">{profileForm.errors.name}</span>{/if}
                        </label>
                        <label>
                            <span class="label">Email address</span>
                            <input type="email" bind:value={profileForm.email} autocomplete="email" required />
                            {#if profileForm.errors.email}<span class="error">{profileForm.errors.email}</span>{/if}
                        </label>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-t border-slate-100 bg-slate-50/70 px-5 py-4 sm:px-6">
                        <p class="hidden text-xs text-slate-500 sm:block">Changes apply to all your workspaces.</p>
                        <button disabled={profileForm.processing || !profileForm.isDirty}>
                            {profileForm.processing ? 'Saving…' : 'Save changes'}
                        </button>
                    </div>
                </form>
            </section>

            <section class="card overflow-hidden p-0">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
                    <div class="flex items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600"><ShieldCheck class="h-5 w-5" strokeWidth={1.9} /></span>
                        <div>
                            <h2 class="text-base font-bold text-slate-950">Two-factor authentication</h2>
                            <p class="mt-1 max-w-xl text-sm text-slate-500">Add a verification code from your authenticator app when signing in.</p>
                        </div>
                    </div>
                    {#if twoFactor.enabled}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700"><CheckCircle2 class="h-3.5 w-3.5" /> Enabled</span>
                    {:else if twoFactor.pending}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700"><RefreshCw class="h-3.5 w-3.5" /> Setup pending</span>
                    {:else}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Not enabled</span>
                    {/if}
                </div>

                <div class="px-5 py-6 sm:px-6">
                    {#if twoFactor.pending && !twoFactor.passwordConfirmed}
                        <div class="flex flex-col gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <LockKeyhole class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600" strokeWidth={1.9} />
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">Confirm your password first</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">For your security, password confirmation is required before viewing or changing 2FA settings.</p>
                                </div>
                            </div>
                            <Link href="/user/confirm-password" class="secondary shrink-0">Confirm password</Link>
                        </div>
                    {:else if twoFactor.pending}
                        <div class="grid gap-7 lg:grid-cols-[220px_minmax(0,1fr)]">
                            <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                                <div class="mx-auto grid aspect-square place-items-center overflow-hidden rounded-xl bg-white [&>svg]:h-full [&>svg]:w-full">
                                    {@html twoFactor.qrCode}
                                </div>
                            </div>
                            <div>
                                <span class="mb-3 grid h-9 w-9 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><Smartphone class="h-4.5 w-4.5" /></span>
                                <h3 class="text-sm font-bold text-slate-950">Finish setup in two steps</h3>
                                <ol class="mt-3 space-y-2 text-sm leading-6 text-slate-600">
                                    <li><strong class="text-slate-800">1.</strong> Scan the QR code or enter the setup key manually in 1Password, Google Authenticator, Authy, or another TOTP app.</li>
                                    <li><strong class="text-slate-800">2.</strong> Enter the six-digit code generated by the app.</li>
                                </ol>
                                <div class="mt-5 max-w-lg">
                                    <p class="mb-2 text-xs font-semibold text-slate-700">Can’t scan the QR code?</p>
                                    <div class="flex items-stretch overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
                                        <code class="min-w-0 flex-1 break-all px-3.5 py-3 font-mono text-sm font-semibold tracking-wide text-slate-800" aria-label="Two-factor setup key">{formattedSetupKey}</code>
                                        <button type="button" class="ghost shrink-0 rounded-none border-l border-slate-200 px-3.5" on:click={copySetupKey} aria-label="Copy setup key">
                                            {#if setupKeyCopied}<Check class="h-4 w-4 text-emerald-600" />{:else}<Copy class="h-4 w-4" />{/if}
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">Choose “enter setup key” in your authenticator app. The key is copied without spaces.</p>
                                </div>
                                <form class="mt-5 flex max-w-md flex-col gap-3 sm:flex-row sm:items-start" on:submit|preventDefault={confirmTwoFactorSetup}>
                                    <label class="flex-1">
                                        <span class="sr-only">Authentication code</span>
                                        <input bind:value={confirmTwoFactorForm.code} inputmode="numeric" autocomplete="one-time-code" placeholder="000 000" maxlength="8" required />
                                        {#if confirmTwoFactorForm.errors.code}<span class="error">{confirmTwoFactorForm.errors.code}</span>{/if}
                                    </label>
                                    <button class="shrink-0" disabled={confirmTwoFactorForm.processing}>{confirmTwoFactorForm.processing ? 'Verifying…' : 'Verify and enable'}</button>
                                </form>
                            </div>
                        </div>
                    {:else if twoFactor.enabled}
                        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                            <div>
                                <h3 class="text-sm font-bold text-slate-950">Recovery codes</h3>
                                <p class="mt-1 max-w-xl text-sm leading-6 text-slate-500">Use a recovery code if you lose access to your authenticator. Codes stay hidden until you confirm your password.</p>
                                {#if recoveryCodes.length}
                                    <div class="mt-4 grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-4 font-mono text-xs text-slate-700 sm:grid-cols-2">
                                        {#each recoveryCodes as code}
                                            <code class="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">{code}</code>
                                        {/each}
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">These codes will be hidden automatically after 60 seconds.</p>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <button type="button" class="secondary gap-2" on:click={copyRecoveryCodes}>
                                            {#if copied}<Check class="h-4 w-4 text-emerald-600" /> Copied{:else}<Copy class="h-4 w-4" /> Copy codes{/if}
                                        </button>
                                        <button type="button" class="secondary gap-2" on:click={hideRecoveryCodes}>
                                            <EyeOff class="h-4 w-4" /> Hide codes
                                        </button>
                                        <button type="button" class="secondary gap-2" disabled={recoveryCodesForm.processing} on:click={regenerateRecoveryCodes}>
                                            <RefreshCw class="h-4 w-4" /> {recoveryCodesForm.processing ? 'Generating…' : 'Generate new codes'}
                                        </button>
                                    </div>
                                {:else}
                                    <div class="mt-4 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-start gap-3">
                                            <LockKeyhole class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                            <div><p class="text-sm font-semibold text-slate-800">Recovery codes are hidden</p><p class="mt-1 text-xs text-slate-500">Confirm your password to reveal them temporarily.</p></div>
                                        </div>
                                        <button type="button" class="secondary shrink-0 gap-2" on:click={openRecoveryCodesModal}><Eye class="h-4 w-4" /> Show recovery codes</button>
                                    </div>
                                {/if}
                            </div>
                            <button type="button" class="secondary text-rose-600 hover:border-rose-200 hover:bg-rose-50" disabled={disableTwoFactorForm.processing} on:click={disableTwoFactor}>
                                {disableTwoFactorForm.processing ? 'Disabling…' : 'Disable 2FA'}
                            </button>
                        </div>
                    {:else if !twoFactor.passwordConfirmed}
                        <div class="flex flex-col gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <LockKeyhole class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600" strokeWidth={1.9} />
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">Confirm your password first</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">For your security, password confirmation is required before setting up 2FA.</p>
                                </div>
                            </div>
                            <Link href="/user/confirm-password" class="secondary shrink-0">Confirm password</Link>
                        </div>
                    {:else}
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <KeyRound class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" strokeWidth={1.9} />
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">Protect your account with a second step</h3>
                                    <p class="mt-1 max-w-xl text-sm leading-6 text-slate-500">You will need your password and a time-based code to sign in.</p>
                                </div>
                            </div>
                            <button class="shrink-0" disabled={enableTwoFactorForm.processing} on:click={startTwoFactorSetup}>
                                {enableTwoFactorForm.processing ? 'Starting…' : 'Set up 2FA'}
                            </button>
                        </div>
                    {/if}
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-rose-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
                <div class="flex flex-col gap-5 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="flex items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-600"><Trash2 class="h-5 w-5" strokeWidth={1.9} /></span>
                        <div>
                            <h2 class="text-base font-bold text-slate-950">Delete account</h2>
                            <p class="mt-1 max-w-xl text-sm leading-6 text-slate-500">Permanently delete your profile{ownedTeamsCount ? ` and ${ownedTeamsCount} workspace${ownedTeamsCount === 1 ? '' : 's'} you own` : ''}. This cannot be undone.</p>
                        </div>
                    </div>
                    <button class="danger shrink-0" on:click={openDeleteModal}>Delete account</button>
                </div>
            </section>
        </div>
    </main>
</AppShell>

{#if showRecoveryModal}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeRecoveryCodesModal()}>
        <form class="modal" on:submit|preventDefault={revealRecoveryCodes}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><LockKeyhole class="h-5 w-5" /></span>
                    <div><h2 class="text-lg font-bold">Show recovery codes</h2><p class="mt-1 text-sm text-slate-500">Confirm your password to continue.</p></div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" disabled={recoveryCodesLoading} on:click={closeRecoveryCodesModal} aria-label="Close"><X class="h-4 w-4" /></button>
            </div>
            <div class="space-y-5 p-6">
                <p class="text-sm leading-6 text-slate-600">Recovery codes grant access to your account without your authenticator. They will be visible for 60 seconds.</p>
                <label>
                    <span class="label">Current password</span>
                    <input type="password" bind:value={recoveryCodesPassword} autocomplete="current-password" required />
                    {#if recoveryCodesError}<span class="error">{recoveryCodesError}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" disabled={recoveryCodesLoading} on:click={closeRecoveryCodesModal}>Cancel</button>
                <button disabled={recoveryCodesLoading}>{recoveryCodesLoading ? 'Checking…' : 'Show codes'}</button>
            </div>
        </form>
    </div>
{/if}

{#if showDeleteModal}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeDeleteModal()}>
        <form class="modal" on:submit|preventDefault={deleteAccount}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-600"><TriangleAlert class="h-5 w-5" /></span>
                    <div><h2 class="text-lg font-bold">Delete your account?</h2><p class="mt-1 text-sm text-slate-500">This action is permanent.</p></div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeDeleteModal} aria-label="Close"><X class="h-4 w-4" /></button>
            </div>
            <div class="space-y-5 p-6">
                <div class="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-800">
                    Your profile, API keys, and all workspaces you own will be deleted. You will also be removed from other workspaces.
                </div>
                <label>
                    <span class="label">Enter your password to confirm</span>
                    <input type="password" bind:value={deleteAccountForm.password} autocomplete="current-password" required />
                    {#if deleteAccountForm.errors.password}<span class="error">{deleteAccountForm.errors.password}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" on:click={closeDeleteModal}>Cancel</button>
                <button class="danger" disabled={deleteAccountForm.processing}>{deleteAccountForm.processing ? 'Deleting…' : 'Delete permanently'}</button>
            </div>
        </form>
    </div>
{/if}
