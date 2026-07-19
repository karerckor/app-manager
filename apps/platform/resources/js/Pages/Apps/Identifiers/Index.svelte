<script>
    // Placeholder page — the full identifiers list UI is implemented in a later task.
    export let auth;
    export let app;
    export let identifiers = [];
    export let credentialTypes = [];
    export let pipelines = [];
    export let buildProfiles = [];
    export let active = 'identifiers';
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
</script>

<div>
    <h1>{app?.name} — Identifiers</h1>
</div>
