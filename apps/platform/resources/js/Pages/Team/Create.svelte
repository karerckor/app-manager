<script>
    import { router, useForm } from '@inertiajs/svelte';
    import { LogOut, Users } from '@lucide/svelte';

    export let auth;
    export let storeUrl;

    const form = useForm({ name: '' });
    const submit = () => form.post(storeUrl);
    const logout = () => router.post('/logout');
</script>

<svelte:head><title>Create your team · App Manager</title></svelte:head>

<main class="grid min-h-screen place-items-center px-4 py-10">
    <section class="w-full max-w-md">
        <div class="mb-4 flex items-center justify-between px-1">
            <p class="text-sm font-semibold tracking-wide text-indigo-600">APP MANAGER</p>
            <button type="button" class="ghost flex items-center gap-2 px-3 py-2 text-xs" on:click={logout}>
                <LogOut class="h-4 w-4" />
                Sign out
            </button>
        </div>

        <div class="card p-7 sm:p-8">
            <span class="mb-5 grid h-12 w-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600">
                <Users class="h-6 w-6" strokeWidth={1.8} />
            </span>
            <h1 class="text-2xl font-bold tracking-tight">Create your team</h1>
            <p class="muted mt-2 mb-7">Welcome, {auth.user.name}. Give your workspace a name to get started.</p>

            <form on:submit|preventDefault={submit} class="space-y-5">
                <label class="block">
                    <span class="label">Team name</span>
                    <input bind:value={form.name} autocomplete="organization" placeholder="e.g. Acme Mobile" required />
                    {#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}
                </label>
                <button class="w-full" disabled={form.processing}>
                    {form.processing ? 'Creating…' : 'Create team'}
                </button>
            </form>
        </div>
    </section>
</main>
