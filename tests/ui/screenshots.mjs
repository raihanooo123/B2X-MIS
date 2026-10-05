/**
 * 05.16 §6 acceptance for the trade screens: a screenshot of every fixture
 * at 390 and 1440 px, axe (WCAG 2.1 A/AA) on each page and on each opened
 * dialog, and a no-horizontal-page-scroll check. Fails on any serious or
 * critical axe violation, or any page overflow.
 *
 *   npx vite build --config tests/ui/vite.config.ts
 *   NODE_PATH=<dir with playwright and axe-core> node tests/ui/screenshots.mjs [dist] [out]
 *
 * Playwright and axe-core are not project dependencies; point NODE_PATH at
 * any install of them. Stripe is blocked, so the card field renders empty.
 */
import { createReadStream, existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { extname, join, resolve } from 'node:path';

const require = createRequire(join(process.env.NODE_PATH ?? process.cwd(), 'noop.js'));
const { chromium } = require('playwright');
const axePath = require.resolve('axe-core/axe.min.js');

const dist = resolve(process.argv[2] ?? 'node_modules/.cache/ui-harness');
const out = resolve(process.argv[3] ?? 'node_modules/.cache/ui-shots');
mkdirSync(out, { recursive: true });

const FIXTURES = [
    ['approvals-populated', '/trade/approvals'],
    ['approvals-decided', '/trade/approvals'],
    ['approvals-empty', '/trade/approvals'],
    ['approvals-filtered-empty', '/trade/approvals'],
    ['approvals-loading', '/trade/approvals'],
    ['approvals-error', '/trade/approvals'],
    ['approval-detail', '/trade/approvals/01J9APPROVAL00000000000000'],
    ['approval-detail-success', '/trade/approvals/01J9APPROVAL00000000000000'],
    ['approval-detail-unfunded', '/trade/approvals/01J9APPROVAL00000000000000'],
    ['credit-populated', '/trade/account/credit'],
    ['credit-over-limit', '/trade/account/credit'],
    ['credit-empty', '/trade/account/credit'],
    ['credit-loading', '/trade/account/credit'],
    ['balance-populated', '/trade/account/balance'],
    ['balance-empty', '/trade/account/balance'],
    ['balance-error', '/trade/account/balance'],
    ['users-populated', '/trade/account/users'],
    ['users-empty', '/trade/account/users'],
    ['users-success', '/trade/account/users'],
    ['pay-card', '/trade/orders/01J9ORDER000000000000000000/pay'],
    ['pay-in-advance', '/trade/orders/01J9ORDER000000000000000000/pay'],
    ['pay-closed', '/trade/orders/01J9ORDER000000000000000000/pay'],
];

/** Interactions that open a dialog or a selection state, screenshot and axe'd too. */
const INTERACTIONS = {
    'approvals-populated': [['selected', async (p) => (await p.locator('input[type=checkbox]:visible').nth(1).check())], ['bulk-reject-dialog', async (p) => p.getByRole('button', { name: 'Reject selected…' }).click()]],
    'approval-detail': [['approve-dialog', async (p) => p.getByRole('button', { name: 'Approve order…' }).click()]],
    'users-populated': [['edit-dialog', async (p) => p.getByRole('button', { name: /^Edit/ }).filter({ visible: true }).nth(2).click()]],
    'pay-in-advance': [['confirm-dialog', async (p) => p.getByRole('button', { name: 'Pay by card instead…' }).click()]],
};

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const server = createServer((req, res) => {
    const path = decodeURIComponent((req.url ?? '/').split('?')[0]);
    const file = join(dist, path);
    const target = path.startsWith('/assets/') && existsSync(file) && statSync(file).isFile() ? file : join(dist, 'index.html');
    res.writeHead(200, { 'Content-Type': TYPES[extname(target)] ?? 'application/octet-stream' });
    createReadStream(target).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch();
const report = [];

async function audit(page, label) {
    await page.addScriptTag({ path: axePath });
    const result = await page.evaluate(async () => window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const violations = result.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, target: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
    report.push({ label, overflow, violations });
    await page.screenshot({ path: join(out, `${label}.png`), fullPage: true });
}

for (const width of [390, 1440]) {
    const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1, locale: 'en-GB', timezoneId: 'Europe/London', reducedMotion: 'reduce' });
    await context.route(/stripe\.com/, (route) => route.abort());
    for (const [name, path] of FIXTURES) {
        const page = await context.newPage();
        await page.goto(`${base}${path}?fixture=${name}`);
        await page.waitForSelector('body[data-ready="true"]');
        await page.waitForSelector('main#main');
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(150);
        await audit(page, `${name}-${width}`);
        for (const [step, act] of INTERACTIONS[name] ?? []) {
            await act(page);
            await page.waitForTimeout(250);
            await audit(page, `${name}-${step}-${width}`);
        }
        await page.close();
    }
    await context.close();
}

await browser.close();
server.close();

writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2));
const serious = report.flatMap((r) => r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => ({ page: r.label, ...v })));
const minor = report.flatMap((r) => r.violations.filter((v) => v.impact !== 'serious' && v.impact !== 'critical').map((v) => ({ page: r.label, ...v })));
const overflowing = report.filter((r) => r.overflow > 0);
console.log(`${report.length} screenshots → ${out}`);
console.log(`axe serious/critical: ${serious.length}; moderate/minor: ${minor.length}; pages with horizontal overflow: ${overflowing.length}`);
for (const v of [...serious, ...minor]) console.log(`  [${v.impact}] ${v.page}: ${v.id} — ${v.help} (${v.nodes}) ${v.target.join(' | ')}`);
for (const r of overflowing) console.log(`  overflow ${r.overflow}px: ${r.label}`);
process.exit(serious.length > 0 || overflowing.length > 0 ? 1 : 0);
