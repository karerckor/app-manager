<script>
    import { useForm } from '@inertiajs/svelte';
    let recovery = false;
    const form = useForm({ code: '', recovery_code: '' });
</script>

<svelte:head><title>Two-factor challenge</title></svelte:head>
<main class="grid min-h-screen place-items-center bg-surface-sunken px-4">
    <section class="card w-full max-w-md">
        <h1 class="font-condensed text-3xl font-semibold text-text uppercase">Two-factor authentication</h1>
        <p class="muted mt-2 mb-8">Enter {recovery ? 'one of your recovery codes' : 'the code from your authenticator app'}.</p>
        <form on:submit|preventDefault={() => form.post('/two-factor-challenge')} class="space-y-5">
            {#if recovery}<label class="block"><span class="label">Recovery code</span><input bind:value={form.recovery_code} autocomplete="one-time-code" /></label>{:else}<label class="block"><span class="label">Authentication code</span><input bind:value={form.code} inputmode="numeric" autocomplete="one-time-code" /></label>{/if}
            {#if form.errors.code}<span class="error">{form.errors.code}</span>{/if}{#if form.errors.recovery_code}<span class="error">{form.errors.recovery_code}</span>{/if}
            <button class="w-full" disabled={form.processing}>Continue</button>
        </form>
        <button class="mt-4 w-full secondary" on:click={() => { recovery = !recovery; form.clearErrors(); }}>{recovery ? 'Use authentication code' : 'Use recovery code'}</button>
    </section>
</main>
