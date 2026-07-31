<script>
    import { Check, Copy } from '@lucide/svelte';

    let { label, value, name, copied, onCopy } = $props();
</script>

<div class="mt-3 grid gap-1">
    <span class="text-[10px] font-bold tracking-wide text-slate-500 uppercase">{label}</span>
    <div class="flex items-center gap-2">
        <code class="min-w-0 flex-1 truncate rounded-lg bg-white/80 px-3 py-2 text-[11px] text-slate-700 ring-1 ring-black/5">{value}</code>
        <button type="button" class="secondary h-8 w-8 shrink-0 p-0" title={`Copy ${label}`} onclick={() => onCopy(value, name)}>
            {#if copied === name}<Check size={14} class="text-emerald-600" />{:else}<Copy size={14} />{/if}
        </button>
    </div>
</div>
