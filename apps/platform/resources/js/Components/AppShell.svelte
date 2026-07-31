<script>
    import { Link, router } from '@inertiajs/svelte';
    import { LayoutGrid, KeyRound, Blocks, Users, LogOut, ScrollText, CreditCard, ShieldCheck, BellRing, Menu, X } from '@lucide/svelte';
    import ThemeToggle from './ui/ThemeToggle.svelte';

    let { auth, teams = [], currentTeam = null, active = 'apps', children } = $props();

    let drawerOpen = $state(false);

    const base = $derived(currentTeam?.slug ? `/accounts/${currentTeam.slug}` : null);
    const url = (suffix) => (base ? `${base}${suffix}` : '/dashboard');

    const items = $derived([
        { key: 'apps', label: 'Applications', href: url('/apps'), icon: LayoutGrid, show: true },
        { key: 'credentials', label: 'Credentials Vault', href: url('/credentials'), icon: ShieldCheck, show: true },
        { key: 'api-keys', label: 'API keys', href: url('/api-keys'), icon: KeyRound, show: currentTeam?.owner_id === auth.user.id },
        { key: 'audit', label: 'Audit log', href: url('/audit'), icon: ScrollText, show: ['owner', 'admin'].includes(auth?.team?.role) },
        { key: 'integrations', label: 'Integrations', href: url('/integrations'), icon: Blocks, show: Boolean(auth?.team?.permissions?.manage_integrations) },
        { key: 'team', label: 'Team access', href: url('/team'), icon: Users, show: true },
        { key: 'billing', label: 'Billing', href: url('/billing'), icon: CreditCard, show: Boolean(auth?.billing?.enabled) && currentTeam?.owner_id === auth?.user?.id },
    ].filter((item) => item.show));

    const tools = $derived([
        { key: 'push-notifications', label: 'Push notifications', href: url('/tools/push-notifications'), icon: BellRing },
    ]);

    const switchTeam = (event) => router.put(`/accounts/${event.currentTarget.value}/switch`);
    const logout = () => router.post('/logout');
    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

    const linkClass = (key) => [
        'flex items-center gap-3 rounded-sm px-3 py-2 text-sm transition',
        key === active
            ? 'bg-brand text-brand-fg font-semibold'
            : 'text-nav-text hover:bg-nav-text/10 hover:text-nav-strong',
    ].join(' ');

    // Inertia swaps the page without unmounting the shell, so the drawer would
    // otherwise stay open on top of the page the user just navigated to.
    $effect(() => {
        const done = router.on('navigate', () => (drawerOpen = false));
        return done;
    });

    const onKeydown = (event) => {
        if (event.key === 'Escape') drawerOpen = false;
    };
</script>

{#snippet nav()}
    <Link href={url('/apps')} class="mb-6 flex items-center gap-2.5 px-2">
        <span class="grid h-8 w-8 grid-cols-2 gap-0.5 rounded-sm bg-brand p-1.5">
            <!-- Square tiles, no radius: at ~10px a 1px rounding is invisible, an
                 arbitrary bracket radius fails design:lint, and a technical-drawing
                 grid is the right read for this mark anyway. -->
            <i class="bg-brand-fg"></i><i class="bg-brand-fg/60"></i>
            <i class="bg-brand-fg/60"></i><i class="bg-brand-fg"></i>
        </span>
        <span class="min-w-0">
            <strong class="block font-condensed text-xs font-semibold tracking-[0.06em] text-nav-strong uppercase">App Manager</strong>
            <small class="block font-mono text-2xs text-nav-text">developer console</small>
        </span>
    </Link>

    <div class="mb-5 rounded-sm border border-nav-border p-2">
        <label for="team" class="mb-1 block font-condensed text-2xs font-semibold tracking-[0.18em] text-nav-text uppercase">Workspace</label>
        <select
            id="team"
            class="w-full border-0 bg-transparent p-0.5 text-sm font-medium text-nav-strong shadow-none focus:ring-0"
            value={currentTeam?.slug ?? ''}
            onchange={switchTeam}
        >
            {#each teams as team (team.slug)}<option class="text-text" value={team.slug}>{team.name}</option>{/each}
        </select>
    </div>

    <nav class="space-y-0.5" aria-label="Main">
        {#each items as item (item.key)}
            <Link href={item.href} class={linkClass(item.key)} aria-current={item.key === active ? 'page' : undefined}>
                <item.icon class="h-4 w-4 shrink-0" strokeWidth={1.8} />
                {item.label}
            </Link>
        {/each}

        <p class="px-3 pt-4 pb-1 font-condensed text-2xs font-semibold tracking-[0.18em] text-nav-text/70 uppercase">Tools</p>
        {#each tools as item (item.key)}
            <Link href={item.href} class={linkClass(item.key)} aria-current={item.key === active ? 'page' : undefined}>
                <item.icon class="h-4 w-4 shrink-0" strokeWidth={1.8} />
                {item.label}
            </Link>
        {/each}
    </nav>

    <div class="mt-auto flex items-center gap-2 border-t border-nav-border pt-3">
        <Link href="/profile" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand/20 font-mono text-2xs font-semibold text-nav-strong" aria-label="Open profile">
            {initials(auth.user.name)}
        </Link>
        <Link href="/profile" class="min-w-0 flex-1 rounded-sm">
            <p class="truncate text-xs font-medium text-nav-strong">{auth.user.name}</p>
            <p class="truncate font-mono text-2xs text-nav-text">{auth.user.email}</p>
        </Link>
        <ThemeToggle />
        <button
            type="button"
            aria-label="Sign out"
            title="Sign out"
            class="grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none transition hover:bg-nav-text/10 hover:text-nav-strong"
            onclick={logout}
        >
            <LogOut class="h-4 w-4" strokeWidth={1.9} />
        </button>
    </div>
{/snippet}

<svelte:window onkeydown={onKeydown} />

<div class="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
    <aside class="hidden min-h-screen flex-col bg-nav px-3 py-4 lg:flex">
        {@render nav()}
    </aside>

    <div class="min-w-0">
        <header class="flex items-center justify-between border-b border-border bg-surface-raised px-4 py-2.5 lg:hidden">
            <button
                type="button"
                class="flex items-center gap-2 rounded-sm bg-transparent px-1 py-1 text-sm font-semibold text-text shadow-none"
                aria-label="Open navigation"
                aria-expanded={drawerOpen}
                onclick={() => (drawerOpen = true)}
            >
                <Menu class="h-5 w-5" strokeWidth={1.9} />
                <span class="font-condensed tracking-[0.06em] uppercase">App Manager</span>
            </button>
            <ThemeToggle />
        </header>

        {#if drawerOpen}
            <div
                class="fixed inset-0 z-40 bg-nav/60 lg:hidden"
                role="presentation"
                onclick={() => (drawerOpen = false)}
            ></div>
            <aside class="fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-nav px-3 py-4 lg:hidden">
                <button
                    type="button"
                    class="mb-2 ml-auto grid h-8 w-8 place-items-center rounded-sm bg-transparent p-0 text-nav-text shadow-none"
                    aria-label="Close navigation"
                    onclick={() => (drawerOpen = false)}
                >
                    <X class="h-4 w-4" strokeWidth={1.9} />
                </button>
                {@render nav()}
            </aside>
        {/if}

        {@render children?.()}
    </div>
</div>
