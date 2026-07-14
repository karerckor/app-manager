<script>
    import { router, useForm } from '@inertiajs/svelte';

    export let auth;
    export let flash = {};
    export let teams = [];
    export let tokens = [];
    export let twoFactorEnabled = false;
    export let twoFactorPending = false;
    export let twoFactorQrCode = null;
    export let recoveryCodes = [];

    const teamForm = useForm({ name: '' });
    const tokenForm = useForm({ name: '' });
    const confirmForm = useForm({ code: '' });

    const createTeam = () => teamForm.post('/teams', { onSuccess: () => teamForm.reset() });
    const createToken = () => tokenForm.post('/api-tokens', { onSuccess: () => tokenForm.reset() });
    const switchTeam = (event) => router.put(`/teams/${event.currentTarget.value}/switch`);
    const logout = () => router.post('/logout');
</script>

<svelte:head><title>Dashboard</title></svelte:head>
<header class="border-b border-slate-800 bg-slate-950/90">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <div><p class="font-bold tracking-wide text-violet-400">APP MANAGER</p><p class="muted">{auth.user.email}</p></div>
        <button class="secondary" on:click={logout}>Sign out</button>
    </div>
</header>

<main class="mx-auto max-w-6xl space-y-6 px-4 py-10">
    <div><h1 class="text-3xl font-bold">Hello, {auth.user.name}</h1><p class="muted mt-2">Manage access, teams and API credentials from one place.</p></div>

    {#if flash?.status}<div class="rounded-xl border border-emerald-800 bg-emerald-950/60 px-4 py-3 text-emerald-300">{flash.status}</div>{/if}
    {#if flash?.token}<div class="rounded-xl border border-amber-700 bg-amber-950/60 p-4"><p class="mb-2 font-semibold text-amber-300">New API key</p><code class="block overflow-x-auto rounded bg-black/30 p-3 text-sm">{flash.token}</code></div>{/if}

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card">
            <h2 class="text-xl font-semibold">Teams</h2><p class="muted mt-1 mb-5">Create a workspace or switch the active one.</p>
            <label class="block"><span class="label">Current team</span><select value={auth.user.current_team_id ?? ''} on:change={switchTeam}>{#each teams as team}<option value={team.id}>{team.name}{team.owner_id === auth.user.id ? ' — owner' : ''}</option>{/each}</select></label>
            <form on:submit|preventDefault={createTeam} class="mt-6 flex gap-3"><div class="grow"><input bind:value={teamForm.name} placeholder="New team name" required />{#if teamForm.errors.name}<span class="error">{teamForm.errors.name}</span>{/if}</div><button disabled={teamForm.processing}>Create</button></form>
        </section>

        <section class="card">
            <h2 class="text-xl font-semibold">API keys</h2><p class="muted mt-1 mb-5">Sanctum personal access tokens for API authentication.</p>
            <form on:submit|preventDefault={createToken} class="flex gap-3"><div class="grow"><input bind:value={tokenForm.name} placeholder="Token name" required />{#if tokenForm.errors.name}<span class="error">{tokenForm.errors.name}</span>{/if}</div><button disabled={tokenForm.processing}>Create</button></form>
            <ul class="mt-5 divide-y divide-slate-800">{#each tokens as token}<li class="flex items-center justify-between gap-3 py-3"><div><p class="font-medium">{token.name}</p><p class="muted">Created {new Date(token.created_at).toLocaleDateString()}</p></div><button class="danger px-3 py-1.5 text-sm" on:click={() => router.delete(`/api-tokens/${token.id}`)}>Revoke</button></li>{:else}<li class="muted py-3">No API keys yet.</li>{/each}</ul>
        </section>
    </div>

    <section class="card">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-semibold">Two-factor authentication</h2><p class="muted mt-1">Protect your account with a time-based one-time password.</p></div><span class="rounded-full px-3 py-1 text-sm {twoFactorEnabled ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-300'}">{twoFactorEnabled ? 'Enabled' : 'Disabled'}</span></div>

        {#if twoFactorPending}
            <div class="mt-6 grid gap-6 md:grid-cols-[auto_1fr]">
                <div class="rounded-xl bg-white p-3 text-black">{@html twoFactorQrCode ?? ''}</div>
                <div><p class="mb-3">Scan the QR code, then enter the six-digit code to confirm setup.</p><form on:submit|preventDefault={() => confirmForm.post('/user/confirmed-two-factor-authentication')} class="flex max-w-md gap-3"><div class="grow"><input bind:value={confirmForm.code} inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />{#if confirmForm.errors.code}<span class="error">{confirmForm.errors.code}</span>{/if}</div><button disabled={confirmForm.processing}>Confirm</button></form></div>
            </div>
        {:else if twoFactorEnabled}
            <div class="mt-6">{#if recoveryCodes.length}<p class="mb-3 font-medium">Recovery codes</p><div class="grid gap-2 rounded-xl bg-slate-950 p-4 font-mono text-sm sm:grid-cols-2">{#each recoveryCodes as code}<span>{code}</span>{/each}</div>{:else}<p class="muted">Confirm your password to view or regenerate recovery codes.</p>{/if}<div class="mt-4 flex flex-wrap gap-3"><button class="secondary" on:click={() => router.post('/user/two-factor-recovery-codes')}>Regenerate recovery codes</button><button class="danger" on:click={() => router.delete('/user/two-factor-authentication')}>Disable 2FA</button></div></div>
        {:else}
            <button class="mt-6" on:click={() => router.post('/user/two-factor-authentication')}>Enable 2FA</button>
        {/if}
    </section>
</main>
