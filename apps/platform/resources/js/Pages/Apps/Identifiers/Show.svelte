<script>
    // Placeholder page — the full identifier detail UI is implemented in a later task.
    export let auth;
    export let app;
    export let identifier = null;
    export let credentialTypes = [];
    export let buildProfiles = [];
    export let active = 'identifiers';
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
</script>

<div>
    <h1>{app?.name} — {identifier?.name}</h1>
</div>
