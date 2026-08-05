<script>
    export let title;
    export let data = {};

    const entries = (value) => Array.isArray(value)
        ? value.map((item, index) => [String(index), item])
        : Object.entries(value ?? {});
    const isStructured = (value) => value !== null && typeof value === 'object';
    const scalar = (value) => {
        if (value === null) return 'null';
        if (value === '') return '""';
        if (typeof value === 'boolean') return value ? 'true' : 'false';

        return String(value);
    };
</script>

<section class="overflow-hidden rounded-md border border-border-subtle bg-surface-raised">
    <h5 class="border-b border-border-subtle bg-surface-sunken/70 px-3 py-2 font-mono text-[9px] font-semibold tracking-wide text-muted">{title}</h5>
    {#if entries(data).length}
        <dl class="divide-y divide-border-subtle">
            {#each entries(data) as [key, value]}
                <div class="grid gap-1 px-3 py-2.5 text-[10px] sm:grid-cols-[minmax(7rem,0.38fr)_minmax(0,1fr)] sm:gap-4">
                    <dt class="break-all font-mono text-faint">{key}</dt>
                    <dd class="min-w-0 text-text">
                        {#if isStructured(value)}
                            <pre class="max-h-72 overflow-auto rounded-sm bg-surface-sunken p-2.5 font-mono text-[9px] leading-4 whitespace-pre-wrap text-muted">{JSON.stringify(value, null, 2)}</pre>
                        {:else}
                            <span class="break-words font-mono">{scalar(value)}</span>
                        {/if}
                    </dd>
                </div>
            {/each}
        </dl>
    {:else}
        <p class="px-3 py-5 text-center text-[10px] text-faint">No data</p>
    {/if}
</section>
