<script>
    import { Plus, Trash2 } from '@lucide/svelte';
    import Select from './Select.svelte';

    let { form, providers = [] } = $props();

    const selected = $derived(providers.find((provider) => provider.value === form.vault.provider) ?? providers[0]);

    const initialRows = () => Object.entries(form.vault.metadata ?? {})
        .map(([key, value]) => ({ key, value }));
    let rows = $state(initialRows());

    const syncMetadata = () => {
        form.vault.metadata = Object.fromEntries(
            rows
                .filter((row) => row.key.trim() !== '')
                .map((row) => [row.key.trim(), String(row.value)]),
        );
    };

    // Rows only flush into form.vault.metadata on blur. Clicking Save is safe
    // (mousedown blurs the input first), but pressing Enter inside a text
    // input submits the form without blurring anything first, which would
    // otherwise post the metadata block one edit behind what is on screen —
    // and since the server replaces metadata wholesale, a newly added row
    // would be silently dropped. The parent calls this before every submit.
    export function sync() {
        syncMetadata();
    }
</script>

<div class="space-y-5">
    <label>
        <span class="label">Vault provider</span>
        <Select bind:value={form.vault.provider} options={providers} ariaLabel="Vault provider" />
        {#if form.errors['vault.provider']}<span class="error">{form.errors['vault.provider']}</span>{/if}
    </label>

    <label>
        <span class="label">Resource</span>
        <input class="font-mono text-xs" bind:value={form.vault.resource} placeholder={selected?.placeholder ?? ''} autocomplete="off" />
        <p class="mt-1.5 text-[11px] text-faint">App Manager never reads this address. Your pipeline resolves it.</p>
        {#if form.errors['vault.resource']}<span class="error">{form.errors['vault.resource']}</span>{/if}
    </label>

    <div class="space-y-2">
        <span class="label">Storage metadata <span class="font-normal text-faint">(optional key/value pairs your pipeline needs)</span></span>
        {#each rows as row, index (index)}
            <div class="flex gap-2">
                <input class="w-1/3 font-mono text-xs" placeholder="region" bind:value={row.key} onblur={syncMetadata} />
                <input class="flex-1 font-mono text-xs" placeholder="eu-central-1" bind:value={row.value} onblur={syncMetadata} />
                <button
                    type="button"
                    class="icon-btn shrink-0"
                    aria-label="Remove metadata row"
                    onclick={() => { rows = rows.filter((_, i) => i !== index); syncMetadata(); }}
                >
                    <Trash2 size={14} />
                </button>
            </div>
        {/each}
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-sm border border-dashed border-border-control bg-transparent px-2 py-1 text-xs font-semibold text-muted shadow-none transition hover:border-brand hover:bg-brand-soft hover:text-brand"
            onclick={() => { rows = [...rows, { key: '', value: '' }]; }}
        >
            <Plus class="h-3 w-3" strokeWidth={2.6} /> Add metadata
        </button>
        {#if form.errors['vault.metadata']}<span class="error">{form.errors['vault.metadata']}</span>{/if}
    </div>
</div>
