<script>
    import { useForm } from '@inertiajs/svelte';
    import Select from '../../../Components/Select.svelte';

    export let app;
    export let editing = null;
    export let open;
    export let onClose;

    const identifierForm = useForm({ platform: 'ios', identifier: '', name: '', version: '', version_code: '' });

    const initIdentifierForm = (identifier) => {
        if (identifier) {
            identifierForm.platform = identifier.platform;
            identifierForm.identifier = identifier.identifier;
            identifierForm.name = identifier.name ?? '';
            identifierForm.version = identifier.version ?? '';
            identifierForm.version_code = identifier.version_code ?? '';
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
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeIdentifier()}>
        <form class="modal" on:submit|preventDefault={saveIdentifier}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">{editing ? 'Edit app identifier' : 'Add app identifier'}</h2><p class="mt-1 text-sm text-slate-500">{editing ? 'Update this platform target.' : 'Connect a platform target to this project.'}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeIdentifier}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Platform</span><Select bind:value={identifierForm.platform} options={[{ value: 'ios', label: 'iOS' }, { value: 'android', label: 'Android' }, { value: 'web', label: 'Web' }]} ariaLabel="Platform" on:change={changeIdentifierPlatform} /></label>
                <label>
                    <span class="label">{identifierLabel(identifierForm.platform)}</span>
                    <input bind:value={identifierForm.identifier} placeholder={identifierPlaceholder(identifierForm.platform)} required />
                    {#if identifierForm.platform === 'web'}<span class="mt-1 block text-[10px] text-slate-400">Hostname only, without https://, a port, or a path.</span>{/if}
                    {#if identifierForm.errors.identifier}<span class="error">{identifierForm.errors.identifier}</span>{/if}
                </label>
                <label><span class="label">Display name <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.name} placeholder={displayNamePlaceholder(identifierForm.platform)} />{#if identifierForm.errors.name}<span class="error">{identifierForm.errors.name}</span>{/if}</label>
                <div class="grid gap-4 {identifierForm.platform === 'android' ? 'sm:grid-cols-2' : ''}">
                    <label><span class="label">Version <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.version} placeholder="1.0.0" />{#if identifierForm.errors.version}<span class="error">{identifierForm.errors.version}</span>{/if}</label>
                    {#if identifierForm.platform === 'android'}<label><span class="label">Version code <span class="font-normal text-slate-400">(optional)</span></span><input type="number" min="1" step="1" bind:value={identifierForm.version_code} placeholder="1" />{#if identifierForm.errors.version_code}<span class="error">{identifierForm.errors.version_code}</span>{/if}</label>{/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeIdentifier}>Cancel</button><button disabled={identifierForm.processing}>{identifierForm.processing ? 'Saving…' : editing ? 'Save changes' : 'Add identifier'}</button></div>
        </form>
    </div>
{/if}
