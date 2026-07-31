<script>
    import { Link, useForm } from '@inertiajs/svelte';
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<svelte:head><title>Create account</title></svelte:head>
<main class="grid min-h-screen place-items-center px-4 py-10"><section class="card w-full max-w-md">
    <p class="mb-2 text-sm font-semibold text-violet-400">APP MANAGER</p><h1 class="text-3xl font-bold">Create account</h1><p class="muted mt-2 mb-8">You’ll create your team after signing up.</p>
    <form on:submit|preventDefault={submit} class="space-y-5">
        <label class="block"><span class="label">Name</span><input bind:value={form.name} autocomplete="name" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>
        <label class="block"><span class="label">Email</span><input type="email" bind:value={form.email} autocomplete="email" required />{#if form.errors.email}<span class="error">{form.errors.email}</span>{/if}</label>
        <label class="block"><span class="label">Password</span><input type="password" bind:value={form.password} autocomplete="new-password" required />{#if form.errors.password}<span class="error">{form.errors.password}</span>{/if}</label>
        <label class="block"><span class="label">Confirm password</span><input type="password" bind:value={form.password_confirmation} autocomplete="new-password" required /></label>
        <button class="w-full" disabled={form.processing}>Create account</button>
    </form><p class="muted mt-6 text-center">Already registered? <Link href="/login" class="text-violet-400">Sign in</Link></p>
</section></main>
