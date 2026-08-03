// Mirrors App\Enums\TagAnimation. Tokens are the canonical lowercase values
// stored at index 2 of a tag tuple; classes are what actually animates.
// The mapping lives only here because tags are never rendered server-side.
export const TAG_ANIMATIONS = {
    pulse: { label: 'Pulse', class: 'animate-pulse' },
    ping: { label: 'Ping', class: 'animate-ping' },
    flash: { label: 'Flash', class: 'tag-anim-flash' },
    tada: { label: 'Tada', class: 'tag-anim-tada' },
    heartbeat: { label: 'Heartbeat', class: 'tag-anim-heartbeat' },
};

export const TAG_ANIMATION_TOKENS = Object.keys(TAG_ANIMATIONS);

export function animationClass(token) {
    return TAG_ANIMATIONS[token]?.class ?? '';
}
