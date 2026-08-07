const ANSI_SOURCE = [
    '(?:\\u001B\\]|\\u009D).*?(?:\\u0007|\\u001B\\\\|\\u009C)',
    '(?:\\u001BP|\\u0090).*?(?:\\u001B\\\\|\\u009C)',
    '(?:\\u001B[\\^_]|[\\u0098\\u009E\\u009F]).*?(?:\\u001B\\\\|\\u009C)',
    '(?:\\u001B\\[|\\u009B)[0-?]*[ -/]*[@-~]',
    '\\u001B[@-_]',
].join('|');

const ANSI_PATTERN = new RegExp(ANSI_SOURCE, 'gs');
const ANSI_COLORS = [
    '#0f172a', '#fb7185', '#34d399', '#fbbf24', '#60a5fa', '#c084fc', '#22d3ee', '#e2e8f0',
    '#64748b', '#fda4af', '#6ee7b7', '#fde68a', '#93c5fd', '#d8b4fe', '#67e8f9', '#f8fafc',
];

const STEP_START = /^--- step:\s+(.+?)\s+---\s*$/;
const STEP_FINISH = /^--- step:\s+(.+?)\s+finished \(exit (-?\d+)\)\s+---\s*$/;

export const stripAnsi = (value) => typeof value === 'string' ? value.replace(ANSI_PATTERN, '') : '';

export const normalizeLogText = (value) => value
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/\u0008/g, '');

const defaultAnsiState = () => ({
    background: null,
    bold: false,
    dim: false,
    foreground: null,
    hidden: false,
    inverse: false,
    italic: false,
    strike: false,
    underline: false,
});

const ansi256Color = (input) => {
    const index = Math.max(0, Math.min(255, Number(input) || 0));

    if (index < 16) return ANSI_COLORS[index];

    if (index < 232) {
        const value = index - 16;
        const levels = [0, 95, 135, 175, 215, 255];
        const red = levels[Math.floor(value / 36)];
        const green = levels[Math.floor((value % 36) / 6)];
        const blue = levels[value % 6];

        return `rgb(${red} ${green} ${blue})`;
    }

    const gray = 8 + (index - 232) * 10;

    return `rgb(${gray} ${gray} ${gray})`;
};

const rgbColor = (red, green, blue) => {
    const clamp = (channel) => Math.max(0, Math.min(255, Number(channel) || 0));

    return `rgb(${clamp(red)} ${clamp(green)} ${clamp(blue)})`;
};

const applyExtendedColor = (state, parameters, index, target) => {
    const mode = parameters[index + 1];

    if (mode === 5 && parameters[index + 2] !== undefined) {
        state[target] = ansi256Color(parameters[index + 2]);
        return index + 2;
    }

    if (mode === 2 && parameters[index + 4] !== undefined) {
        state[target] = rgbColor(parameters[index + 2], parameters[index + 3], parameters[index + 4]);
        return index + 4;
    }

    return index;
};

const applySgr = (state, source) => {
    const normalized = source.replace(/::/g, ':').replace(/:/g, ';');
    const parameters = normalized === ''
        ? [0]
        : normalized.split(';').map((parameter) => parameter === '' ? 0 : Number(parameter));

    for (let index = 0; index < parameters.length; index += 1) {
        const code = Number.isFinite(parameters[index]) ? parameters[index] : 0;

        if (code === 0) Object.assign(state, defaultAnsiState());
        else if (code === 1) state.bold = true;
        else if (code === 2) state.dim = true;
        else if (code === 3) state.italic = true;
        else if (code === 4) state.underline = true;
        else if (code === 7) state.inverse = true;
        else if (code === 8) state.hidden = true;
        else if (code === 9) state.strike = true;
        else if (code === 22) {
            state.bold = false;
            state.dim = false;
        } else if (code === 23) state.italic = false;
        else if (code === 24) state.underline = false;
        else if (code === 27) state.inverse = false;
        else if (code === 28) state.hidden = false;
        else if (code === 29) state.strike = false;
        else if (code >= 30 && code <= 37) state.foreground = ANSI_COLORS[code - 30];
        else if (code === 38) index = applyExtendedColor(state, parameters, index, 'foreground');
        else if (code === 39) state.foreground = null;
        else if (code >= 40 && code <= 47) state.background = ANSI_COLORS[code - 40];
        else if (code === 48) index = applyExtendedColor(state, parameters, index, 'background');
        else if (code === 49) state.background = null;
        else if (code >= 90 && code <= 97) state.foreground = ANSI_COLORS[code - 90 + 8];
        else if (code >= 100 && code <= 107) state.background = ANSI_COLORS[code - 100 + 8];
    }
};

