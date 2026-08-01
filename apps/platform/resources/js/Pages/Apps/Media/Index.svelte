<script>
    import { router, useForm } from '@inertiajs/svelte';
    import {
        Braces,
        Download,
        FileArchive,
        FileImage,
        FileMusic,
        FilePlay,
        FileSpreadsheet,
        FileText,
        Files,
        Image as ImageIcon,
        LoaderCircle,
        Pencil,
        RefreshCw,
        Sparkles,
        Trash2,
        Upload,
        X,
    } from '@lucide/svelte';
    import AppLayout from '../AppLayout.svelte';
    import { formatDateTime, formatMetadata, parseMetadata } from '../lib.js';

    let {
        auth,
        flash = {},
        app,
        pipelines = [],
        identifiers = [],
        buildProfiles = [],
        tag_suggestions = [],
        navigationTeams = [],
        navigationTeam = null,
        active = 'media',
        fileLibrary = { files: [], upload_url: '', max_file_size: 0 },
        mediaLibrary = [],
    } = $props();

    let section = $state('files');
    let selectedAssetCollection = $state('app_icon');
    let showUpload = $state(false);
    let uploading = $state(null);
    let generating = $state(false);
    let fileInput = $state(null);
    let fileMetadataText = $state('');
    let fileMetadataError = $state('');
    let metadataPreviewFileId = $state(null);
    let editingFileId = $state(null);
    let editMetadataText = $state('');
    let editMetadataError = $state('');
    let confirmingDeleteId = $state(null);
    let deletingFileId = $state(null);

    const fileForm = useForm({ file: null, metadata: '' });
    const metadataForm = useForm({ metadata: '' });

    const selectedAsset = $derived(
        mediaLibrary.find((asset) => asset.collection === selectedAssetCollection) ?? mediaLibrary[0],
    );

    const upload = (collection, event) => {
        const file = event.currentTarget.files?.[0];
        event.currentTarget.value = '';
        if (!file) return;

        router.post(
            app.urls.assets.replace('__collection__', collection),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => uploading = collection,
                onFinish: () => uploading = null,
            },
        );
    };

    const formatBytes = (bytes) => {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return `${(bytes / (1024 ** unit)).toFixed(unit ? 1 : 0)} ${units[unit]}`;
    };

    const selectFile = (event) => {
        fileForm.file = event.currentTarget.files?.[0] ?? null;
        fileForm.clearErrors('file');
    };

    const resetUpload = () => {
        showUpload = false;
        fileForm.reset();
        fileForm.clearErrors();
        fileMetadataText = '';
        fileMetadataError = '';
        if (fileInput) fileInput.value = '';
    };

    const closeUpload = () => {
        if (!fileForm.processing) resetUpload();
    };

    const uploadFile = () => {
        const metadata = parseMetadata(fileMetadataText, (message) => fileMetadataError = message);

        if (metadata === undefined || !fileForm.file) return;

        fileForm.metadata = metadata === null ? '' : JSON.stringify(metadata);
        fileForm.post(fileLibrary.upload_url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: resetUpload,
        });
    };

    const toggleMetadata = (file) => {
        editingFileId = null;
        confirmingDeleteId = null;
        metadataPreviewFileId = metadataPreviewFileId === file.id ? null : file.id;
    };

    const editFile = (file) => {
        metadataPreviewFileId = file.id;
        editingFileId = file.id;
        confirmingDeleteId = null;
        editMetadataText = formatMetadata(file.metadata);
        editMetadataError = '';
        metadataForm.metadata = '';
        metadataForm.clearErrors();
    };

    const cancelEdit = () => {
        editingFileId = null;
        editMetadataText = '';
        editMetadataError = '';
        metadataForm.clearErrors();
    };

    const saveMetadata = (file) => {
        const metadata = parseMetadata(editMetadataText, (message) => editMetadataError = message);

        if (metadata === undefined) return;

        metadataForm.metadata = metadata === null ? '' : JSON.stringify(metadata);
        metadataForm.patch(file.urls.update, {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });
    };

    const confirmDelete = (file) => {
        editingFileId = null;
        metadataPreviewFileId = null;
        confirmingDeleteId = file.id;
    };

    const deleteFile = (file) => router.delete(file.urls.destroy, {
        preserveScroll: true,
        onStart: () => deletingFileId = file.id,
        onSuccess: () => {
            confirmingDeleteId = null;
            metadataPreviewFileId = null;
        },
        onFinish: () => deletingFileId = null,
    });

    const fileKind = (file) => {
        if (file.preview_type === 'image') return 'image';
        if (file.preview_type === 'video') return 'video';

        const extension = file.extension?.toLowerCase();
        const mime = file.mime_type?.toLowerCase() ?? '';

        if (extension === 'pdf') return 'pdf';
        if (mime.startsWith('audio/') || ['aac', 'flac', 'm4a', 'mp3', 'ogg', 'opus', 'wav', 'wma'].includes(extension)) return 'audio';
        if (['doc', 'docx', 'odt', 'pages'].includes(extension)) return 'document';
        if (['csv', 'numbers', 'ods', 'xls', 'xlsm', 'xlsx'].includes(extension)) return 'spreadsheet';
        if (['log', 'md', 'rtf', 'text', 'txt'].includes(extension)) return 'text';
        if (['7z', 'apk', 'bz2', 'dmg', 'gz', 'ipa', 'pkg', 'rar', 'tar', 'tgz', 'xz', 'zip'].includes(extension)) return 'archive';
        if (mime.startsWith('image/')) return 'image-file';
        if (mime.startsWith('video/')) return 'video-file';

        return 'extension';
    };

    const extensionLabel = (file) => (file.extension || 'file').toUpperCase().slice(0, 8);
    const badgeTheme = (kind) => ({
        image: 'bg-brand-soft text-brand ring-brand-soft-line/70',
        'image-file': 'bg-brand-soft text-brand ring-brand-soft-line/70',
        video: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200/70',
        'video-file': 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200/70',
        pdf: 'bg-danger/10 text-danger ring-danger/70',
        audio: 'bg-pink-50 text-pink-700 ring-pink-200/70',
        document: 'bg-brand-soft text-brand ring-brand-soft-line/70',
        spreadsheet: 'bg-ok/10 text-ok ring-ok/70',
        text: 'bg-border-subtle text-muted ring-border',
        archive: 'bg-warn-text/10 text-warn-fill ring-warn-fill/70',
        extension: 'bg-border-subtle text-muted ring-border',
    }[kind] ?? 'bg-border-subtle text-muted ring-border');

    const placeholderTheme = (kind) => ({
        'image-file': 'from-brand-soft to-brand-soft text-brand',
        'video-file': 'from-fuchsia-50 to-brand-soft text-fuchsia-500',
        pdf: 'from-danger/10 to-red-50 text-danger',
        audio: 'from-pink-50 to-fuchsia-50 text-pink-500',
        document: 'from-brand-soft to-brand-soft text-brand',
        spreadsheet: 'from-ok/10 to-green-50 text-ok',
        text: 'from-surface-sunken to-border-subtle text-muted',
        archive: 'from-warn-text/10 to-orange-50 text-warn-fill',
        extension: 'from-surface-sunken to-border-subtle text-muted',
    }[kind] ?? 'from-surface-sunken to-border-subtle text-muted');

    const readyCount = (asset) => asset.platforms
        .flatMap((platform) => platform.variants)
        .filter((variant) => variant.ready)
        .length;

    const variantCount = (asset) => asset.platforms
        .reduce((total, platform) => total + platform.variants.length, 0);

    const previewStyle = (asset, variant) => {
        const dimensions = asset.platforms
            .flatMap((platform) => platform.variants)
            .map((item) => Math.max(item.width, item.height));
        const smallest = Math.min(...dimensions);
        const largest = Math.max(...dimensions);
        const longestSide = Math.max(variant.width, variant.height);
        const progress = largest === smallest
            ? 1
            : (Math.log(longestSide) - Math.log(smallest)) / (Math.log(largest) - Math.log(smallest));
        const displayedLongestSide = 32 + (progress * 86);
        const width = Math.max(12, Math.round(displayedLongestSide * (variant.width / longestSide)));
        const height = Math.max(12, Math.round(displayedLongestSide * (variant.height / longestSide)));

        return `width: ${width}px; height: ${height}px;`;
    };

    const hasPendingVariants = $derived(mediaLibrary.some(
        (asset) => asset.source && readyCount(asset) < variantCount(asset),
    ));

    const regenerate = () => router.post(
        app.urls.media_regenerate,
        {},
        {
            preserveScroll: true,
            onStart: () => generating = true,
            onFinish: () => generating = false,
        },
    );

    const refresh = () => router.reload({ only: ['mediaLibrary'], preserveScroll: true });
