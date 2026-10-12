import { esc } from '../format.js';

/** Tokens of a GraphQL document: block strings, strings, comments, punctuators, numbers and names. */
const TOKEN = /"""[\s\S]*?"""|"(?:\\.|[^"\\\n])*"|#[^\n]*|\.\.\.|[!$&():=@[\]{|}]|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|[_A-Za-z]\w*/g;
const KEYWORDS = ['query', 'mutation', 'subscription', 'fragment'];
const TONES = {
  keyword: 'text-brass font-semibold',
  type: 'text-brass',
  variable: 'text-warn',
  directive: 'text-bad',
  argument: 'text-muted',
  string: 'text-ok',
  number: 'text-warn',
  literal: 'text-warn',
  punctuation: 'text-muted',
  comment: 'text-muted italic',
};

const kindOf = (text) => (text[0] === '"' ? 'string' : text[0] === '#' ? 'comment' : /^-?\d/.test(text) ? 'number' : /^[_A-Za-z]/.test(text) ? 'name' : 'punct');

/** What a token is, from the tokens around it (comments left out). */
function tone(t, prev, next, braces, parens) {
  if (t.kind === 'comment' || t.kind === 'string' || t.kind === 'number') return t.kind;
  if (t.text === '$' || prev?.text === '$') return 'variable';
  if (t.text === '@' || prev?.text === '@') return 'directive';
  if (t.kind === 'punct') return 'punctuation';
  if (braces === 0 && KEYWORDS.includes(t.text)) return 'keyword';
  if (t.text === 'on' && next?.kind === 'name') return 'keyword';
  if (prev?.text === 'on' && prev.kind === 'name') return 'type';
  // A variable's type, in the operation's own parentheses.
  if (braces === 0 && parens > 0 && (prev?.text === ':' || prev?.text === '[')) return 'type';
  if (parens > 0 && ['true', 'false', 'null'].includes(t.text)) return 'literal';
  if (next?.text === ':') return 'argument';
  return null;
}

/** A GraphQL document as HTML, each token coloured by what it is; text it cannot read is kept as it is. */
export function graphqlHtml(text) {
  const source = String(text ?? '');
  const tokens = [...source.matchAll(TOKEN)].map((m) => ({ text: m[0], at: m.index, kind: kindOf(m[0]) }));
  const code = tokens.filter((t) => t.kind !== 'comment');
  let braces = 0;
  let parens = 0;
  let offset = 0;
  let out = '';
  for (const t of tokens) {
    const i = code.indexOf(t);
    if (t.text === '{') braces++;
    if (t.text === '}') braces--;
    if (t.text === '(') parens++;
    if (t.text === ')') parens--;
    const kind = i < 0 ? 'comment' : tone(t, code[i - 1], code[i + 1], braces, parens);
    out += esc(source.slice(offset, t.at)) + (kind ? `<span class="${TONES[kind]}">${esc(t.text)}</span>` : esc(t.text));
    offset = t.at + t.text.length;
  }
  return out + esc(source.slice(offset));
}
