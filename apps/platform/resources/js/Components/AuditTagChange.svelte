<script>
    export let change = {};

    $: before = change.from_tags ?? [];
    $: after = change.to_tags ?? [];
</script>

<div class="text-[11px] leading-4 text-slate-500">
    <span class="font-semibold text-slate-600">Tags:</span>

    {#if change.mode === 'changed'}
        <div class="mt-1.5 grid gap-1.5">
            <div class="flex items-start gap-2">
                <span class="w-10 shrink-0 pt-0.5 text-[10px] font-medium text-slate-400">Before</span>
                <span class="inline-flex flex-wrap gap-1">
                    {#each before as tag (tag.label)}
                        <span class="inline-flex items-center gap-1 rounded-md bg-slate-50 px-1.5 py-0.5 font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full" style={`background-color:${tag.color || '#94a3b8'}`}></span>
                            {tag.label}
                            {#if tag.animation}<span class="text-[10px] text-slate-400">· {tag.animation}</span>{/if}
                        </span>
                    {:else}
                        <span class="pt-0.5 text-slate-400">None</span>
                    {/each}
                </span>
            </div>
            <div class="flex items-start gap-2">
                <span class="w-10 shrink-0 pt-0.5 text-[10px] font-medium text-slate-400">After</span>
                <span class="inline-flex flex-wrap gap-1">
                    {#each after as tag (tag.label)}
                        <span class="inline-flex items-center gap-1 rounded-md bg-slate-50 px-1.5 py-0.5 font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full" style={`background-color:${tag.color || '#94a3b8'}`}></span>
                            {tag.label}
                            {#if tag.animation}<span class="text-[10px] text-slate-400">· {tag.animation}</span>{/if}
                        </span>
                    {:else}
                        <span class="pt-0.5 text-slate-400">None</span>
                    {/each}
                </span>
            </div>
        </div>
    {:else}
        <span class="mt-1.5 inline-flex flex-wrap gap-1">
            {#each change.mode === 'removed' ? before : after as tag (tag.label)}
                <span class="inline-flex items-center gap-1 rounded-md bg-slate-50 px-1.5 py-0.5 font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full" style={`background-color:${tag.color || '#94a3b8'}`}></span>
                    {tag.label}
                    {#if tag.animation}<span class="text-[10px] text-slate-400">· {tag.animation}</span>{/if}
                </span>
            {:else}
                <span class="text-slate-400">None</span>
            {/each}
            {#if change.mode === 'removed'}<span class="self-center text-slate-400">→ Removed</span>{/if}
        </span>
    {/if}
</div>
