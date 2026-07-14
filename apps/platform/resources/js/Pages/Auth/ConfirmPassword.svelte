<script>
    import { useForm } from '@inertiajs/svelte';
    const form = useForm({ password: '' });
</script>

<svelte:head><title>Confirm password</title></svelte:head>
<main class="grid min-h-screen place-items-center px-4"><section class="card w-full max-w-md">
    <h1 class="text-3xl font-bold">Confirm your password</h1><p class="muted mt-2 mb-8">This is a protected action. Enter your password to continue.</p>
    <form on:submit|preventDefault={() => form.post('/user/confirm-password', { onFinish: () => form.reset() })} class="space-y-5">
        <label class="block"><span class="label">Password</span><input type="password" bind:value={form.password} autocomplete="current-password" required />{#if form.errors.password}<span class="error">{form.errors.password}</span>{/if}</label>
        <button class="w-full" disabled={form.processing}>Confirm</button>
    </form>
</section></main>
