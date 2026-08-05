<script>
    import { useForm } from '@inertiajs/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let createTeamUrl;

    const form = useForm({ name: '' });
    const createTeam = () => form.post(createTeamUrl);
</script>

<svelte:head><title>Create a team · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam}>
    <main class="mx-auto grid min-h-[80vh] max-w-2xl place-items-center px-5 py-12">
        <section class="card w-full p-8 text-center">
            <span class="mx-auto mb-5 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-xl font-bold text-indigo-600">+</span>
            <h1 class="text-2xl font-bold tracking-tight">Create a team</h1>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">Create a workspace for your applications, identifiers, and credentials.</p>
            {#if flash?.status}<p class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{flash.status}</p>{/if}
            <form class="mx-auto mt-7 flex max-w-md gap-3" on:submit|preventDefault={createTeam}>
                <label class="min-w-0 flex-1 text-left"><span class="sr-only">Team name</span><input bind:value={form.name} placeholder="Team name" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>
                <button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create team'}</button>
            </form>
        </section>
    </main>
</AppShell>