const ansiStyle = (state) => {
    let foreground = state.foreground;
    let background = state.background;

    if (state.inverse) {
        [foreground, background] = [background || '#020617', foreground || '#cbd5e1'];
    }

    const declarations = [];
    if (foreground) declarations.push(`color:${foreground}`);
    if (background) declarations.push(`background-color:${background}`);
    if (state.bold) declarations.push('font-weight:600');
    if (state.dim) declarations.push('opacity:0.65');
    if (state.italic) declarations.push('font-style:italic');
    if (state.hidden) declarations.push('visibility:hidden');

    const decorations = [];
    if (state.underline) decorations.push('underline');
    if (state.strike) decorations.push('line-through');
    if (decorations.length) declarations.push(`text-decoration:${decorations.join(' ')}`);

    return declarations.join(';');
};

export const parseAnsiSegments = (value, initialState = null, maxSegments = 10000) => {
    const state = initialState ? { ...initialState } : defaultAnsiState();
    const matcher = new RegExp(ANSI_SOURCE, 'gs');
    const segments = [];
    const plainParts = [];
    let simplified = false;
    let offset = 0;

    const pushText = (text) => {
        const normalized = normalizeLogText(text);
        if (!normalized) return;

        plainParts.push(normalized);
        if (simplified) return;

        const style = ansiStyle(state);
        const previous = segments.at(-1);

        if (previous?.style === style) previous.text += normalized;
        else segments.push({ style, text: normalized });

        if (segments.length > maxSegments) {
            simplified = true;
            segments.length = 0;
        }
    };

    for (const match of value.matchAll(matcher)) {
        pushText(value.slice(offset, match.index));

        const sgr = match[0].match(/^(?:\u001B\[|\u009B)([0-9;:]*)m$/s);
        if (sgr) applySgr(state, sgr[1]);

        offset = match.index + match[0].length;
    }

    pushText(value.slice(offset));

    const plainText = plainParts.join('');

    return {
        plainText,
        segments: simplified ? [{ style: '', text: plainText }] : segments,
        simplified,
        state,
    };
};

const trimBoundaryLines = (lines) => {
    const trimmed = [...lines];

    while (trimmed.length && stripAnsi(trimmed[0]).trim() === '') trimmed.shift();
    while (trimmed.length && stripAnsi(trimmed.at(-1)).trim() === '') trimmed.pop();

    return trimmed;
};

export const parseBuildLog = (value) => {
    if (!value) return [];

    const sections = [];
    let current = null;
    let sequence = 0;

    const beginOutput = () => ({
        id: `output-${sequence++}`,
        kind: 'output',
        name: 'Build output',
        status: 'output',
        exitCode: null,
        lines: [],
    });

    const flush = () => {
        if (!current) return;

        const lines = trimBoundaryLines(current.lines);

        if (lines.length || current.kind === 'step') {
            sections.push({
                ...current,
                lines,
                lineCount: lines.length,
            });
        }

        current = null;
    };

    for (const rawLine of value.split(/\r?\n/)) {
        const plainLine = stripAnsi(rawLine);
        const finish = plainLine.match(STEP_FINISH);

        if (finish) {
            if (current?.kind === 'step' && current.name === finish[1]) {
                current.exitCode = Number(finish[2]);
                current.status = current.exitCode === 0 ? 'success' : 'failed';
                flush();
            } else {
                if (!current) current = beginOutput();
                current.lines.push(rawLine);
            }

            continue;
        }

        const start = plainLine.match(STEP_START);

        if (start) {
            flush();
            current = {
                id: `step-${sequence++}`,
                kind: 'step',
                name: start[1],
                status: 'running',
                exitCode: null,
                lines: [],
            };
            continue;
        }

        if (!current) current = beginOutput();
        current.lines.push(rawLine);
    }

    flush();

    return sections.map((section, index) => ({ ...section, order: index + 1 }));
};

export const filterLogSections = (sections, query) => {
    const normalizedQuery = query.trim().toLocaleLowerCase();

    if (!normalizedQuery) return sections;

    return sections.flatMap((section) => {
        const lines = section.lines.filter((line) => stripAnsi(line).toLocaleLowerCase().includes(normalizedQuery));

        return lines.length ? [{
            ...section,
            lines,
            lineCount: lines.length,
        }] : [];
    });
};
