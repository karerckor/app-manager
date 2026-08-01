<script>
    import { router } from '@inertiajs/svelte';
    import { Check, CircleGauge, CreditCard, ExternalLink } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';

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

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="billing" eyebrow="Hosted SaaS" title="Billing" description="A base monthly subscription with usage-based billing for accepted build runs." {flash}>
        <div class="grid gap-6 md:grid-cols-2">
            <section class="card">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <span class="grid h-11 w-11 place-items-center rounded-sm bg-brand-soft text-brand"><CreditCard class="h-5 w-5" strokeWidth={1.8} /></span>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {subscription.active ? 'bg-ok/10 text-ok' : 'bg-warn-text/10 text-warn-text'}">{statusLabel}</span>
                </div>
                <h2 class="text-lg font-bold text-text">Platform subscription</h2>
                <p class="mt-2 text-sm leading-6 text-muted">The Stripe subscription contains the base monthly price and the metered build-runs price.</p>
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
                    <span class="grid h-11 w-11 place-items-center rounded-sm bg-brand-soft text-brand"><CircleGauge class="h-5 w-5" strokeWidth={1.8} /></span>
                    <span class="rounded-full bg-surface-sunken px-2.5 py-1 text-[11px] font-bold text-muted">Current month</span>
                </div>
                <p class="text-sm font-semibold text-muted">Accepted build runs</p>
                <p class="mt-2 text-4xl font-bold tracking-tight text-text">{usage.build_runs_this_month}</p>
                <p class="mt-3 text-xs leading-5 text-muted">Each accepted build is recorded once. Included usage and overage rates are configured by graduated tiers on the Stripe Price.</p>
            </section>
        </div>
</WorkspacePage>
