<script>
    import { Link, useForm } from '@inertiajs/svelte';
    let { auth = {} } = $props();
    const form = useForm({ email: '', password: '', remember: false });
    const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<svelte:head><title>Sign in</title></svelte:head>
<main class="grid min-h-screen place-items-center bg-surface-sunken px-4">
    <section class="card w-full max-w-md">
        <p class="font-condensed text-2xs font-semibold tracking-[0.18em] text-brand uppercase">App Manager</p>
        <h1 class="mt-2 font-condensed text-3xl font-semibold text-text uppercase">Welcome back</h1>
        <p class="muted mt-2 mb-8">Sign in to manage your teams and API keys.</p>
        <form onsubmit={(event) => { event.preventDefault(); submit(); }} class="space-y-5">
            <label class="block"><span class="label">Email</span><input type="email" bind:value={form.email} autocomplete="email" required />{#if form.errors.email}<span class="error">{form.errors.email}</span>{/if}</label>
            <label class="block"><span class="label">Password</span><input type="password" bind:value={form.password} autocomplete="current-password" required />{#if form.errors.password}<span class="error">{form.errors.password}</span>{/if}</label>
            <label class="flex items-center gap-2 text-sm text-muted"><input class="h-4 w-4" type="checkbox" bind:checked={form.remember} /> Remember me</label>
            <button class="w-full" disabled={form.processing}>Sign in</button>
        </form>
        <div class="mt-6 flex justify-between text-sm"><Link href="/forgot-password" class="text-brand hover:text-brand/80">Forgot password?</Link>{#if auth.registration_enabled !== false}<Link href="/register" class="text-brand hover:text-brand/80">Create account</Link>{/if}</div>
    </section>
</main>
