<script>
    import { Link, useForm } from '@inertiajs/svelte';
    const form = useForm({ email: '', password: '', remember: false });
    const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<svelte:head><title>Sign in</title></svelte:head>
<main class="grid min-h-screen place-items-center px-4"><section class="card w-full max-w-md">
    <p class="mb-2 text-sm font-semibold text-violet-400">APP MANAGER</p><h1 class="text-3xl font-bold">Welcome back</h1><p class="muted mt-2 mb-8">Sign in to manage your teams and API keys.</p>
    <form on:submit|preventDefault={submit} class="space-y-5">
        <label class="block"><span class="label">Email</span><input type="email" bind:value={form.email} autocomplete="email" required />{#if form.errors.email}<span class="error">{form.errors.email}</span>{/if}</label>
        <label class="block"><span class="label">Password</span><input type="password" bind:value={form.password} autocomplete="current-password" required />{#if form.errors.password}<span class="error">{form.errors.password}</span>{/if}</label>
        <label class="flex items-center gap-2 text-sm text-slate-300"><input class="h-4 w-4" type="checkbox" bind:checked={form.remember} /> Remember me</label>
        <button class="w-full" disabled={form.processing}>Sign in</button>
    </form>
    <div class="mt-6 flex justify-between text-sm"><Link href="/forgot-password" class="text-violet-400">Forgot password?</Link><Link href="/register" class="text-violet-400">Create account</Link></div>
</section></main>
