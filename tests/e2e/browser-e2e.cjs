// 以真實 Chrome 走完端對端流程。需要 playwright-core 與本機 Chrome。
// 用法：NODE_PATH=<含 playwright-core 的 node_modules> node tests/e2e/browser-e2e.cjs <screenshot 目錄>
// 執行前先重建資料：docker compose exec app php artisan migrate:fresh --seed --force
// 腳本會用 topup:admin-reset 取得管理員的臨時密碼，所以不需要傳入密碼。
const { chromium } = require('playwright-core');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const path = require('node:path');

const BASE = 'https://localhost:8443';
const [shots] = process.argv.slice(2);
const PASSWORD = 'Password123456';
const problems = [];
let passed = 0;

function check(name, ok, detail = '') {
  if (ok) { passed++; console.log(`PASS  ${name}`); }
  else { problems.push(name); console.log(`FAIL  ${name} ${detail}`); }
}

function base32(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

function hotp(secret, step) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const digest = crypto.createHmac('sha1', base32(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 0xf;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const lastStep = new Map();
// 驗證碼只能用一次，所以要等到比上次新的時間步。
async function totp(admin) {
  let step = Math.floor(Date.now() / 30000);
  while (step <= (lastStep.get(admin.name) ?? 0)) {
    await new Promise((r) => setTimeout(r, 500));
    step = Math.floor(Date.now() / 30000);
  }
  lastStep.set(admin.name, step);
  return hotp(admin.secret, step);
}

const num = (text) => Number(text.replace(/[^\d-]/g, ''));

async function newActor(browser, name) {
  // 關閉動態效果：數字跑動與淡入動畫會讓讀值與截圖不穩定。
  const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'zh-TW', viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.on('dialog', (d) => d.accept());
  page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) check(`${name} 頁面沒有 CSP 違規`, false, m.text()); });
  page.on('pageerror', (e) => check(`${name} 頁面沒有 JS 錯誤`, false, e.message));
  page.on('response', (r) => { if (r.status() >= 500) check(`${name} 沒有 5xx`, false, r.url()); });
  return { name, page, context };
}

async function shot(actor, name) {
  await actor.page.screenshot({ path: path.join(shots, `${name}.png`), fullPage: true });
}

async function balances(player) {
  await player.page.goto(`${BASE}/game`);
  const values = await player.page.locator('.stat .value').allTextContents();
  return { balance: num(values[0]), frozen: num(values[1]), available: num(values[2]) };
}

async function expectBalances(player, expected, label) {
  const got = await balances(player);
  check(label, got.balance === expected.balance && got.frozen === expected.frozen, `got ${JSON.stringify(got)} expected ${JSON.stringify(expected)}`);
}

function resetPassword(name) {
  const output = execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'topup:admin-reset', name, '--password'], { encoding: 'utf8' });
  return output.match(/password: (\S+)/)[1];
}

async function submitAdminPassword(page, name, password) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('#username', name);
  await page.fill('#password', password);
  await page.click('main button[type=submit]');
}

async function adminLogin(actor) {
  const { page, name } = actor;
  const initial = resetPassword(name);

  await submitAdminPassword(page, name, 'wrong-password-1');
  await page.waitForSelector('.flash.err');
  check(`${name} 密碼錯誤進不了後台`, page.url().endsWith('/admin/login'));

  await submitAdminPassword(page, name, initial);
  await page.waitForURL('**/admin/totp');
  actor.secret = (await page.locator('.totp-secret').textContent()).replace(/\s/g, '');
  check(`${name} 第一次登入顯示 TOTP 密鑰`, /^[A-Z2-7]{32}$/.test(actor.secret), actor.secret);
  await page.fill('#code', await totp(actor));
  await page.click('main button[type=submit]');

  await page.waitForURL('**/admin/password');
  check(`${name} 第一次登入被要求更改初始密碼`, (await page.locator('main').textContent()).includes('初始密碼'));
  await page.goto(`${BASE}/admin/refunds`);
  check(`${name} 改密碼前進不了其他頁面`, page.url().endsWith('/admin/password'));

  actor.password = `E2e-${crypto.randomBytes(12).toString('hex')}`;
  await page.fill('#current_password', initial);
  await page.fill('#password', actor.password);
  await page.fill('#password_confirmation', actor.password);
  await page.click('main button[type=submit]');
  await page.waitForURL('**/admin/refunds');
  check(`${name} 更改密碼後進入後台`, true);
}

async function register(actor, username, nickname) {
  const { page } = actor;
  await page.goto(`${BASE}/game/register`);
  await page.fill('#username', username);
  await page.fill('#nickname', nickname);
  await page.fill('#password', PASSWORD);
  await page.fill('#password_confirmation', PASSWORD);
  await page.click('main button[type=submit]');
  await page.waitForURL(`${BASE}/game`);
  actor.playerNo = await page.locator('dl.facts .code').textContent();
  check(`${username} 註冊成功並取得玩家編號`, /^P[A-Z0-9]{8}$/.test(actor.playerNo), actor.playerNo);
}

