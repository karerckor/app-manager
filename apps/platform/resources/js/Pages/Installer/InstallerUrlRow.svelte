<script>
    import { Check, Copy } from '@lucide/svelte';

    let { label, value, name, copied, onCopy } = $props();
</script>

<div class="mt-3 grid gap-1">
    <span class="font-condensed text-2xs font-semibold tracking-wide text-faint uppercase">{label}</span>
    <div class="flex items-center gap-2">
        <code class="min-w-0 flex-1 truncate rounded-sm border border-border bg-surface-raised px-3 py-2 text-xs text-text">{value}</code>
        <button type="button" class="secondary h-8 w-8 shrink-0 p-0" title={`Copy ${label}`} onclick={() => onCopy(value, name)}>
            {#if copied === name}<Check size={14} class="text-ok" />{:else}<Copy size={14} />{/if}
        </button>
    </div>
</div>
