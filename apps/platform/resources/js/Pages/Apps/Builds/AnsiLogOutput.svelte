<script>
    import { onMount } from 'svelte';
    import { parseAnsiSegments } from './build-logs.js';

    export let lines = [];
    export let follow = false;
    export let label = 'Build log output';

    const lineHeight = 22;
    const overscan = 40;
    const directRenderLimit = 500;

    let directRows = [];
    let endIndex = 0;
    let resizeObserver;
    let scrollContainer;
    let startIndex = 0;
    let visibleRows = [];

    const renderLine = (value, index) => ({
        index,
        segments: parseAnsiSegments(value.replace(/\r/g, ''), null, 500).segments,
    });

    const updateWindow = () => {
        if (!scrollContainer || lines.length <= directRenderLimit) return;

        const firstVisible = Math.floor(scrollContainer.scrollTop / lineHeight);
        const visibleCount = Math.ceil(scrollContainer.clientHeight / lineHeight);
        startIndex = Math.max(0, firstVisible - overscan);
        endIndex = Math.min(lines.length, firstVisible + visibleCount + overscan);
        visibleRows = lines.slice(startIndex, endIndex).map((line, offset) => renderLine(line, startIndex + offset));
    };

    const syncLines = () => {
        if (!scrollContainer) return;

        if (follow) scrollContainer.scrollTop = scrollContainer.scrollHeight;

        if (lines.length > directRenderLimit) updateWindow();
    };

    $: directRows = lines.length <= directRenderLimit ? lines.map(renderLine) : [];
    $: lines, requestAnimationFrame(syncLines);

    onMount(() => {
        if (lines.length > directRenderLimit && typeof ResizeObserver !== 'undefined') {
            resizeObserver = new ResizeObserver(updateWindow);
            resizeObserver.observe(scrollContainer);
        }

        syncLines();

        return () => resizeObserver?.disconnect();
    });
</script>

<div
    class="h-full w-full overflow-auto bg-slate-950 font-mono text-xs text-slate-300 selection:bg-indigo-500/40"
    bind:this={scrollContainer}
    on:scroll={updateWindow}
    role="region"
    aria-label={label}
>
    {#if lines.length <= directRenderLimit}
        <div class="min-w-full w-max">
            {#each directRows as row (row.index)}
                <div class="whitespace-pre px-4" style:height={`${lineHeight}px`} style:line-height={`${lineHeight}px`}>
                    {#each row.segments as segment}<span style={segment.style}>{segment.text}</span>{/each}
                </div>
            {/each}
        </div>
    {:else}
        <div class="min-w-full w-max" style:min-height={`${lines.length * lineHeight}px`}>
            <div aria-hidden="true" style:height={`${startIndex * lineHeight}px`}></div>
            {#each visibleRows as row (row.index)}
                <div class="whitespace-pre px-4" style:height={`${lineHeight}px`} style:line-height={`${lineHeight}px`}>
                    {#each row.segments as segment}<span style={segment.style}>{segment.text}</span>{/each}
                </div>
            {/each}
            <div aria-hidden="true" style:height={`${Math.max(0, lines.length - endIndex) * lineHeight}px`}></div>
        </div>
    {/if}
</div>