async function buy(player, packageName, scenario) {
  const { page } = player;
  await page.goto(`${BASE}/game`);
  await page.click('text=前往充值網站');
  await page.waitForURL(`${BASE}/shop`);
  check('單次 token 沒有出現在網址', !page.url().includes('token'));
  await page.locator('.package-card', { hasText: packageName }).click();
  await page.click('#topup-submit');
  await page.waitForURL('**/mock-pay/**');
  const orderNo = await page.locator('dl.facts .code').textContent();
  // 信用卡的結果由測試卡號決定；竄改通知的情境走「進階測試」表單。
  const TEST_CARDS = { success: '4111111111111111', failed: '4000000000000002', delayed: '4000000000000036', duplicate: '4000000000000044' };
  if (scenario && TEST_CARDS[scenario]) {
    await page.click(`[data-fill-card="${TEST_CARDS[scenario]}"]`);
    check(`${scenario} 測試卡填入後格式檢查通過`, (await page.locator('#card-form [aria-invalid=true]').count()) === 0);
    await page.click('#card-form button[type=submit]');
    await page.waitForURL('**/shop/orders/**');
  } else if (scenario) {
    await page.click('details.advanced summary');
    await page.selectOption('#scenario', scenario);
    await page.click('details.advanced button[type=submit]');
    await page.waitForURL('**/shop/orders/**');
  }
  return orderNo;
}

async function waitForText(actor, url, text, timeout = 45000) {
  const until = Date.now() + timeout;
  for (;;) {
    try {
      await actor.page.goto(url);
      if ((await actor.page.locator('main').textContent()).includes(text)) return true;
    } catch (e) {
      // 訂單頁會自動重新整理，可能與這裡的跳轉互相中斷；直接重試。
      if (!/ERR_ABORTED|interrupted|destroyed/i.test(e.message)) throw e;
    }
    if (Date.now() > until) return false;
    await actor.page.waitForTimeout(1000);
  }
}

// 退款要先綁定撥款帳戶（需要再輸入一次密碼）；已經綁過就不重複綁。
async function ensureBankAccount(player) {
  const { page } = player;
  await page.goto(`${BASE}/game/refunds`);
  if ((await page.locator('input[name=bank_account_id]').count()) > 0) return;
  await page.fill('#label', '測試帳戶');
  await page.fill('#bank_code', '812');
  await page.fill('#bank_account', '1234567890123');
  await page.fill('#holder_name', '王小明');
  await page.fill('#account_password', PASSWORD);
  await page.click('details.add-account button[type=submit]');
  await page.waitForSelector('.flash.ok');
  check(`${player.name} 綁定撥款帳戶`, (await page.textContent('.flash.ok')).includes('812-****0123'));
}

async function submitRefund(player, points) {
  const { page } = player;
  await ensureBankAccount(player);
  await page.goto(`${BASE}/game/refunds`);
  await page.fill('#points', String(points));
  await page.click('main form[data-dialog] button[type=submit]');
  await page.waitForSelector('#refund-dialog[open]');
  await page.click('[data-dialog-ok]');
  await page.waitForURL(`${BASE}/game/refunds`);
  await page.waitForSelector('.flash.ok');
}

async function latestRefundId(admin) {
  await admin.page.goto(`${BASE}/admin/refunds`);
  return num(await admin.page.locator('tbody tr td a').first().textContent());
}

async function reviewerApprove(reviewer, id) {
  await reviewer.page.goto(`${BASE}/admin/refunds/${id}`);
  await reviewer.page.click('text=同意，交由覆核');
  await reviewer.page.waitForSelector('.flash.ok');
}

async function supervisorApprove(supervisor, id) {
  await supervisor.page.goto(`${BASE}/admin/refunds/${id}`);
  await supervisor.page.fill('#code', await totp(supervisor));
  await supervisor.page.click('text=核准並送出');
  await supervisor.page.waitForSelector('.flash.ok, .flash.err');
}

async function setNextPayout(supervisor, result) {
  await supervisor.page.goto(`${BASE}/admin/demo`);
  await supervisor.page.selectOption('#result', result);
  await supervisor.page.click('main button:has-text("設定")');
  await supervisor.page.waitForSelector('.flash.ok');
}

