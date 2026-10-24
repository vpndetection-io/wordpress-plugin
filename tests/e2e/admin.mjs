// Drives the admin screens in Chromium, as a site owner on a VPN would: the self-check, Connect with
// VPNDetection against the stand-in API, saving settings, the Activity tab and its Allow link, and a
// held order. It saves the screenshots the plugin directory shows.
//
//   node tests/e2e/admin.mjs <WordPress container address> <order id> <screenshot directory>
//
// PLAYWRIGHT_MODULE names another copy of playwright (playwright-core will do) and CHROMIUM a browser
// for it to drive, where neither is installed the default way.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const [address, order, shots] = process.argv.slice(2);
const site = 'http://wp';
const failures = [];

function check(name, ok) {
    console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${name}`);
    if (!ok) {
        failures.push(name);
    }
}

const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM || undefined,
    args: [`--host-resolver-rules=MAP wp ${address}`],
});
try {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        extraHTTPHeaders: { 'X-Forwarded-For': '45.83.91.1' },
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto(`${site}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'correct-horse');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);

    await page.goto(`${site}/wp-admin/options-general.php?page=vpndetection`);
    check('the self-check names the address', (await page.textContent('#vpnd-connection')).includes('45.83.91.1'));
    check('  ... and says it is a VPN', (await page.textContent('#vpnd-connection')).includes('VPNs'));
    check('an unconnected site offers Connect', await page.isVisible('#vpnd-connect'));
    check('a free account is offered for Tor', (await page.textContent('.vpnd-signals')).includes('Needs a free account'));

    const popup = context.waitForEvent('page');
    await page.click('#vpnd-connect');
    const tab = await popup;
    const approval = await tab.waitForRequest(/user_code=/);
    await page.waitForFunction(() => document.getElementById('vpnd-connect-status').textContent.includes('WXYZ-1234'));
    check('Connect opens the approval page with the code', approval.url().includes('user_code=WXYZ-1234'));
    check('  ... tagged as the plugin\'s', approval.url().includes('utm_source=wordpress'));
    await tab.close();
    await page.waitForSelector('text=Connected, on the Max plan.', { timeout: 30_000 });
    check('once approved the site is connected', true);
    check('  ... and no signal needs a better plan', !(await page.textContent('.vpnd-signals')).includes('Needs'));

    await page.selectOption('#vpnd-login', 'block');
    await page.selectOption('#vpnd-comment', 'hold');
    await page.selectOption('#vpnd-pages', 'log');
    check('an area that is on shows its options', await page.isVisible('tr[data-area="comment"] .vpnd-own'));
    await page.check('tr[data-area="comment"] .vpnd-own');
    check('  ... and its own signals once asked for', await page.isVisible('tr[data-area="comment"] .vpnd-own-signals'));
    await page.check('tr[data-area="comment"] .vpnd-own-signals input[value="hosting"]');
    await page.fill('#vpnd-allowlist', '203.0.113.7 # the office');
    await page.click('#submit');
    await page.waitForSelector('text=Settings saved.');
    check('the settings save', await page.inputValue('#vpnd-login') === 'block');
    check('  ... an area\'s own signals with them', await page.isChecked('tr[data-area="comment"] .vpnd-own'));
    check('  ... and the site\'s signals stay its own', await page.isChecked('.vpnd-signals input[value="tor"]'));
    await page.screenshot({ path: `${shots}/screenshot-1.png`, clip: { x: 0, y: 0, width: 1280, height: 1500 }, fullPage: true });

    await page.goto(`${site}/wp-admin/options-general.php?page=vpndetection&tab=activity`);
    check('the Activity tab lists who was caught', await page.isVisible('table.wp-list-table tbody tr td code'));
    await page.screenshot({ path: `${shots}/screenshot-2.png` });
    await page.click('table.wp-list-table tbody tr:first-child .row-actions a');
    await page.waitForSelector('text=is on the allowlist');
    check('Allow this address puts it on the allowlist', true);
    await page.goto(`${site}/wp-admin/options-general.php?page=vpndetection`);
    check('  ... where the settings show it', (await page.inputValue('#vpnd-allowlist')).includes('# the office'));

    if (order) {
        await page.goto(`${site}/wp-admin/admin.php?page=wc-orders&action=edit&id=${order}`);
        if (!(await page.isVisible('.order_notes'))) {
            await page.goto(`${site}/wp-admin/post.php?post=${order}&action=edit`);
        }
        check('a held order shows its note', (await page.textContent('.order_notes')).includes('VPNDetection'));
        await page.locator('#woocommerce-order-notes').screenshot({ path: `${shots}/screenshot-3.png` });
    }
    check('no script error on any screen', errors.length === 0);
    if (errors.length > 0) {
        console.log(errors.join('\n'));
    }
} finally {
    await browser.close();
}
process.exit(failures.length === 0 ? 0 : 1);
