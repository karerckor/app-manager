<script>
    let {
        label,
        field,
        value = $bindable(),
        type = 'text',
        placeholder = '',
        wrapperClass = '',
        required = true,
        error,
    } = $props();
</script>

<label class={wrapperClass}>
    <span class="label">{label}</span>
    <input {type} bind:value {placeholder} {required} />
    {#if error(field)}<span class="error">{error(field)}</span>{/if}
</label>