(async () => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER || 'chrome', headless: true });
  const A = await newActor(browser, 'A');
  const B = await newActor(browser, 'B');
  const reviewer = await newActor(browser, 'reviewer');
  const supervisor = await newActor(browser, 'supervisor');

  // 管理員第一次登入、綁定 TOTP；錯誤密碼與未驗證 TOTP 都進不去。
  await reviewer.page.goto(`${BASE}/admin/refunds`);
  check('未登入進不了後台', reviewer.page.url().endsWith('/admin/login'));
  await adminLogin(reviewer);
  await adminLogin(supervisor);

  // 步驟 8 的前半：新增 500 元方案。
  await supervisor.page.goto(`${BASE}/admin/packages`);
  await supervisor.page.fill('#name', '500 元方案');
  await supervisor.page.fill('#price', '500');
  await supervisor.page.click('main .card:last-child button[type=submit]');
  await supervisor.page.waitForSelector('.flash.ok');

  // 步驟 1：註冊、充值。
  await register(A, 'alice_e2e', '愛麗絲');
  await register(B, 'bob_e2e', '小明');
  await buy(A, '500 元方案', 'success');
  // 訂單頁每 1 秒更新，入帳完成後自動回到玩家中心。
  await A.page.waitForURL(`${BASE}/game`, { timeout: 45000 });
  check('入帳後回到玩家中心並顯示訊息', (await A.page.textContent('.flash.ok')).includes('充值完成，已入帳 50,000 點'));
  await shot(A, '01-order-fulfilled');
  await expectBalances(A, { balance: 50000, frozen: 0 }, '付款 500 元取得 50,000 點');
  await A.page.goto(`${BASE}/game/ledger`);
  check('點數明細顯示充值入點', (await A.page.locator('main').textContent()).includes('充值入點'));
  await A.page.goto(`${BASE}/game/orders`);
  check('玩家頁顯示充值訂單', (await A.page.locator('main').textContent()).includes('500 元方案'));

  // 重複通知、錯誤簽章與金額不符。
  const duplicate = await buy(A, '100 元方案', 'duplicate');
  check('重複通知的訂單完成', await waitForText(A, `${BASE}/shop/orders/${duplicate}`, '已完成'));
  await expectBalances(A, { balance: 60000, frozen: 0 }, '重複通知只入點一次');
  const badSignature = await buy(A, '100 元方案', 'bad_signature');
  await A.page.waitForTimeout(3000);
  check('錯誤簽章不改變訂單', await waitForText(A, `${BASE}/shop/orders/${badSignature}`, '待付款'));
  const mismatch = await buy(A, '100 元方案', 'amount_mismatch');
  await supervisor.page.waitForTimeout(3000);
  check('金額不符轉入異常清單', await waitForText(supervisor, `${BASE}/admin/anomalies`, mismatch));
  await expectBalances(A, { balance: 60000, frozen: 0 }, '錯誤簽章與金額不符都不入點');

  // 步驟 2：任務、紅包。
  await A.page.goto(`${BASE}/game`);
  await A.page.fill('#points', '100000');
  await A.page.click('text=取得點數');
  await A.page.waitForSelector('.flash.ok');
  await A.page.goto(`${BASE}/game/red-packets`);
  await A.page.fill('#player_no', B.playerNo);
  await A.page.fill('#points', '5000');
  await A.page.fill('#message', '恭喜發財');
  await A.page.click('text=下一步');
  await A.page.waitForSelector('text=確認紅包');
  const confirm = await A.page.locator('main').textContent();
  check('紅包確認畫面顯示對方編號與暱稱', confirm.includes(B.playerNo) && confirm.includes('小明'));
  await shot(A, '02-red-packet-confirm');
  await A.page.click('text=確認送出');
  await A.page.waitForSelector('.flash.ok');
  await expectBalances(A, { balance: 155000, frozen: 0 }, '發紅包先扣點');
  await B.page.goto(`${BASE}/game/red-packets`);
  await B.page.click('main button:has-text("領取")');
  await B.page.waitForSelector('.flash.ok');
  await expectBalances(B, { balance: 5000, frozen: 0 }, '收件者領取紅包');

  await A.page.goto(`${BASE}/game/red-packets`);
  await A.page.fill('#player_no', B.playerNo);
  await A.page.fill('#points', '1000');
  await A.page.click('text=下一步');
  await A.page.click('text=確認送出');
  await A.page.waitForSelector('.flash.ok');
  const packetId = num(await A.page.locator('.card:last-child tbody tr td').first().textContent());
  await supervisor.page.goto(`${BASE}/admin/demo`);
  await supervisor.page.fill('#packet_id', String(packetId));
  await supervisor.page.click('text=立即到期並退回');
  await supervisor.page.waitForSelector('.flash.ok');
  await expectBalances(A, { balance: 155000, frozen: 0 }, '未領紅包到期退回');

  // 步驟 3：退款申請、提示視窗、凍結。
  await ensureBankAccount(A);
  await A.page.goto(`${BASE}/game/refunds`);
  await A.page.fill('#points', '80000');
  await A.page.click('main form[data-dialog] button[type=submit]');
  await A.page.waitForSelector('#refund-dialog[open]');
  const dialogText = await A.page.locator('#refund-dialog').textContent();
  check('提示視窗說明不可撤銷與凍結', dialogText.includes('無法撤銷') && dialogText.includes('凍結') && dialogText.includes('真實銀行帳號'));
  await shot(A, '03-refund-dialog');
  await A.page.click('[data-dialog-cancel]');
  check('取消提示視窗不會送出', (await A.page.locator('main').textContent()).includes('還沒有申請紀錄'));
  await submitRefund(A, 80000);
  await A.page.waitForTimeout(3000);
  await expectBalances(A, { balance: 155000, frozen: 80000 }, '只凍結申請的點數');
  await A.page.goto(`${BASE}/game/refunds`);
  const refundPage = await A.page.locator('main').textContent();
  check('玩家看到審核中', refundPage.includes('審核中'));
  check('玩家頁沒有撤銷按鈕', !refundPage.includes('撤銷申請') && (await A.page.locator('button:has-text("撤銷")').count()) === 0);
  check('玩家頁帳號已遮罩', refundPage.includes('812-****0123') && !refundPage.includes('1234567890123'));
  await A.page.goto(`${BASE}/game/red-packets`);
  await A.page.fill('#player_no', B.playerNo);
  await A.page.fill('#points', '80000');
  await A.page.click('text=下一步');
  await A.page.waitForSelector('.flash.err');
  check('凍結點數不能發紅包', (await A.page.locator('.flash.err').textContent()).includes('可用點數不足'));

  // 步驟 4：逾時提醒、兩層審核。
  const refundId = await latestRefundId(reviewer);
  await supervisor.page.goto(`${BASE}/admin/demo`);
  await supervisor.page.fill('#refund_id', String(refundId));
  await supervisor.page.click('text=設為審核逾時');
  await supervisor.page.waitForSelector('.flash.ok');
  await reviewer.page.goto(`${BASE}/admin/refunds`);
  check('後台顯示審核逾時', (await reviewer.page.locator('main').textContent()).includes('審核逾時'));
  await reviewer.page.goto(`${BASE}/admin/refunds/${refundId}`);
  const reviewerPage = await reviewer.page.locator('main').textContent();
  check('初審看到點數、金額與遮罩帳戶', reviewerPage.includes('80,000 點') && reviewerPage.includes('800 元') && reviewerPage.includes('812-****0123') && !reviewerPage.includes('1234567890123'));
  check('初審頁沒有覆核按鈕', (await reviewer.page.locator('text=核准並送出').count()) === 0);
  await supervisor.page.goto(`${BASE}/admin/refunds/${refundId}`);
  check('待初審時覆核者不能操作', (await supervisor.page.locator('text=核准並送出').count()) === 0);
  await shot(reviewer, '04-review');
  await reviewerApprove(reviewer, refundId);
  await supervisorApprove(supervisor, refundId);
  await shot(supervisor, '05-supervisor-approved');

  // 步驟 5：扣點、撥款、後四碼、稽核。
  check('撥款完成', await waitForText(supervisor, `${BASE}/admin/refunds/${refundId}`, '已撥款'));
  await expectBalances(A, { balance: 75000, frozen: 0 }, '核准後扣除凍結點數');
  await A.page.goto(`${BASE}/game/refunds`);
  const done = await A.page.locator('main').textContent();
  check('玩家看到已完成', done.includes('已完成'));
  check('玩家看不到審核層級', !done.includes('待覆核') && !done.includes('supervisor') && !done.includes('reviewer'));
  await supervisor.page.goto(`${BASE}/admin/audit?action=refund.`);
  const audit = await supervisor.page.locator('main').textContent();
  for (const action of ['refund.submitted', 'refund.frozen', 'refund.review_approved', 'refund.approved', 'refund.points_deducted', 'refund.paying_out', 'refund.paid_out']) {
    check(`稽核紀錄包含 ${action}`, audit.includes(action));
  }
  check('稽核紀錄沒有完整帳號', !audit.includes('1234567890123'));
  await shot(supervisor, '06-audit');

  // 步驟 6：拒絕、撥款失敗重試、結案還點。
  await submitRefund(A, 10000);
  await A.page.waitForTimeout(3000);
  const rejectId = await latestRefundId(reviewer);
  await reviewer.page.goto(`${BASE}/admin/refunds/${rejectId}`);
  await reviewer.page.fill('#reason', '資料需要補充');
  await reviewer.page.click('text=拒絕並解凍');
  await reviewer.page.waitForSelector('.flash.ok');
  await A.page.waitForTimeout(3000);
  await expectBalances(A, { balance: 75000, frozen: 0 }, '拒絕後解凍');
  await A.page.goto(`${BASE}/game/refunds`);
  check('玩家看到拒絕理由', (await A.page.locator('main').textContent()).includes('資料需要補充'));

  await submitRefund(A, 10000);
  await A.page.waitForTimeout(3000);
  const retryId = await latestRefundId(reviewer);
  await setNextPayout(supervisor, 'failed');
  await reviewerApprove(reviewer, retryId);
  await supervisorApprove(supervisor, retryId);
  check('撥款失敗', await waitForText(supervisor, `${BASE}/admin/refunds/${retryId}`, '撥款失敗'));
  await shot(supervisor, '07-payout-failed');
  await supervisor.page.click('text=重試撥款');
  check('重試後撥款成功', await waitForText(supervisor, `${BASE}/admin/refunds/${retryId}`, '已撥款'));
  await expectBalances(A, { balance: 65000, frozen: 0 }, '重試不會重複扣點');

  await submitRefund(A, 10000);
  await A.page.waitForTimeout(3000);
  const closeId = await latestRefundId(reviewer);
  await setNextPayout(supervisor, 'failed');
  await reviewerApprove(reviewer, closeId);
  await supervisorApprove(supervisor, closeId);
  check('第二次撥款失敗', await waitForText(supervisor, `${BASE}/admin/refunds/${closeId}`, '撥款失敗'));
  await supervisor.page.fill('#reason', '帳戶錯誤');
  await supervisor.page.fill('#code', await totp(supervisor));
  await supervisor.page.click('text=結案並還點');
  check('結案並還點', await waitForText(supervisor, `${BASE}/admin/refunds/${closeId}`, '已結案並還點'));
  await expectBalances(A, { balance: 65000, frozen: 0 }, '結案後點數還給玩家');

  // 步驟 7：人工調整、停權連動、補入點。
  await reviewer.page.goto(`${BASE}/admin/players`);
  await reviewer.page.fill('#player_no', A.playerNo);
  await reviewer.page.click('main button[type=submit]');
  await reviewer.page.waitForURL('**/admin/players/*');
  await reviewer.page.selectOption('#direction', 'add');
  await reviewer.page.fill('#points', '1000');
  await reviewer.page.fill('#adjust_reason', '活動補償');
  await reviewer.page.click('main button:has-text("發起")');
  await reviewer.page.waitForSelector('.flash.ok');
  await expectBalances(A, { balance: 65000, frozen: 0 }, '發起調整時點數不變');
  await supervisor.page.goto(`${BASE}/admin/adjustments`);
  await supervisor.page.fill('input[name=code]', await totp(supervisor));
  await supervisor.page.click('main button:has-text("核准")');
  await supervisor.page.waitForSelector('.flash.ok');
  await expectBalances(A, { balance: 66000, frozen: 0 }, '覆核核准後調整生效');

  await B.page.goto(`${BASE}/game`);
  await B.page.fill('#points', '20000');
  await B.page.click('text=取得點數');
  await B.page.waitForSelector('.flash.ok');
  const heldOrder = await buy(B, '100 元方案', null);
  await submitRefund(B, 10000);
  await B.page.waitForTimeout(3000);
  await B.page.goto(`${BASE}/game/red-packets`);
  await B.page.fill('#player_no', A.playerNo);
  await B.page.fill('#points', '100');
  await B.page.click('text=下一步');
  await B.page.click('text=確認送出');
  await B.page.waitForSelector('.flash.ok');
  await expectBalances(B, { balance: 24900, frozen: 10000 }, '停權前 B 的點數狀態');

  await reviewer.page.goto(`${BASE}/admin/players?player_no=${B.playerNo}`);
  await reviewer.page.selectOption('#direction', 'add');
  await reviewer.page.fill('#points', '777');
  await reviewer.page.fill('#adjust_reason', '待核准調整');
  await reviewer.page.click('main button:has-text("發起")');
  await reviewer.page.waitForSelector('.flash.ok');
  const bAdminUrl = reviewer.page.url();
  const bRefundId = await latestRefundId(reviewer);

  await supervisor.page.goto(bAdminUrl);
  await supervisor.page.fill('#suspend_reason', '可疑操作');
  await supervisor.page.fill('#code', await totp(supervisor));
  await supervisor.page.click('main button:has-text("停權")');
  await supervisor.page.waitForSelector('.flash.ok');
  await shot(supervisor, '08-suspended');

  await B.page.goto(`${BASE}/game`);
  check('停權後既有 session 立即失效', B.page.url().endsWith('/game/login'));
  check('停權提示不含內部理由', (await B.page.locator('main').textContent()).includes('帳號已停權') && !(await B.page.locator('main').textContent()).includes('可疑操作'));
  await B.page.fill('#username', 'bob_e2e');
  await B.page.fill('#password', PASSWORD);
  await B.page.click('main button[type=submit]');
  await B.page.waitForSelector('.flash.err');
  check('停權後無法登入', (await B.page.locator('.flash.err').textContent()).includes('帳號已停權'));
  await B.page.goto(`${BASE}/shop`);
  check('停權後充值網站也失效', B.page.url().endsWith('/game/login'));

  check('審核中的申請被自動拒絕', await waitForText(supervisor, `${BASE}/admin/refunds/${bRefundId}`, '帳號停權，系統自動拒絕'));
  await supervisor.page.goto(`${BASE}/admin/adjustments`);
  check('待核准調整被自動拒絕', (await supervisor.page.locator('main').textContent()).includes('帳號停權，系統自動拒絕'));
  await expectBalances(A, { balance: 66000, frozen: 0 }, '發給 A 的未領紅包沒有入帳');
  await A.page.goto(`${BASE}/game/red-packets`);
  check('A 的紅包匣顯示已退回', (await A.page.locator('main').textContent()).includes('已退回'));

  execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'tests/e2e/simulate-payment.php', heldOrder], { stdio: 'pipe' });
  check('停權後的付款轉入異常清單', await waitForText(supervisor, `${BASE}/admin/anomalies`, '玩家停權，暫不入點'));
  await shot(supervisor, '09-anomaly-held');

  await supervisor.page.goto(bAdminUrl);
  await supervisor.page.fill('#suspend_reason', '查證無誤');
  await supervisor.page.fill('#code', await totp(supervisor));
  await supervisor.page.click('main button:has-text("解除停權")');
  await supervisor.page.waitForSelector('.flash.ok');

  await B.page.goto(`${BASE}/game/login`);
  await B.page.fill('#username', 'bob_e2e');
  await B.page.fill('#password', PASSWORD);
  await B.page.click('main button[type=submit]');
  await B.page.waitForURL(`${BASE}/game`);
  await expectBalances(B, { balance: 25000, frozen: 0 }, '解除後：解凍、紅包退回、尚未補入點');

  await supervisor.page.goto(`${BASE}/admin/anomalies`);
  const row = supervisor.page.locator('tr', { hasText: '玩家停權，暫不入點' });
  await row.locator('input[name=code]').fill(await totp(supervisor));
  await row.locator('button:has-text("補入點")').click();
  await supervisor.page.waitForSelector('.flash.ok');
  await B.page.waitForTimeout(3000);
  await expectBalances(B, { balance: 35000, frozen: 0 }, '手動補入點只入帳一次');

  // 步驟 8：下架與改價。
  await supervisor.page.goto(`${BASE}/admin/packages`);
  const packageRow = supervisor.page.locator('tbody tr', { hasText: '500 元方案' });
  await packageRow.locator('input[name=price]').fill('600');
  await packageRow.locator('input[name=active]').uncheck();
  await packageRow.locator('button:has-text("儲存")').click();
  await supervisor.page.waitForSelector('.flash.ok');
  await A.page.goto(`${BASE}/game`);
  await A.page.click('text=前往充值網站');
  await A.page.waitForURL(`${BASE}/shop`);
  check('下架後充值頁看不到方案', !(await A.page.locator('main').textContent()).includes('500 元方案'));
  await A.page.goto(`${BASE}/game/orders`);
  const orders = await A.page.locator('main').textContent();
  check('既有訂單維持原方案與金額', orders.includes('500 元方案') && orders.includes('500 元'));
  await shot(A, '10-orders');

  // 安全事件的稽核紀錄。
  await supervisor.page.goto(`${BASE}/admin/audit?action=admin.`);
  const adminAudit = await supervisor.page.locator('main').textContent();
  for (const action of ['admin.login_failed', 'admin.totp_confirmed', 'admin.password_changed', 'admin.reset_by_cli', 'admin.login']) {
    check(`稽核紀錄包含 ${action}`, adminAudit.includes(action));
  }
  check('稽核紀錄有來源 IP', adminAudit.includes('172.28.0.1'));
  await shot(supervisor, '11-admin-audit');

  // 帳號停用：既有 session 立即失效，也不能重新登入。
  await supervisor.page.goto(`${BASE}/admin/accounts`);
  const accountRow = supervisor.page.locator('tbody tr', { hasText: 'reviewer' });
  await accountRow.locator('input[name=reason]').fill('離職');
  await accountRow.locator('input[name=code]').fill(await totp(supervisor));
  await accountRow.locator('button:has-text("停用")').click();
  await supervisor.page.waitForSelector('.flash.ok');
  await shot(supervisor, '12-accounts');
  await reviewer.page.goto(`${BASE}/admin/refunds`);
  check('停用後既有 session 立即失效', reviewer.page.url().endsWith('/admin/login'));
  await submitAdminPassword(reviewer.page, 'reviewer', reviewer.password);
  await reviewer.page.waitForSelector('.flash.err');
  check('停用後無法登入', reviewer.page.url().endsWith('/admin/login'));

  const enableRow = supervisor.page.locator('tbody tr', { hasText: 'reviewer' });
  await enableRow.locator('input[name=reason]').fill('復職');
  await enableRow.locator('input[name=code]').fill(await totp(supervisor));
  await enableRow.locator('button:has-text("恢復")').click();
  await supervisor.page.waitForSelector('.flash.ok');
  await submitAdminPassword(reviewer.page, 'reviewer', reviewer.password);
  await reviewer.page.waitForURL('**/admin/totp');
  check('恢復後可以登入，且不再顯示 TOTP 密鑰', (await reviewer.page.locator('.code').count()) === 0);

  // 改密碼：另一台裝置立即登出，改密碼的這台保持登入。
  await reviewer.page.fill('#code', await totp(reviewer));
  await reviewer.page.click('main button[type=submit]');
  await reviewer.page.waitForURL('**/admin/refunds');
  const otherDevice = await newActor(browser, 'reviewer');
  await submitAdminPassword(otherDevice.page, 'reviewer', reviewer.password);
  await otherDevice.page.waitForURL('**/admin/totp');
  await otherDevice.page.fill('#code', await totp(reviewer));
  await otherDevice.page.click('main button[type=submit]');
  await otherDevice.page.waitForURL('**/admin/refunds');
  check('另一台裝置也登入了', true);

  const changed = `E2e-${crypto.randomBytes(12).toString('hex')}`;
  await reviewer.page.goto(`${BASE}/admin/password`);
  await reviewer.page.fill('#current_password', reviewer.password);
  await reviewer.page.fill('#password', changed);
  await reviewer.page.fill('#password_confirmation', changed);
  await reviewer.page.click('main button[type=submit]');
  await reviewer.page.waitForSelector('.flash.ok');
  await otherDevice.page.goto(`${BASE}/admin/refunds`);
  check('改密碼後另一台裝置立即登出', otherDevice.page.url().endsWith('/admin/login'));
  check('管理員另一台裝置看到指定的提示', (await otherDevice.page.locator('main').textContent()).includes('帳號密碼已變更，請重新登入'));
  await reviewer.page.goto(`${BASE}/admin/refunds`);
  check('改密碼的裝置保持登入', reviewer.page.url().endsWith('/admin/refunds'));
  await submitAdminPassword(otherDevice.page, 'reviewer', reviewer.password);
  await otherDevice.page.waitForSelector('.flash.err');
  check('舊密碼不能再登入', otherDevice.page.url().endsWith('/admin/login'));

  // 玩家改密碼：另一台裝置的玩家中心與充值網站都失效，這台保持登入。
  const phoneGame = await newActor(browser, 'A');
  await phoneGame.page.goto(`${BASE}/game/login`);
  await phoneGame.page.fill('#username', 'alice_e2e');
  await phoneGame.page.fill('#password', PASSWORD);
  await phoneGame.page.click('main button[type=submit]');
  await phoneGame.page.waitForURL(`${BASE}/game`);

  const phoneShop = await newActor(browser, 'A');
  await phoneShop.page.goto(`${BASE}/game/login`);
  await phoneShop.page.fill('#username', 'alice_e2e');
  await phoneShop.page.fill('#password', PASSWORD);
  await phoneShop.page.click('main button[type=submit]');
  await phoneShop.page.waitForURL(`${BASE}/game`);
  await phoneShop.page.click('text=前往充值網站');
  await phoneShop.page.waitForURL(`${BASE}/shop`);
  await phoneShop.page.waitForTimeout(1500);

  const newPlayerPassword = 'ChangedPassword987';
  await A.page.goto(`${BASE}/game`);
  await A.page.click('header a:has-text("改密碼")');
  await A.page.waitForURL('**/game/password');
  await A.page.fill('#current_password', PASSWORD);
  await A.page.fill('#password', newPlayerPassword);
  await A.page.fill('#password_confirmation', newPlayerPassword);
  await shot(A, '13-player-password');
  await A.page.click('main button[type=submit]');
  await A.page.waitForSelector('.flash.ok');
  check('玩家看到密碼已更新', (await A.page.locator('.flash.ok').textContent()).includes('密碼已更新'));
  check('玩家改密碼的裝置保持登入', A.page.url() === `${BASE}/game`);

  await phoneGame.page.goto(`${BASE}/game/ledger`);
  check('玩家另一台裝置被登出', phoneGame.page.url().endsWith('/game/login'));
  check('玩家另一台裝置看到指定的提示', (await phoneGame.page.locator('main').textContent()).includes('帳號密碼已變更，請重新登入'));
  await shot(phoneGame, '14-player-kicked');

  await phoneShop.page.goto(`${BASE}/shop`);
  check('玩家另一台裝置的充值網站也被登出', phoneShop.page.url().endsWith('/game/login'));
  check('充值網站被登出時看到指定的提示', (await phoneShop.page.locator('main').textContent()).includes('帳號密碼已變更，請重新登入'));

  await phoneGame.page.fill('#username', 'alice_e2e');
  await phoneGame.page.fill('#password', PASSWORD);
  await phoneGame.page.click('main button[type=submit]');
  await phoneGame.page.waitForSelector('.flash.err');
  check('玩家舊密碼不能再登入', phoneGame.page.url().endsWith('/game/login'));
  await phoneGame.page.fill('#username', 'alice_e2e');
  await phoneGame.page.fill('#password', newPlayerPassword);
  await phoneGame.page.click('main button[type=submit]');
  await phoneGame.page.waitForURL(`${BASE}/game`);
  check('玩家新密碼可以登入', true);

  await A.page.waitForTimeout(1500);
  await A.page.click('text=前往充值網站');
  await A.page.waitForURL(`${BASE}/shop`);
  await A.page.goto(`${BASE}/shop`);
  check('改密碼的裝置重新進入充值網站正常', A.page.url() === `${BASE}/shop`);

  // 小遊戲：遊戲內充值、轉盤、驗證、停用、退款、帳務核對。
  const C = await newActor(browser, 'C');
  await register(C, 'carol_e2e', '卡蘿');
  const wheelPoints = async () => num(await C.page.textContent('#wheel-available'));
  const topup = async (text) => {
    await C.page.click('[data-topup] >> nth=0');
    await C.page.waitForFunction((t) => document.getElementById('topup-message').textContent.includes(t), text, { timeout: 40000 });
  };

  await C.page.goto(`${BASE}/game/wheel`);
  check('轉動前確認預設開啟', await C.page.isChecked('#wheel-confirm-toggle'));
  await C.page.click('#wheel-spin');
  await C.page.waitForSelector('#wheel-confirm-dialog[open]');
  await C.page.click('#wheel-confirm-ok');
  await C.page.waitForFunction(() => document.getElementById('wheel-message').textContent.includes('點數不足'));
  check('點數不足時這一局不成立', (await wheelPoints()) === 0);

  await supervisor.page.goto(`${BASE}/admin/demo`);
  await supervisor.page.selectOption('#game_topup_result', 'failed');
  await supervisor.page.click('form[action$="next-game-topup"] button');
  await supervisor.page.waitForSelector('.flash.ok');
  await topup('請聯繫客服');
  const failedText = await C.page.textContent('#topup-message');
  check('充值失敗時玩家只看到固定訊息', failedText === '充值失敗，請聯繫客服', failedText);
  check('充值失敗不入點', (await wheelPoints()) === 0);
  await C.page.goto(`${BASE}/game/orders`);
  const ordersText = await C.page.textContent('main');
  check('訂單頁也只顯示固定訊息', ordersText.includes('充值失敗，請聯繫客服') && !ordersText.includes('付款失敗'));
  await supervisor.page.goto(`${BASE}/admin/minigame?topup=failed`);
  check('後台看得到實際的付款失敗', (await supervisor.page.textContent('main')).includes('付款失敗'));

  await C.page.goto(`${BASE}/game/wheel`);
  await topup('充值完成');
  let wheelBalance = await wheelPoints();
  check('失敗只生效一次，下一次充值到帳', wheelBalance > 0, String(wheelBalance));
  await topup('充值完成');
  wheelBalance = await wheelPoints();
  await shot(C, '20-wheel-topup');

  await C.page.click('#wheel-spin');
  await C.page.click('#wheel-confirm-cancel');
  check('取消確認不會扣點', (await wheelPoints()) === wheelBalance);
  await C.page.uncheck('#wheel-confirm-toggle');
  await C.page.reload();
  check('確認設定保留在瀏覽器', !(await C.page.isChecked('#wheel-confirm-toggle')));

  await C.page.check('input[name=bet][value="1000"]');
  for (let i = 0; i < 4; i++) {
    const [response] = await Promise.all([
      C.page.waitForResponse((r) => r.url().endsWith('/spin')),
      C.page.click('#wheel-spin'),
    ]);
    const round = await response.json();
    check(`第 ${i + 1} 局結算`, round.state === 'settled' && round.bet === 1000, round.message);
    wheelBalance += round.payout - round.bet;
    await C.page.waitForFunction(() => !document.getElementById('wheel-spin').disabled, null, { timeout: 8000 });
    check(`第 ${i + 1} 局餘額與結果一致`, (await wheelPoints()) === wheelBalance);
    const message = await C.page.textContent('#wheel-message');
    check(`第 ${i + 1} 局低於下注額不算中獎`, round.payout >= round.bet || !message.includes('贏'), message);
  }
  await shot(C, '21-wheel-result');

  await C.page.goto(`${BASE}/game/ledger`);
  const carolLedger = await C.page.textContent('main');
  check('點數明細有充值與遊戲扣點', carolLedger.includes('充值入點') && carolLedger.includes('遊戲扣點'));

  await C.page.goto(`${BASE}/game/wheel/rounds`);
  const verifyButtons = C.page.locator('[data-verify]');
  check('紀錄頁列出已結算的局', (await verifyButtons.count()) === 4);
  for (let i = 0; i < 4; i++) {
    await verifyButtons.nth(i).click();
    await C.page.waitForFunction((n) => document.querySelectorAll('[data-verify-result]')[n].textContent !== '', i);
    check(`第 ${i + 1} 筆在瀏覽器驗證通過`, (await C.page.locator('[data-verify-result]').nth(i).textContent()) === '驗證通過');
  }
  await shot(C, '22-wheel-rounds');

  const toggleWheel = async (button, reason) => {
    await supervisor.page.goto(`${BASE}/admin/minigame`);
    await supervisor.page.fill('#toggle_reason', reason);
    await supervisor.page.fill('#code', await totp(supervisor));
    await supervisor.page.click(`main button:has-text("${button}")`);
    await supervisor.page.waitForSelector('.flash.ok');
  };
  await toggleWheel('停用轉盤', '維護');
  await C.page.goto(`${BASE}/game/wheel`);
  check('停用後無法轉動', await C.page.isDisabled('#wheel-spin'));
  check('停用後畫面有提示', (await C.page.textContent('main')).includes('轉盤目前暫停服務'));
  await toggleWheel('啟用轉盤', '恢復');
  await C.page.goto(`${BASE}/game/wheel`);
  check('重新啟用後可以轉動', !(await C.page.isDisabled('#wheel-spin')));
  await supervisor.page.goto(`${BASE}/admin/audit?action=minigame.`);
  const minigameAudit = await supervisor.page.textContent('main');
  check('開關有稽核紀錄', minigameAudit.includes('minigame.disabled') && minigameAudit.includes('minigame.enabled'));

  // 玩過遊戲的點數一樣能走完退款。
  await submitRefund(C, 10000);
  const carolRefundId = await latestRefundId(reviewer);
  await reviewerApprove(reviewer, carolRefundId);
  await supervisorApprove(supervisor, carolRefundId);
  check('玩過遊戲的點數可以退款撥款', await waitForText(C, `${BASE}/game/refunds`, '已完成'));

  await supervisor.page.goto(`${BASE}/admin/minigame`);
  const minigamePage = await supervisor.page.textContent('main');
  check('後台帳務核對通過', minigamePage.includes('核對通過'));
  check('後台顯示回報率設定值', minigamePage.includes('95%'));
  await shot(supervisor, '23-admin-minigame');
  const carolCheck = execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'minigame:check'], { encoding: 'utf8' });
  check('minigame:check 通過', carolCheck.includes('帳務核對通過'), carolCheck);

  // 重置測試資料：玩家清空，稽核紀錄保留。
  await supervisor.page.goto(`${BASE}/admin/demo`);
  await supervisor.page.fill('#confirm', 'RESET');
  await supervisor.page.click('main button:has-text("重置")');
  await supervisor.page.waitForSelector('.flash.ok');
  await supervisor.page.goto(`${BASE}/admin/audit?action=refund.paid_out`);
  check('重置後稽核紀錄仍然保留', (await supervisor.page.locator('main').textContent()).includes('refund.paid_out'));
  await A.page.goto(`${BASE}/game`);
  check('重置後玩家資料已清除', A.page.url().endsWith('/game/login'));

  await browser.close();
  console.log(`\n通過 ${passed} 項，失敗 ${problems.length} 項`);
  if (problems.length) { console.log(problems.join('\n')); process.exit(1); }
})().catch((e) => { console.error('中斷：', e); process.exit(2); });
