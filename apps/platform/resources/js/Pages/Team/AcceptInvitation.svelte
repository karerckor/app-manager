<script>
    import { router } from '@inertiajs/svelte';
    import { MailCheck } from '@lucide/svelte';

    export let invitation;
    let processing = false;
    const accept = () => {
        processing = true;
        router.post(invitation.accept_url, {}, { onFinish: () => processing = false });
    };
</script>

<svelte:head><title>Team invitation · App Manager</title></svelte:head>

<main class="grid min-h-screen place-items-center px-5 py-12">
    <section class="card w-full max-w-md p-7 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><MailCheck class="h-7 w-7" strokeWidth={1.7} /></span>
        <p class="eyebrow mt-5">Team invitation</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight">Join {invitation.team_name}</h1>
        <p class="mt-3 text-sm leading-6 text-slate-500">You were invited as <strong class="capitalize text-slate-700">{invitation.role}</strong>.</p>

        {#if !invitation.is_pending}
            <div class="mt-6 rounded-xl bg-slate-100 px-4 py-3 text-sm font-medium text-slate-600">This invitation has expired or is no longer active.</div>
        {:else if !invitation.email_matches}
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Sign in as <strong>{invitation.email}</strong> to accept this invitation.</div>
        {:else}
            <button class="mt-7 w-full" disabled={processing} on:click={accept}>{processing ? 'Joining…' : `Join ${invitation.team_name}`}</button>
        {/if}
    </section>
</main>
