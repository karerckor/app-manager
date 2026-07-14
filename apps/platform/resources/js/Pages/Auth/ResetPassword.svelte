<script>
    import { useForm } from '@inertiajs/svelte';
    export let email; export let token;
    const form = useForm({ token, email, password: '', password_confirmation: '' });
</script>

<svelte:head><title>Choose password</title></svelte:head>
<main class="grid min-h-screen place-items-center px-4"><section class="card w-full max-w-md">
    <h1 class="mb-8 text-3xl font-bold">Choose a new password</h1>
    <form on:submit|preventDefault={() => form.post('/reset-password')} class="space-y-5">
        <label class="block"><span class="label">Email</span><input type="email" bind:value={form.email} required />{#if form.errors.email}<span class="error">{form.errors.email}</span>{/if}</label>
        <label class="block"><span class="label">Password</span><input type="password" bind:value={form.password} required />{#if form.errors.password}<span class="error">{form.errors.password}</span>{/if}</label>
        <label class="block"><span class="label">Confirm password</span><input type="password" bind:value={form.password_confirmation} required /></label>
        <button class="w-full" disabled={form.processing}>Reset password</button>
    </form>
</section></main>
