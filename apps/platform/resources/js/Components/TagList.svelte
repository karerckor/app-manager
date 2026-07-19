<script>
    export let tags = [];

    function contrast(hex) {
        const h = hex.replace('#', '');
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return luminance > 0.6 ? '#111827' : '#ffffff';
    }
</script>

{#if tags && tags.length}
    <span class="inline-flex flex-wrap gap-1">
        {#each tags as tag}
            <span
                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                style="background-color: {tag[1] || '#e5e7eb'}; color: {tag[1] ? contrast(tag[1]) : '#374151'};"
            >
                {tag[0]}
            </span>
        {/each}
    </span>
{/if}
