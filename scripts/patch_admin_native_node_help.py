#!/usr/bin/env python3
"""Guide the original Umi native node forms without replacing their lifecycle.

A single maintained webpack module supplies bilingual React help. Explicit
field markers are added at controlled native inputs. Each owned edit is fenced
and reversible. --check verifies both built bundles; --input/--output support
isolated review without touching public assets.
"""
import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = Path(__file__).with_name('admin_native_node_help.js')
BUNDLES = ROOT / 'public/assets/admin'
HELPER_ID = 'irboard/native-node-help'
# module: (class, actual React import, protocol/section)
TARGETS = {
    'H9LU': [('p', 'h.a', 'shadowsocks')],
    '3XVG': [('g', 'd.a', 'dns'), ('v', 'd.a', 'rules'), ('b', 'd.a', 'tls'), ('E', 'd.a', 'vmess')],
    'ykC2': [('m', 'f.a', 'trojan')],
    'uzXD': [('V', 'y.a', 'hysteria'), ('U', 'y.a', 'tls'),
             ('EncryptionSettings', 'y.a', 'encryption'), ('z', 'y.a', 'vless'),
             ('wTuic', 'y.a', 'tuic'), ('wAnyTLS', 'y.a', 'anytls'),
             ('wMdns', 'y.a', 'mdns'), ('wTrusttunnel', 'y.a', 'trusttunnel'),
             ('wV2node', 'y.a', 'v2node')],
}
MODULE = re.compile(r'^    ("[^"]+"|[A-Za-z0-9_]+): function\(', re.M)
CHANGE = re.compile(
    r'onChange:\s*(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>\s*(?:\{\s*)?this\.'
    r'(?P<method>formChange|changeServer|change|setObfsSettings)\('
    r'\s*(?:[A-Za-z_$][\w$]*\s*,\s*)?["\'](?P<field>[A-Za-z0-9_]+)["\']'
)


def matching(text, start):
    """Find a JS closing delimiter, skipping strings, comments and regexes."""
    opening = text[start]
    closing = {'{': '}', '(': ')', '[': ']'}[opening]
    stack = [closing]
    index = start + 1
    previous = opening
    while index < len(text):
        char = text[index]
        if char in '\"\'`':
            quote = char
            index += 1
            while index < len(text):
                if text[index] == '\\':
                    index += 2
                elif text[index] == quote:
                    index += 1
                    break
                else:
                    index += 1
            previous = 'literal'
            continue
        if text.startswith('//', index):
            end = text.find('\n', index + 2)
            index = len(text) if end < 0 else end + 1
            continue
        if text.startswith('/*', index):
            end = text.find('*/', index + 2)
            if end < 0:
                raise RuntimeError('Unclosed JavaScript comment')
            index = end + 2
            continue
        if char == '/' and previous in ('(', '[', '{', '=', ':', ',', '!', '?', ';', '&', '|'):
            index += 1
            bracket = False
            while index < len(text):
                if text[index] == '\\':
                    index += 2
                    continue
                if text[index] == '[':
                    bracket = True
                elif text[index] == ']':
                    bracket = False
                elif text[index] == '/' and not bracket:
                    index += 1
                    while index < len(text) and text[index].isalpha():
                        index += 1
                    break
                index += 1
            previous = 'literal'
            continue
        if char in '{([':
            stack.append({'{': '}', '(': ')', '[': ']'}[char])
        elif char in '})]':
            if not stack or stack.pop() != char:
                raise RuntimeError('Unexpected JavaScript delimiter near ' + text[max(0, index - 50):index + 30])
            if not stack:
                return index
        if not char.isspace():
            previous = char
        index += 1
    raise RuntimeError('Unclosed JavaScript delimiter')


def strip_owned(text):
    text = re.sub(r'/\*__INH_MODULE_START__\*/.*?/\*__INH_MODULE_END__\*/', '', text, flags=re.S)
    text = re.sub(r'/\*__INH_REQUIRE_START__\*/.*?/\*__INH_REQUIRE_END__\*/', '', text, flags=re.S)
    text = re.sub(r'/\*__INH_FIELD_START__\*/.*?/\*__INH_FIELD_END__\*/', '', text, flags=re.S)
    text = re.sub(r'/\*__INH_WRAP_OPEN_START__\*/.*?/\*__INH_WRAP_OPEN_END__\*/', '', text, flags=re.S)
    text = re.sub(r'/\*__INH_WRAP_CLOSE_START__\*/.*?/\*__INH_WRAP_CLOSE_END__\*/', '', text, flags=re.S)
    return text


def guide_data():
    source = SOURCE.read_text(encoding='utf-8')
    raw = source.split('var imnNativeNodeGuides = ', 1)[1].split(';\nvar imnNativeHelpOwnerIds', 1)[0]
    return json.loads(raw)


