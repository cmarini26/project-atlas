#!/usr/bin/env node
/**
 * Design-system regression gate.
 *
 * The frontend has a large backlog of raw form controls and raw Tailwind
 * palette classes (see CM-107). Rather than block on that backlog, this
 * checks only the lines a change *adds*, so a PR can't introduce new
 * violations while the sweep is in progress.
 *
 *   node scripts/check-design-system.mjs --base <ref>   # CI: fail on new violations in <ref>...HEAD
 *   node scripts/check-design-system.mjs                 # local: list every violation in resources/js, never fails
 *
 * Escape hatch: put `ds-ignore` in a comment on the offending line or the
 * line above it.
 */
import { execSync } from 'node:child_process'
import { readFileSync, existsSync } from 'node:fs'

const PALETTE =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose'
const PROP =
  'bg|text|border|ring|divide|from|via|to|fill|stroke|outline|placeholder|caret|accent|decoration|shadow'

const RULES = [
  {
    id: 'raw-form-control',
    test: (line, file) =>
      file.startsWith('resources/js/Pages/') && /<(input|select|textarea)[\s/>]/.test(line),
    message: 'raw <input|select|textarea> in a Page — use Components/UI/Input|Select|Textarea',
  },
  {
    id: 'raw-palette-class',
    test: (line, file) =>
      !file.startsWith('resources/js/Components/UI/') &&
      !file.startsWith('resources/js/Components/Marketing/') &&
      (new RegExp(`\\b(${PROP})-(${PALETTE})-(50|[1-9]00|950)\\b`).test(line) ||
        /\bbg-white\b/.test(line)),
    message: 'raw Tailwind palette class — use a --color-* token from resources/css/app.css',
  },
  {
    id: 'atlas-title',
    test: (line) => /<title>[^<]*\bAtlas\b/.test(line),
    message: 'drop the "— Atlas" title suffix — the product is "The Clear Move" (CM-109)',
  },
]

const git = (cmd) => execSync(`git ${cmd}`, { encoding: 'utf8' })
const isTarget = (f) => /^resources\/js\/.*\.(vue|ts)$/.test(f) && !/\.(spec|test)\.ts$/.test(f)

function checkLine(file, lineNo, line, out) {
  if (/ds-ignore/.test(line)) return
  for (const rule of RULES) {
    if (rule.test(line, file)) out.push({ file, lineNo, ruleId: rule.id, message: rule.message, line: line.trim() })
  }
}

function runFullTree() {
  const files = git('ls-files resources/js').trim().split('\n').filter(isTarget)
  const out = []
  for (const file of files) {
    const lines = readFileSync(file, 'utf8').split('\n')
    let prev = ''
    lines.forEach((line, i) => {
      if (!/ds-ignore/.test(prev)) checkLine(file, i + 1, line, out)
      prev = line
    })
  }
  report(out, false)
}

function runDiff(base) {
  // --relative so paths are relative to CWD (scripts run from backend/), both
  // in the file list and when passed back as a pathspec.
  const files = git(`diff --name-only --relative --diff-filter=ACMR ${base}...HEAD`)
    .trim()
    .split('\n')
    .filter(isTarget)
  const out = []
  for (const file of files) {
    if (!existsSync(file)) continue
    const diff = git(`diff --unified=0 --relative ${base}...HEAD -- "${file}"`)
    let newLineNo = 0
    let prevAdded = ''
    for (const dl of diff.split('\n')) {
      const hunk = dl.match(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/)
      if (hunk) {
        newLineNo = parseInt(hunk[1], 10)
        prevAdded = ''
        continue
      }
      if (dl.startsWith('+') && !dl.startsWith('+++')) {
        const line = dl.slice(1)
        if (!/ds-ignore/.test(prevAdded)) checkLine(file, newLineNo, line, out)
        prevAdded = line
        newLineNo++
      } else if (!dl.startsWith('-') && !dl.startsWith('---')) {
        newLineNo++
        prevAdded = ''
      }
    }
  }
  report(out, true)
}

function report(out, fail) {
  if (out.length === 0) {
    console.log('design-system check: clean')
    return
  }
  const byRule = {}
  for (const v of out) {
    byRule[v.ruleId] = (byRule[v.ruleId] ?? 0) + 1
    console.log(`${v.file}:${v.lineNo}  [${v.ruleId}]  ${v.message}\n    ${v.line}`)
  }
  console.log(`\n${out.length} violation(s): ${JSON.stringify(byRule)}`)
  if (fail) {
    console.log('\nFix these, or add `ds-ignore` in a comment on the line if it is deliberate.')
    process.exit(1)
  }
}

const baseArg = process.argv.indexOf('--base')
if (baseArg !== -1 && process.argv[baseArg + 1]) {
  runDiff(process.argv[baseArg + 1])
} else {
  runFullTree()
}
