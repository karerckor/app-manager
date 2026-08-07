import assert from 'node:assert/strict';
import test from 'node:test';
import { filterLogSections, parseAnsiSegments, parseBuildLog, stripAnsi } from '../../resources/js/Pages/Apps/Builds/build-logs.js';

test('stripAnsi removes colors and OSC hyperlinks without removing their labels', () => {
    const value = '\u001B[32mBuild passed\u001B[0m\n\u001B]8;;https://example.test\u001B\\Open report\u001B]8;;\u001B\\';

    assert.equal(stripAnsi(value), 'Build passed\nOpen report');
});

test('parseAnsiSegments renders classic, 256-color, and truecolor SGR styles', () => {
    const parsed = parseAnsiSegments([
        '\u001B[1;31mFailed\u001B[0m ',
        '\u001B[38;5;214mwarning\u001B[0m ',
        '\u001B[38;2;12;34;56mcustom\u001B[0m',
    ].join(''));

    assert.equal(parsed.segments[0].text, 'Failed');
    assert.match(parsed.segments[0].style, /color:#fb7185/);
    assert.match(parsed.segments[0].style, /font-weight:600/);
    assert.match(parsed.segments[2].style, /color:rgb\(255 175 0\)/);
    assert.match(parsed.segments[4].style, /color:rgb\(12 34 56\)/);
    assert.equal(parsed.plainText, 'Failed warning custom');
});

test('parseAnsiSegments falls back to one plain segment for highly fragmented logs', () => {
    const parsed = parseAnsiSegments('\u001B[31mred\u001B[0m plain \u001B[32mgreen', null, 1);

    assert.equal(parsed.simplified, true);
    assert.deepEqual(parsed.segments, [{ style: '', text: 'red plain green' }]);
});

test('parseBuildLog creates ordered sections from appmctl step markers', () => {
    const sections = parseBuildLog([
        'Preparing runner',
        '--- step: Install dependencies ---',
        '\u001B[36mnpm ci\u001B[0m',
        'added 42 packages',
        '--- step: Install dependencies finished (exit 0) ---',
        '--- step: Test ---',
        'Assertion failed',
        '--- step: Test finished (exit 1) ---',
    ].join('\n'));

    assert.deepEqual(sections.map(({ name, status, exitCode, lineCount }) => ({ name, status, exitCode, lineCount })), [
        { name: 'Build output', status: 'output', exitCode: null, lineCount: 1 },
        { name: 'Install dependencies', status: 'success', exitCode: 0, lineCount: 2 },
        { name: 'Test', status: 'failed', exitCode: 1, lineCount: 1 },
    ]);
    assert.match(sections[1].lines.join('\n'), /\u001B\[36m/);
});

test('parseBuildLog keeps an unfinished step running and filtering matches plain text', () => {
    const sections = parseBuildLog('--- step: Build ---\n\u001B[33mCompiling app\u001B[0m\nBundling assets');
    const filtered = filterLogSections(sections, 'compiling');

    assert.equal(sections[0].status, 'running');
    assert.equal(filtered.length, 1);
    assert.equal(filtered[0].lineCount, 1);
    assert.equal(stripAnsi(filtered[0].lines.join('\n')), 'Compiling app');
});

test('a short failed step keeps its plain text available to the direct renderer', () => {
    const sections = parseBuildLog([
        '--- step: Composer Install ---',
        'Composer could not find a composer.json file in /workspace',
        'To initialize a project, please create a composer.json file.',
        '--- step: Composer Install finished (exit 1) ---',
    ].join('\n'));
    const renderedLines = sections[0].lines.map((line) => parseAnsiSegments(line).plainText);

    assert.equal(sections[0].lineCount, 2);
    assert.deepEqual(renderedLines, [
        'Composer could not find a composer.json file in /workspace',
        'To initialize a project, please create a composer.json file.',
    ]);
});
