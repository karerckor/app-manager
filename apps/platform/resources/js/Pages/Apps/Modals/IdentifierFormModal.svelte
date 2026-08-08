<script>
    import { useForm } from '@inertiajs/svelte';
    import Select from '../../../Components/Select.svelte';

    export let app;
    export let editing = null;
    export let open;
    export let onClose;

    const identifierForm = useForm({
        platform: 'ios',
        identifier: '',
        name: '',
        version: '',
        version_code: '',
        build_number: '',
        is_default: false,
        create_for_both_platforms: false,
    });

    const initIdentifierForm = (identifier) => {
        if (identifier) {
            identifierForm.platform = identifier.platform;
            identifierForm.identifier = identifier.identifier;
            identifierForm.name = identifier.name ?? '';
            identifierForm.version = identifier.version ?? '';
            identifierForm.version_code = identifier.version_code ?? '';
            identifierForm.build_number = identifier.build_number ?? '';
            identifierForm.is_default = Boolean(identifier.is_default);
            identifierForm.create_for_both_platforms = false;
        } else {
            identifierForm.reset();
        }
        identifierForm.clearErrors();
    };

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;

        if (open) initIdentifierForm(editing);
    }

    const closeIdentifier = () => {
        identifierForm.reset();
        identifierForm.clearErrors();
        onClose();
    };
    const saveIdentifier = () => {
        const options = { preserveScroll: true, onSuccess: closeIdentifier };

        if (editing) {
            identifierForm.patch(editing.update_url, options);
        } else {
            identifierForm.post(app.urls.identifiers, options);
        }
    };
    const changeIdentifierPlatform = (event) => {
        identifierForm.platform = event.detail;
        if (identifierForm.platform !== 'android') identifierForm.version_code = '';
        if (identifierForm.platform !== 'ios') identifierForm.build_number = '';
    };
    const setCreationScope = (paired) => {
        identifierForm.create_for_both_platforms = paired;

        if (paired) {
            identifierForm.platform = 'ios';
        } else {
            identifierForm.version_code = '';
        }
    };
    const identifierLabel = (platform) => ({
        ios: 'Bundle identifier',
        android: 'Package name',
        web: 'Domain or subdomain',
    })[platform] ?? 'Identifier';
    const identifierPlaceholder = (platform) => platform === 'web'
        ? 'app.example.com'
        : 'com.company.product';
    const displayNamePlaceholder = (platform) => ({
        ios: 'Atlas for iOS',
        android: 'Atlas for Android',
        web: 'Atlas Web',
    })[platform] ?? 'Atlas';
    const isMobilePair = () => !editing && identifierForm.create_for_both_platforms;
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeIdentifier()}>
        <form class="modal" on:submit|preventDefault={saveIdentifier}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">{editing ? 'Edit app identifier' : 'Add app identifier'}</h2><p class="mt-1 text-sm text-slate-500">{editing ? 'Update this platform target.' : 'Create one target or a matched mobile pair.'}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeIdentifier} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                {#if !editing}
                    <fieldset>
                        <legend class="label">Create for</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" class="rounded-md border px-3 py-3 text-left shadow-none transition {isMobilePair() ? 'border-border bg-surface-raised text-muted hover:border-border-control' : 'border-brand bg-brand-soft text-brand ring-1 ring-brand/15'}" aria-pressed={!isMobilePair()} on:click={() => setCreationScope(false)}>
                                <span class="block text-xs font-bold">One platform</span>
                                <span class="mt-1 block text-[10px] font-normal leading-4 opacity-75">iOS, Android, or Web</span>
                            </button>
                            <button type="button" class="rounded-md border px-3 py-3 text-left shadow-none transition {isMobilePair() ? 'border-brand bg-brand-soft text-brand ring-1 ring-brand/15' : 'border-border bg-surface-raised text-muted hover:border-border-control'}" aria-pressed={isMobilePair()} on:click={() => setCreationScope(true)}>
                                <span class="block text-xs font-bold">iOS + Android</span>
                                <span class="mt-1 block text-[10px] font-normal leading-4 opacity-75">Same identifier, two targets</span>
                            </button>
                        </div>
                        {#if identifierForm.errors.create_for_both_platforms}<span class="error">{identifierForm.errors.create_for_both_platforms}</span>{/if}
                    </fieldset>
                {/if}
                {#if !isMobilePair()}<label><span class="label">Platform</span><Select bind:value={identifierForm.platform} options={[{ value: 'ios', label: 'iOS' }, { value: 'android', label: 'Android' }, { value: 'web', label: 'Web' }]} ariaLabel="Platform" on:change={changeIdentifierPlatform} />{#if identifierForm.errors.platform}<span class="error">{identifierForm.errors.platform}</span>{/if}</label>{/if}
                <label>
                    <span class="label">{isMobilePair() ? 'Shared app identifier' : identifierLabel(identifierForm.platform)}</span>
                    <input bind:value={identifierForm.identifier} placeholder={identifierPlaceholder(identifierForm.platform)} required />
                    {#if isMobilePair()}<span class="mt-1 block text-[10px] text-slate-400">Used as both the iOS bundle identifier and Android package name.</span>{:else if identifierForm.platform === 'web'}<span class="mt-1 block text-[10px] text-slate-400">Hostname only, without https://, a port, or a path.</span>{/if}
                    {#if identifierForm.errors.identifier}<span class="error">{identifierForm.errors.identifier}</span>{/if}
                </label>
                <label><span class="label">Display name <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.name} placeholder={isMobilePair() ? 'Atlas Mobile' : displayNamePlaceholder(identifierForm.platform)} />{#if identifierForm.errors.name}<span class="error">{identifierForm.errors.name}</span>{/if}</label>
                <div class="grid gap-4 {isMobilePair() || identifierForm.platform !== 'web' ? 'sm:grid-cols-2' : ''}">
                    <label><span class="label">Version <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.version} placeholder="1.0.0" />{#if identifierForm.errors.version}<span class="error">{identifierForm.errors.version}</span>{/if}</label>
                    {#if isMobilePair() || identifierForm.platform === 'ios'}<label><span class="label">iOS build number <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.build_number} placeholder="1" />{#if identifierForm.errors.build_number}<span class="error">{identifierForm.errors.build_number}</span>{/if}</label>{/if}
                    {#if isMobilePair() || identifierForm.platform === 'android'}<label><span class="label">Android version code <span class="font-normal text-slate-400">(optional)</span></span><input type="number" min="1" step="1" bind:value={identifierForm.version_code} placeholder="1" />{#if identifierForm.errors.version_code}<span class="error">{identifierForm.errors.version_code}</span>{/if}</label>{/if}
                </div>
                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-surface-sunken px-4 py-3">
                    <input class="mt-0.5 h-4 w-4 shrink-0 accent-brand" type="checkbox" bind:checked={identifierForm.is_default} />
                    <span><span class="block text-xs font-bold text-text">Default identifier</span><span class="mt-1 block text-[10px] leading-4 text-muted">Use this target automatically when a build specifies a platform but no identifier.{#if isMobilePair()} Applies to both platforms.{/if}</span></span>
                </label>
                {#if identifierForm.errors.is_default}<span class="error">{identifierForm.errors.is_default}</span>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeIdentifier}>Cancel</button><button disabled={identifierForm.processing}>{identifierForm.processing ? 'Saving…' : editing ? 'Save changes' : isMobilePair() ? 'Add 2 identifiers' : 'Add identifier'}</button></div>
        </form>
    </div>
{/if}