def patch_class(block, class_name, react, protocol, guides):
    anchor = re.search(r'\bclass ' + re.escape(class_name) + r' extends ' + re.escape(react) + r'\.Component\s*\{', block)
    if not anchor:
        raise RuntimeError('Missing native class ' + class_name)
    start = anchor.end() - 1
    end = matching(block, start)
    source = block[anchor.start():end + 1]
    fields = set()
    specific = guides['protocols'].get(protocol, {})

    def annotate(match):
        field = match.group('field')
        if match.group('method') == 'setObfsSettings':
            field = 'obfs.' + field
        if field not in specific and field not in guides['common']:
            raise RuntimeError(f'Missing guide for {protocol}/{field}')
        fields.add(field)
        return ('/*__INH_FIELD_START__*/"data-node-field":' + json.dumps(field)
                + ',/*__INH_FIELD_END__*/' + match.group(0))

    source = CHANGE.sub(annotate, source)
    # The installation command is a native read-only output, without onChange.
    if protocol == 'v2node':
        command = re.compile(r'value:\s*e\.install_command\b')
        if len(command.findall(source)) != 1:
            raise RuntimeError('Native V2node installation output anchor changed')
        source = command.sub(lambda match: '/*__INH_FIELD_START__*/"data-node-field":"install_command",/*__INH_FIELD_END__*/' + match.group(0), source)
    render = re.search(r'^            render\(\)\s*\{', source, re.M)
    if not render:
        raise RuntimeError('Missing native render method ' + class_name)
    render_start = render.end() - 1
    render_end = matching(source, render_start)
    body = source[render_start + 1:render_end]
    returned = re.search(r'\breturn\s+', body)
    if not returned:
        raise RuntimeError('Missing native return tree ' + class_name)
    expression_start = render_start + 1 + returned.end()
    expression_end = render_end
    while expression_end > expression_start and source[expression_end - 1].isspace():
        expression_end -= 1
    if source[expression_end - 1] == ';':
        expression_end -= 1
    prefix = '/*__INH_WRAP_OPEN_START__*/irNativeNodeHelp(' + react + ',/*__INH_WRAP_OPEN_END__*/'
    suffix = '/*__INH_WRAP_CLOSE_START__*/,' + json.dumps(protocol) + ',this)/*__INH_WRAP_CLOSE_END__*/'
    source = source[:expression_start] + prefix + source[expression_start:expression_end] + suffix + source[expression_end:]
    return block[:anchor.start()] + source + block[end + 1:], len(fields)


def patch_text(text):
    text = strip_owned(text)
    original = text
    guides = guide_data()
    declarations = list(MODULE.finditer(text))
    ranges = {}
    for index, match in enumerate(declarations):
        name = match.group(1).strip('"')
        ranges[name] = (match.start(), declarations[index + 1].start() if index + 1 < len(declarations) else len(text))
    edits = []
    for name, targets in TARGETS.items():
        if name not in ranges:
            raise RuntimeError('Missing native module ' + name)
        start, end = ranges[name]
        block = text[start:end]
        for class_name, react, protocol in targets:
            block, _ = patch_class(block, class_name, react, protocol, guides)
        strict = '        "use strict";'
        if block.count(strict) != 1:
            raise RuntimeError('Native module strict anchor changed: ' + name)
        require = ('/*__INH_REQUIRE_START__*/\n        var irNativeNodeHelp = n('
                   + json.dumps(HELPER_ID) + ').default;/*__INH_REQUIRE_END__*/')
        block = block.replace(strict, strict + require, 1)
        edits.append((start, end, block))
    for start, end, block in sorted(edits, reverse=True):
        text = text[:start] + block + text[end:]
    source = SOURCE.read_text(encoding='utf-8')
    module = ('/*__INH_MODULE_START__*/    ' + json.dumps(HELPER_ID)
              + ': function(e, t, n) {\n        "use strict";\n'
              + source + '\n        t.default = imnNativeHelp;\n    },\n/*__INH_MODULE_END__*/')
    anchor = '    uzXD: function(e, t, n) {'
    if text.count(anchor) != 1:
        raise RuntimeError('Native node manager module anchor changed')
    text = text.replace(anchor, module + anchor, 1)
    if strip_owned(text) != original:
        raise RuntimeError('Native guidance patch failed its reversibility check')
    return text


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--input', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if args.output and not args.input:
        parser.error('--output requires --input')
    paths = [args.input] if args.input else [BUNDLES / 'umi.js', BUNDLES / 'umi-fa.js']
    for path in paths:
        raw = path.read_bytes()
        result = patch_text(raw.decode('utf-8')).encode('utf-8')
        if args.check:
            if result != raw:
                raise SystemExit(path.name + ': native node guidance is not current')
        else:
            output = args.output or path
            if output != path or result != raw:
                output.write_bytes(result)
        print(path.name + ': native Umi node field guidance ready')


if __name__ == '__main__':
    main()