</script>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-xl font-extrabold tracking-tight text-text">Files and Media Library</h2>
            <p class="mt-1 max-w-2xl text-xs leading-5 text-muted">Store project files with structured metadata and generate every required app icon and splash-screen size.</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap gap-2" role="tablist" aria-label="Files and media sections">
        <button
            type="button"
            role="tab"
            aria-selected={section === 'files'}
            aria-controls="files-panel"
            class="chip {section === 'files' ? 'border-brand-soft-line bg-brand-soft text-brand' : 'border-border bg-surface-raised text-muted hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand'}"
            onclick={() => section = 'files'}
        >
            Files
            <span class="ml-1 rounded-full bg-surface-raised/80 px-1.5 py-0.5 font-mono text-[9px] font-bold text-current ring-1 ring-current/10">{fileLibrary.files.length}</span>
        </button>

        <button
            type="button"
            role="tab"
            aria-selected={section === 'assets'}
            aria-controls="assets-panel"
            class="chip {section === 'assets' ? 'border-brand-soft-line bg-brand-soft text-brand' : 'border-border bg-surface-raised text-muted hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand'}"
            onclick={() => section = 'assets'}
        >
            App assets
            <span class="ml-1 rounded-full bg-surface-raised/80 px-1.5 py-0.5 font-mono text-[9px] font-bold text-current ring-1 ring-current/10">{mediaLibrary.length}</span>
        </button>
    </div>

    {#if section === 'files'}
        <section id="files-panel" role="tabpanel">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-text">Attached files</h3>
                    <p class="mt-1 text-[11px] text-muted">Any file type, up to {formatBytes(fileLibrary.max_file_size)} per file.</p>
                </div>
                <button type="button" class="px-3 py-2 text-xs" onclick={() => showUpload = true}>
                    <Upload class="mr-2 h-3.5 w-3.5" strokeWidth={2} />
                    Upload file
                </button>
            </div>

            {#if fileLibrary.files.length}
                <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {#each fileLibrary.files as file}
                        {@const kind = fileKind(file)}
                        <article class="card group overflow-hidden p-0">
                            <div class="relative aspect-[16/10] overflow-hidden border-b border-border-subtle bg-surface-sunken">
                                {#if editingFileId === file.id}
                                    <form class="flex h-full flex-col bg-text p-4" onsubmit={(event) => { event.preventDefault(); saveMetadata(file); }}>
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <label for={`file-metadata-${file.id}`} class="text-[10px] font-bold tracking-wider text-faint uppercase">Edit JSON metadata</label>
                                            <button type="button" class="grid h-7 w-7 place-items-center bg-surface-raised/10 p-0 text-faint shadow-none hover:bg-surface-raised/15 hover:text-white" aria-label="Close metadata editor" onclick={cancelEdit}>
                                                <X class="h-3.5 w-3.5" strokeWidth={2} />
                                            </button>
                                        </div>
                                        <textarea
                                            id={`file-metadata-${file.id}`}
                                            class="min-h-0 flex-1 resize-none border-control bg-text font-mono text-[11px] leading-5 text-faint placeholder:text-muted focus:border-brand focus:ring-brand/20"
                                            bind:value={editMetadataText}
                                            spellcheck="false"
                                            placeholder={'{\n  "purpose": "release-notes"\n}'}
                                        ></textarea>
                                        {#if editMetadataError || metadataForm.errors.metadata}<span class="mt-1 text-[10px] text-danger">{editMetadataError || metadataForm.errors.metadata}</span>{/if}
                                        <div class="mt-3 flex justify-end gap-2">
                                            <button type="button" class="border border-control bg-text px-3 py-1.5 text-[10px] text-faint shadow-none hover:bg-text" onclick={cancelEdit}>Cancel</button>
                                            <button type="submit" class="px-3 py-1.5 text-[10px]" disabled={metadataForm.processing}>{metadataForm.processing ? 'Saving…' : 'Save metadata'}</button>
                                        </div>
                                    </form>
                                {:else if metadataPreviewFileId === file.id}
                                    <div class="flex h-full flex-col bg-text p-4">
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <span class="flex items-center gap-2 text-[9px] font-bold tracking-wider text-faint uppercase">
                                                <Braces class="h-3.5 w-3.5" strokeWidth={2} />
                                                JSON metadata
                                            </span>
                                            <button type="button" class="grid h-7 w-7 place-items-center bg-surface-raised/10 p-0 text-faint shadow-none hover:bg-surface-raised/15 hover:text-white" title="Edit metadata" aria-label={`Edit metadata for ${file.name}`} onclick={() => editFile(file)}>
                                                <Pencil class="h-3.5 w-3.5" strokeWidth={2} />
                                            </button>
                                        </div>
                                        <pre class="min-h-0 flex-1 overflow-auto rounded-lg border border-white/10 bg-black/20 p-3 font-mono text-[11px] leading-5 whitespace-pre-wrap text-faint">{JSON.stringify(file.metadata ?? {}, null, 2)}</pre>
                                    </div>
                                {:else if file.preview_type === 'image'}
                                    <img src={file.urls.preview} alt={file.name} class="h-full w-full object-contain" loading="lazy" />
                                {:else if file.preview_type === 'video'}
                                    <!-- svelte-ignore a11y_media_has_caption -->
                                    <video src={file.urls.preview} class="h-full w-full bg-black object-contain" controls preload="metadata" playsinline aria-label={`Preview ${file.name}`}></video>
                                {:else}
                                    <div class="grid h-full place-items-center bg-gradient-to-br {placeholderTheme(kind)}">
                                        <div class="text-center">
                                            {#if kind === 'pdf'}
                                                <FileText class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'audio'}
                                                <FileMusic class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'document'}
                                                <FileText class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'spreadsheet'}
                                                <FileSpreadsheet class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'text'}
                                                <FileText class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'archive'}
                                                <FileArchive class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'image-file'}
                                                <FileImage class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else if kind === 'video-file'}
                                                <FilePlay class="mx-auto h-14 w-14" strokeWidth={1.35} />
                                            {:else}
                                                <span class="block max-w-44 truncate font-mono text-2xl font-black tracking-tight">{extensionLabel(file)}</span>
                                            {/if}
                                            {#if kind !== 'extension'}<span class="mt-2 block font-mono text-[10px] font-bold tracking-widest opacity-70">{extensionLabel(file)}</span>{/if}
                                        </div>
                                    </div>
                                {/if}

                                {#if editingFileId !== file.id && metadataPreviewFileId !== file.id}
                                    <span class="absolute top-3 left-3 rounded-md px-2 py-1 font-mono text-[9px] font-bold tracking-wide ring-1 {badgeTheme(kind)}">
                                        {extensionLabel(file)}
                                    </span>
                                {/if}
                            </div>

                            <div class="p-4">
                                <div class="min-w-0">
                                    <h4 class="truncate text-sm font-bold text-text" title={file.name}>{file.name}</h4>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-faint">
                                        <span>{formatBytes(file.size)}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{formatDateTime(file.created_at)}</span>
                                    </div>
                                </div>

                                {#if confirmingDeleteId === file.id}
                                    <div class="mt-4 rounded-md border border-danger bg-danger/10 p-3">
                                        <p class="text-[11px] font-semibold text-danger">Delete this file permanently?</p>
                                        <div class="mt-2 flex justify-end gap-2">
                                            <button type="button" class="secondary px-3 py-1.5 text-[10px]" disabled={deletingFileId === file.id} onclick={() => confirmingDeleteId = null}>Cancel</button>
                                            <button type="button" class="bg-danger px-3 py-1.5 text-[10px] text-white hover:bg-danger" disabled={deletingFileId === file.id} onclick={() => deleteFile(file)}>
                                                {deletingFileId === file.id ? 'Deleting…' : 'Confirm delete'}
                                            </button>
                                        </div>
                                    </div>
                                {:else}
                                    <div class="mt-4 flex items-center gap-2 border-t border-border-subtle pt-3">
                                        <button
                                            type="button"
                                            aria-pressed={metadataPreviewFileId === file.id}
                                            class="secondary min-w-0 flex-1 px-3 py-2 text-[10px] {metadataPreviewFileId === file.id ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}"
                                            onclick={() => toggleMetadata(file)}
                                        >
                                            <Braces class="mr-1.5 h-3.5 w-3.5" strokeWidth={2} />
                                            Metadata
                                        </button>
                                        <a href={file.urls.download} class="secondary grid h-8 w-8 shrink-0 place-items-center p-0" title={`Download ${file.name}`} aria-label={`Download ${file.name}`}>
                                            <Download class="h-3.5 w-3.5" strokeWidth={2} />
                                        </a>
                                        <button type="button" class="grid h-8 w-8 shrink-0 place-items-center bg-danger/10 p-0 text-danger shadow-none hover:bg-danger/10" title="Delete file" aria-label={`Delete ${file.name}`} onclick={() => confirmDelete(file)}>
                                            <Trash2 class="h-3.5 w-3.5" strokeWidth={2} />
                                        </button>
                                    </div>
                                {/if}
                            </div>
                        </article>
                    {/each}
                </div>
            {:else}
                <div class="card grid min-h-64 place-items-center border-dashed bg-surface-sunken/50 px-6 py-10 text-center">
                    <div>
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-md bg-surface-raised text-faint shadow-sm ring-1 ring-border">
                            <Files class="h-6 w-6" strokeWidth={1.6} />
                        </span>
                        <p class="mt-4 text-sm font-bold text-text">No files attached yet</p>
                        <p class="mt-1 text-xs text-faint">Upload any file and optionally pair it with a JSON metadata object.</p>
                        <button type="button" class="mt-4 px-4 py-2 text-xs" onclick={() => showUpload = true}><Upload class="mr-2 h-3.5 w-3.5" />Upload first file</button>
                    </div>
                </div>
            {/if}
        </section>
    {:else}
        <section id="assets-panel" role="tabpanel">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-text">Generated app assets</h3>
                    <p class="mt-1 text-[11px] text-muted">Upload one source image and download platform-ready PNG variants.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="secondary px-3 py-2 text-xs" onclick={refresh}>
                        <RefreshCw class="mr-2 h-3.5 w-3.5" strokeWidth={2} />
                        Refresh
                    </button>
                    {#if hasPendingVariants}
                        <button type="button" class="px-3 py-2 text-xs" disabled={generating} onclick={regenerate}>
                            {#if generating}<LoaderCircle class="mr-2 inline h-3.5 w-3.5 animate-spin" strokeWidth={2} />{:else}<Sparkles class="mr-2 inline h-3.5 w-3.5" strokeWidth={2} />{/if}
                            Generate missing
                        </button>
                    {/if}
                </div>
            </div>

            <div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="App asset types">
                {#each mediaLibrary as asset}
                    <button
                        type="button"
                        role="tab"
                        aria-selected={selectedAsset?.collection === asset.collection}
                        class="chip {selectedAsset?.collection === asset.collection ? 'border-brand-soft-line bg-brand-soft text-brand' : 'border-border bg-surface-raised text-muted hover:border-brand-soft-line hover:bg-brand-soft hover:text-brand'}"
                        onclick={() => selectedAssetCollection = asset.collection}
                    >
                        {asset.label}
                        <span class="ml-1 rounded-full bg-surface-raised/80 px-1.5 py-0.5 font-mono text-[9px] font-bold text-current ring-1 ring-current/10">
                            {readyCount(asset)}/{variantCount(asset)}
                        </span>
                    </button>
                {/each}
            </div>

            {#if selectedAsset}
                <section class="card overflow-hidden p-0">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border-subtle px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-md bg-brand-soft text-brand">
                                <ImageIcon class="h-4.5 w-4.5" strokeWidth={1.8} />
                            </span>
                            <div>
                                <h3 class="text-sm font-bold">{selectedAsset.label}</h3>
                                <p class="mt-0.5 text-xs text-muted">{selectedAsset.description}</p>
                            </div>
                        </div>

                        <label for={`media-${selectedAsset.collection}`} class="secondary cursor-pointer px-3 py-2 text-xs">
                            {#if uploading === selectedAsset.collection}
                                <LoaderCircle class="mr-2 h-4 w-4 animate-spin" strokeWidth={2} />
                                Uploading…
                            {:else}
                                <Upload class="mr-2 h-4 w-4" strokeWidth={2} />
                                {selectedAsset.source ? 'Replace source' : 'Upload source'}
                            {/if}
                        </label>
                        <input
                            id={`media-${selectedAsset.collection}`}
                            class="hidden"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            disabled={uploading !== null}
                            onchange={(event) => upload(selectedAsset.collection, event)}
                        />
                    </div>

                    <div class="grid gap-0 lg:grid-cols-[280px_minmax(0,1fr)]">
                        <aside class="border-b border-border-subtle bg-surface-sunken/60 p-5 lg:border-r lg:border-b-0">
                            {#if selectedAsset.source}
                                <div class="grid min-h-56 place-items-center overflow-hidden rounded-md border border-border bg-surface-raised p-5">
                                    <img
                                        src={selectedAsset.source.preview_url}
                                        alt={`${selectedAsset.label} source`}
                                        class={selectedAsset.collection === 'app_icon'
                                            ? 'aspect-square max-h-44 w-full max-w-44 rounded-[22%] object-cover shadow-lg shadow-slate-300/60'
                                            : 'max-h-64 w-auto max-w-full rounded-md object-contain shadow-lg shadow-slate-300/60'}
                                    />
                                </div>
                                <div class="mt-4">
                                    <p class="truncate text-xs font-semibold text-text">{selectedAsset.source.name}</p>
                                    <div class="mt-1 flex items-center justify-between gap-3 text-[11px] text-muted">
                                        <span>{formatBytes(selectedAsset.source.size)}</span>
                                        <span>{readyCount(selectedAsset)} / {variantCount(selectedAsset)} ready</span>
                                    </div>
                                    <a href={selectedAsset.source.download_url} class="secondary mt-3 w-full py-2 text-xs">
                                        <Download class="mr-2 h-3.5 w-3.5" strokeWidth={2} />
                                        Download original
                                    </a>
                                </div>
                            {:else}
                                <div class="grid min-h-56 place-items-center rounded-md border border-dashed border-control bg-surface-raised p-6 text-center">
                                    <div>
                                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-md bg-border-subtle text-faint">
                                            <ImageIcon class="h-5 w-5" strokeWidth={1.8} />
                                        </span>
                                        <p class="mt-3 text-sm font-semibold">No source image</p>
                                        <p class="mt-1 text-xs leading-5 text-muted">Upload an image to generate every listed size automatically.</p>
                                    </div>
                                </div>
                            {/if}
                            <p class="mt-4 rounded-md bg-brand-soft px-3.5 py-3 text-[11px] leading-5 text-brand">{selectedAsset.recommendation}</p>
                        </aside>

                        <div class="space-y-6 p-5">
                            {#each selectedAsset.platforms as platform}
                                <div>
                                    <div class="mb-3 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2.5">
                                            <h4 class="text-sm font-bold">{platform.label}</h4>
                                            <span class="rounded-full bg-border-subtle px-2 py-0.5 text-[10px] font-semibold text-muted">{platform.variants.length} sizes</span>
                                        </div>
                                        <div class="hidden items-center gap-2 text-[10px] font-medium text-faint sm:flex" aria-label="Previews use a relative size scale">
                                            <span>Smaller</span>
                                            <span class="relative block h-px w-14 bg-border">
                                                <span class="absolute -top-0.5 left-0 h-1.5 w-1.5 rounded-full bg-control"></span>
                                                <span class="absolute -top-1 right-0 h-2.5 w-2.5 rounded-full bg-brand"></span>
                                            </span>
                                            <span>Larger</span>
                                        </div>
                                    </div>
                                    <div class="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2 xl:grid-cols-3">
                                        {#each platform.variants as variant}
                                            <article class="group flex min-h-52 min-w-0 flex-col bg-surface-raised p-3">
                                                <div class="variant-stage relative flex h-36 items-end justify-center overflow-hidden rounded-md border border-border-subtle bg-surface-sunken pb-3">
                                                    <span class="absolute top-2 left-2 rounded-md bg-surface-raised/90 px-1.5 py-1 font-mono text-[9px] font-semibold text-faint shadow-sm ring-1 ring-border/70">
                                                        {variant.width} × {variant.height}
                                                    </span>
                                                    {#if variant.ready || selectedAsset.source}
                                                        <img
                                                            src={variant.ready ? variant.preview_url : selectedAsset.source.preview_url}
                                                            alt=""
                                                            style={previewStyle(selectedAsset, variant)}
                                                            class={selectedAsset.collection === 'app_icon'
                                                                ? 'shrink-0 rounded-[22%] object-cover shadow-md shadow-slate-300/60 ring-1 ring-black/5 transition-transform duration-200 group-hover:scale-105'
                                                                : 'shrink-0 rounded-md object-cover shadow-md shadow-slate-300/60 ring-1 ring-black/5 transition-transform duration-200 group-hover:scale-105'}
                                                        />
                                                    {:else}
                                                        <span
                                                            style={previewStyle(selectedAsset, variant)}
                                                            class={selectedAsset.collection === 'app_icon'
                                                                ? 'grid shrink-0 place-items-center rounded-[22%] border border-dashed border-control bg-surface-raised/70 text-faint'
                                                                : 'grid shrink-0 place-items-center rounded-md border border-dashed border-control bg-surface-raised/70 text-faint'}
                                                        >
                                                            <ImageIcon class="h-3.5 w-3.5" strokeWidth={1.8} />
                                                        </span>
                                                    {/if}
                                                    <span class="absolute right-3 bottom-2 left-3 h-px bg-border"></span>
                                                </div>
                                                <div class="mt-3 flex min-w-0 items-start gap-2">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-xs font-semibold text-text" title={variant.label}>{variant.label}</p>
                                                        <p class="mt-1 flex items-center gap-1.5 text-[10px] font-medium {variant.ready ? 'text-ok' : 'text-faint'}">
                                                            <span class="h-1.5 w-1.5 rounded-full {variant.ready ? 'bg-ok/100' : selectedAsset.source ? 'bg-amber-400' : 'bg-control'}"></span>
                                                            {variant.ready ? 'Ready' : selectedAsset.source ? 'Generating' : 'Needs source'}
                                                        </p>
                                                    </div>
                                                    {#if variant.ready}
                                                        <a
                                                            href={variant.download_url}
                                                            title={`Download ${variant.label}`}
                                                            aria-label={`Download ${variant.label}`}
                                                            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-ok/10 text-ok transition hover:bg-ok/10"
                                                        >
                                                            <Download class="h-3.5 w-3.5" strokeWidth={2.2} />
                                                        </a>
                                                    {:else}
                                                        <span
                                                            class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-border-subtle text-faint"
                                                            title={selectedAsset.source ? 'Conversion is queued' : 'Upload a source image'}
                                                        >
                                                            {#if selectedAsset.source}<LoaderCircle class="h-3.5 w-3.5 animate-spin" strokeWidth={2} />{:else}<span class="h-1.5 w-1.5 rounded-full bg-control"></span>{/if}
                                                        </span>
                                                    {/if}
                                                </div>
                                            </article>
                                        {/each}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </div>
                </section>
            {/if}
        </section>
    {/if}

    {#if showUpload}
        <div class="modal-backdrop" role="presentation" onclick={(event) => event.target === event.currentTarget && closeUpload()}>
            <form class="modal max-w-xl" onsubmit={(event) => { event.preventDefault(); uploadFile(); }}>
                <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-text">Upload file</h2>
                        <p class="mt-1 text-sm text-muted">Attach a file to {app.name}. Its filename becomes the download key.</p>
                    </div>
                    <button type="button" class="ghost -mr-2 p-2" aria-label="Close upload" disabled={fileForm.processing} onclick={closeUpload}>
                        <X class="h-4 w-4" strokeWidth={2} />
                    </button>
                </div>

                <div class="space-y-5 p-6">
                    <div>
                        <span class="label">File</span>
                        <label for="app-file-upload" class="group block cursor-pointer rounded-md border border-dashed border-control bg-surface-sunken px-5 py-6 text-center transition hover:border-brand hover:bg-brand-soft/40">
                            <span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-surface-raised text-muted shadow-sm ring-1 ring-border transition group-hover:text-brand group-hover:ring-brand-soft-line">
                                <Upload class="h-4.5 w-4.5" strokeWidth={1.8} />
                            </span>
                            <span class="mt-3 block truncate text-sm font-semibold text-text">{fileForm.file?.name ?? 'Choose a file'}</span>
                            <span class="mt-1 block text-xs text-muted">
                                {fileForm.file ? formatBytes(fileForm.file.size) : `Any type, up to ${formatBytes(fileLibrary.max_file_size)}`}
                            </span>
                        </label>
                        <input id="app-file-upload" class="hidden" type="file" bind:this={fileInput} onchange={selectFile} />
                        {#if fileForm.errors.file}<span class="error">{fileForm.errors.file}</span>{/if}
                    </div>

                    <label>
                        <span class="label">Metadata <span class="font-normal text-faint">(optional JSON object)</span></span>
                        <textarea
                            class="min-h-36 font-mono text-xs leading-5"
                            bind:value={fileMetadataText}
                            spellcheck="false"
                            placeholder={'{\n  "purpose": "release-notes",\n  "version": "2.4.0"\n}'}
                        ></textarea>
                        {#if fileMetadataError || fileForm.errors.metadata}<span class="error">{fileMetadataError || fileForm.errors.metadata}</span>{/if}
                    </label>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-border-subtle bg-surface-sunken/60 px-6 py-4">
                    <button type="button" class="secondary px-4 py-2 text-xs" disabled={fileForm.processing} onclick={closeUpload}>Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs" disabled={!fileForm.file || fileForm.processing}>
                        {#if fileForm.processing}<LoaderCircle class="mr-2 h-3.5 w-3.5 animate-spin" strokeWidth={2} />{:else}<Upload class="mr-2 h-3.5 w-3.5" strokeWidth={2} />{/if}
                        {fileForm.processing ? 'Uploading…' : 'Upload file'}
                    </button>
                </div>
            </form>
        </div>
    {/if}
</AppLayout>

<style>
    .variant-stage {
        background-image:
            linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
            linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
        background-size: 12px 12px;
    }
</style>
