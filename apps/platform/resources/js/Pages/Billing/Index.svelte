<script>
    import { router } from '@inertiajs/svelte';
    import { Check, CircleGauge, CreditCard, ExternalLink } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let subscription;
    export let usage;
    export let checkoutUrl;
    export let portalUrl = null;

    let redirecting = false;

    const postAndRedirect = (url) => {
        redirecting = true;
        router.post(url, {}, { onFinish: () => redirecting = false });
    };

    $: statusLabel = subscription.active
        ? (subscription.on_grace_period ? 'Cancels at period end' : 'Active')
        : 'Subscription required';
</script>

<svelte:head><title>Billing · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="billing">
    <main class="mx-auto max-w-[960px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8">
            <p class="eyebrow mb-2">Hosted SaaS</p>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950">Billing</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-500">A base monthly subscription with usage-based billing for accepted build runs.</p>
        </div>

        {#if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-white"><Check class="h-3 w-3" strokeWidth={3} /></span>
                {flash.status}
            </div>
        {/if}

        <div class="grid gap-6 md:grid-cols-2">
            <section class="card">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><CreditCard class="h-5 w-5" strokeWidth={1.8} /></span>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {subscription.active ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}">{statusLabel}</span>
                </div>
                <h2 class="text-lg font-bold text-slate-900">Platform subscription</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">The Stripe subscription contains the base monthly price and the metered build-runs price.</p>
                <div class="mt-6">
                    {#if subscription.active}
                        <button class="flex w-full items-center justify-center gap-2" disabled={redirecting || !portalUrl} on:click={() => postAndRedirect(portalUrl)}>
                            Manage in Stripe <ExternalLink class="h-4 w-4" />
                        </button>
                    {:else}
                        <button class="w-full" disabled={redirecting} on:click={() => postAndRedirect(checkoutUrl)}>
                            {redirecting ? 'Opening Stripe…' : 'Start subscription'}
                        </button>
                    {/if}
                </div>
            </section>

            <section class="card">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-violet-50 text-violet-600"><CircleGauge class="h-5 w-5" strokeWidth={1.8} /></span>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">Current month</span>
                </div>
                <p class="text-sm font-semibold text-slate-500">Accepted build runs</p>
                <p class="mt-2 text-4xl font-bold tracking-tight text-slate-950">{usage.build_runs_this_month}</p>
                <p class="mt-3 text-xs leading-5 text-slate-500">Each accepted build is recorded once. Included usage and overage rates are configured by graduated tiers on the Stripe Price.</p>
            </section>
        </div>
    </main>
</AppShell>
