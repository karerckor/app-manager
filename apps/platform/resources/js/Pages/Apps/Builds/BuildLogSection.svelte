<script>
    import { Check, ChevronDown, X } from '@lucide/svelte';
    import AnsiLogOutput from './AnsiLogOutput.svelte';

    export let section;
    export let defaultExpanded = false;
    export let forceExpanded = false;
    export let follow = false;
    export let height = '8rem';

    let expanded = defaultExpanded;

    $: isExpanded = forceExpanded || expanded;

    const toggle = () => {
        if (forceExpanded) return;

        expanded = !expanded;
    };

    const statusLabel = () => {
        if (section.status === 'success') return 'Passed';
        if (section.status === 'failed') return 'Failed';
        if (section.status === 'running') return 'Running';

        return 'Output';
    };
</script>

<article class="bg-slate-950">
    <button
        type="button"
        class="group flex w-full items-center gap-3 rounded-none bg-slate-900/70 px-4 py-3 text-left text-slate-300 shadow-none transition hover:bg-slate-800/90 hover:text-white focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400"
        aria-expanded={isExpanded}
        on:click={toggle}
    >
        <span class="w-5 shrink-0 font-mono text-[9px] font-medium text-slate-600">{String(section.order).padStart(2, '0')}</span>
        <span
            class="grid h-5 w-5 shrink-0 place-items-center rounded-full border {section.status === 'success' ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-400' : section.status === 'failed' ? 'border-rose-400/30 bg-rose-400/10 text-rose-400' : section.status === 'running' ? 'border-indigo-400/30 bg-indigo-400/10 text-indigo-300' : 'border-slate-600 bg-slate-800 text-slate-400'}"
            aria-hidden="true"
        >
            {#if section.status === 'success'}
                <Check class="h-3 w-3" strokeWidth={2.5} />
            {:else if section.status === 'failed'}
                <X class="h-3 w-3" strokeWidth={2.5} />
            {:else}
                <span class="h-1.5 w-1.5 rounded-full bg-current {section.status === 'running' ? 'animate-pulse' : ''}"></span>
            {/if}
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-xs font-semibold text-slate-200">{section.name}</span>
            <span class="mt-0.5 block text-[9px] text-slate-500">{section.lineCount} {section.lineCount === 1 ? 'line' : 'lines'}{section.exitCode !== null ? ` · exit ${section.exitCode}` : ''}</span>
        </span>
        <span class="hidden text-[9px] font-bold tracking-wide uppercase sm:inline {section.status === 'success' ? 'text-emerald-400' : section.status === 'failed' ? 'text-rose-400' : section.status === 'running' ? 'text-indigo-300' : 'text-slate-500'}">{statusLabel()}</span>
        <ChevronDown class="h-4 w-4 shrink-0 text-slate-500 transition {isExpanded ? 'rotate-180' : ''}" strokeWidth={1.8} />
    </button>

    {#if isExpanded}
        <div class="border-t border-white/5 bg-slate-950" style:height>
            <AnsiLogOutput
                lines={section.lines}
                {follow}
                label={`${section.name} log output`}
            />
        </div>
    {/if}
</article>
