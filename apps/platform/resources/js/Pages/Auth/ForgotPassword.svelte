<script>
    import { Link, useForm } from '@inertiajs/svelte';
    export let status = null;
    const form = useForm({ email: '' });
</script>

<svelte:head><title>Reset password</title></svelte:head>
<main class="grid min-h-screen place-items-center px-4"><section class="card w-full max-w-md">
    <h1 class="text-3xl font-bold">Reset password</h1><p class="muted mt-2 mb-8">We will email you a secure reset link.</p>
    {#if status}<p class="mb-4 text-emerald-400">{status}</p>{/if}
    <form on:submit|preventDefault={() => form.post('/forgot-password')} class="space-y-5"><label class="block"><span class="label">Email</span><input type="email" bind:value={form.email} required />{#if form.errors.email}<span class="error">{form.errors.email}</span>{/if}</label><button class="w-full" disabled={form.processing}>Email reset link</button></form>
    <p class="mt-6 text-center"><Link href="/login" class="text-violet-400">Back to sign in</Link></p>
</section></main>
