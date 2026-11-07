// 放在外部檔案，符合 CSP（不允許行內腳本）。
// 結果一律由伺服器決定；這裡只負責畫面、送出請求，以及在瀏覽器獨立驗算。
(function () {
  'use strict';

  var POLL_INTERVAL_MS = 2000;
  var POLL_LIMIT_MS = 30000;
  var SPIN_MS = 4000;
  var CONFIRM_STORAGE_KEY = 'wheel.confirmBeforeSpin';

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function randomHex(bytes) {
    var values = crypto.getRandomValues(new Uint8Array(bytes));
    return Array.prototype.map.call(values, function (v) { return v.toString(16).padStart(2, '0'); }).join('');
  }

  function randomUuid() {
    return crypto.randomUUID();
  }

  /** 回傳 { ok, status, data }；連線失敗或收到非 JSON（例如被導向登入頁）時 data 是 null。 */
  async function requestJson(url, options) {
    try {
      var response = await fetch(url, Object.assign({
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }, options || {}));
      var type = response.headers.get('content-type') || '';
      var data = type.indexOf('application/json') >= 0 ? await response.json() : null;
      return { ok: response.ok, status: response.status, data: data, redirected: response.redirected };
    } catch (e) {
      return { ok: false, status: 0, data: null, redirected: false };
    }
  }

  function initGame(app) {
    var csrf = app.dataset.csrf;
    var baseUrl = app.dataset.spinUrl;
    var topupUrl = app.dataset.topupUrl;

    var rotor = document.getElementById('wheel-rotor');
    var spinButton = document.getElementById('wheel-spin');
    var message = document.getElementById('wheel-message');
    var available = document.getElementById('wheel-available');
    var hash = document.getElementById('wheel-hash');
    var seedInput = document.getElementById('wheel-client-seed');
    var confirmToggle = document.getElementById('wheel-confirm-toggle');
    var dialog = document.getElementById('wheel-confirm-dialog');
    var topupMessage = document.getElementById('topup-message');

    var publicId = app.dataset.publicId;
    var totalRotation = 0;
    var spinEnabledByServer = !spinButton.disabled;

    seedInput.value = randomHex(16);

    // 這個設定只是防誤按，存在瀏覽器；預設開啟。
    var storedConfirm = null;
    try { storedConfirm = localStorage.getItem(CONFIRM_STORAGE_KEY); } catch (e) { /* 無法存取時用預設值 */ }
    confirmToggle.checked = storedConfirm !== '0';
    confirmToggle.addEventListener('change', function () {
      try { localStorage.setItem(CONFIRM_STORAGE_KEY, confirmToggle.checked ? '1' : '0'); } catch (e) { /* 忽略 */ }
    });

    function jsonHeaders() {
      return { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf };
    }

    function setMessage(el, text, kind) {
      el.textContent = text || '';
      el.className = el.id === 'wheel-message' ? 'wheel-message' : '';
      if (kind) el.classList.add('is-' + kind);
    }

    function updateAvailable(value) {
      if (typeof value === 'number') available.textContent = value.toLocaleString('en-US');
    }

    function selectedBet() {
      return Number(document.querySelector('input[name=bet]:checked').value);
    }

    function setNextRound(next) {
      if (!next) return;
      publicId = next.public_id;
      hash.textContent = next.server_seed_hash;
      seedInput.value = randomHex(16);
    }

    /** 指針固定在正上方，轉到 roll 對應的角度；這樣停的位置一定和結果一致。 */
    function rotateTo(roll) {
      var target = (roll + 0.5) * 3.6;
      var current = totalRotation % 360;
      totalRotation += 360 * 5 + (360 - target - current + 720) % 360;
      rotor.style.transform = 'rotate(' + totalRotation + 'deg)';
    }

    function resetSpinButton() {
      spinButton.disabled = !spinEnabledByServer;
    }

    async function pollRound(id) {
      var deadline = Date.now() + POLL_LIMIT_MS;
      while (Date.now() < deadline) {
        await sleep(POLL_INTERVAL_MS);
        var result = await requestJson(baseUrl + '/' + id);
        if (result.data && result.data.state !== 'processing') return result.data;
      }
      return null;
    }

    async function showRound(data) {
      if (data.state === 'crediting' || data.state === 'settled') {
        rotateTo(data.roll);
        // 系統設定減少動態效果時轉盤沒有動畫，不必等。
        await sleep(window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : SPIN_MS);
        setMessage(message, data.state === 'crediting' ? data.message + '（入帳中）' : data.message, data.outcome);
      } else if (data.state === 'processing') {
        setMessage(message, data.message, 'pending');
      } else {
        setMessage(message, data.message, 'error');
      }
      updateAvailable(data.available);
    }

    async function spin() {
      spinButton.disabled = true;
      setMessage(message, '處理中，請稍候', 'pending');

      var id = publicId;
      var result = await requestJson(baseUrl + '/' + id + '/spin', {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ bet: selectedBet(), client_seed: seedInput.value.trim() }),
      });

      if (!result.data) {
        // 斷線時這一局不會取消：結果已由伺服器決定，可到紀錄頁查看。
        if (result.redirected) { window.location.reload(); return; }
        setMessage(message, '連線中斷。如果這一局已經送出，結果會保留在遊戲紀錄頁。', 'error');
        resetSpinButton();
        return;
      }

      if (!result.ok) {
        setMessage(message, result.data.message || '無法轉動，請稍後再試', 'error');
        resetSpinButton();
        return;
      }

      var data = result.data;
      setNextRound(data.next);

      if (data.state === 'processing') {
        setMessage(message, data.message, 'pending');
        var finished = await pollRound(id);
        if (!finished) {
          setMessage(message, '仍在處理中，請稍後到遊戲紀錄頁查看。這一局不會重複扣點。', 'pending');
          resetSpinButton();
          return;
        }
        data = finished;
      }

      await showRound(data);
      resetSpinButton();
    }

    spinButton.addEventListener('click', function () {
      if (confirmToggle.checked) {
        document.getElementById('wheel-confirm-bet').textContent = selectedBet().toLocaleString('en-US');
        dialog.showModal();
      } else {
        spin();
      }
    });
    document.getElementById('wheel-confirm-cancel').addEventListener('click', function () { dialog.close(); });
    document.getElementById('wheel-confirm-ok').addEventListener('click', function () {
      dialog.close();
      spin();
    });

    initTopup();

    // 遊戲內充值：彈窗內選付款方式 → 建立訂單 → 輸入付款資料 → 等待金流通知。
    // 建立訂單連線失敗時沿用同一個 request_id，再按一次也只會有一張訂單。
    function initTopup() {
      var topupDialog = document.getElementById('topup-dialog');
      if (!topupDialog) return;

      var createButton = document.getElementById('topup-create');
      var chooseError = document.getElementById('topup-choose-error');
      var payError = document.getElementById('topup-pay-error');
      var resultBox = document.getElementById('topup-result');
      var testCards = document.getElementById('topup-test-cards');
      var payForms = topupDialog.querySelectorAll('form[data-topup-pay]');
      var current = null; // { packageId, price, points, requestId, method, orderNo }

      function showStep(step) {
        topupDialog.querySelectorAll('[data-topup-step]').forEach(function (section) {
          section.hidden = section.dataset.topupStep !== step;
        });
      }

      function text(id, value) { document.getElementById(id).textContent = value; }

      function formatTwd(value) { return Number(value).toLocaleString('en-US') + ' 元'; }

      function clearFormErrors() {
        payError.textContent = '';
        topupDialog.querySelectorAll('[data-error-for]').forEach(function (box) { box.textContent = ''; });
        topupDialog.querySelectorAll('[aria-invalid]').forEach(function (input) { input.removeAttribute('aria-invalid'); });
      }

      // 卡號與安全碼送出後一律清空，與付款頁相同：不留在畫面上。
      function clearSensitive() {
        ['card_number', 'card_cvv'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('card-brand').textContent = '';
      }

      function resetDialog() {
        payForms.forEach(function (form) { form.reset(); });
        clearFormErrors();
        clearSensitive();
        chooseError.textContent = '';
        resultBox.textContent = '';
        resultBox.className = 'wheel-message';
        topupDialog.querySelectorAll('.topup-order-row').forEach(function (row) { row.hidden = true; });
        createButton.disabled = false;
        showStep('choose');
      }

      function showResult(message, kind) {
        resultBox.textContent = message;
        resultBox.className = 'wheel-message' + (kind ? ' is-' + kind : '');
        setMessage(topupMessage, message, kind);
        showStep('result');
      }

      function renderTestCards(cards) {
        testCards.replaceChildren();
        (cards || []).forEach(function (card) {
          var row = document.createElement('tr');
          var number = document.createElement('td');
          var code = document.createElement('span');
          code.className = 'code';
          code.textContent = card.display;
          number.appendChild(code);
          var brand = document.createElement('td');
          brand.textContent = card.brand;
          var label = document.createElement('td');
          label.textContent = card.label;
          var action = document.createElement('td');
          var fill = document.createElement('button');
          fill.type = 'button';
          fill.className = 'secondary small';
          fill.dataset.fillCard = card.number;
          fill.textContent = '填入';
          action.appendChild(fill);
          row.append(number, brand, label, action);
          testCards.appendChild(row);
        });
      }

      function showPayment(order) {
        var payment = order.payment;
        current.orderNo = order.order_no;
        text('topup-order-no', order.order_no);
        text('topup-expires', payment.expires_at);
        topupDialog.querySelectorAll('.topup-order-row').forEach(function (row) { row.hidden = false; });

        topupDialog.querySelectorAll('[data-pay-panel]').forEach(function (panel) {
          panel.hidden = panel.dataset.payPanel !== payment.method;
        });
        topupDialog.querySelectorAll('[data-check=amount]').forEach(function (input) { input.dataset.amount = order.amount; });
        topupDialog.querySelector('[data-pay-label]').textContent = '確認付款 ' + formatTwd(order.amount);

        if (payment.method === 'card') renderTestCards(payment.test_cards);
        if (payment.method === 'atm') {
          text('topup-atm-bank', payment.bank);
          text('topup-atm-account', payment.account.replace(/(\d{4})(?=\d)/g, '$1 '));
        }
        if (payment.method === 'cvs') text('topup-cvs-code', payment.code);

        showStep('pay');
        var first = topupDialog.querySelector('[data-pay-panel="' + payment.method + '"] input');
        if (first) first.focus();
      }

      async function pollTopup(orderNo) {
        // 延遲通知約 30 秒才到，等久一點。
        var deadline = Date.now() + POLL_LIMIT_MS * 2;
        while (Date.now() < deadline) {
          await sleep(POLL_INTERVAL_MS);
          var result = await requestJson(topupUrl + '/' + encodeURIComponent(orderNo));
          if (result.data && ['success', 'failed', 'expired'].indexOf(result.data.state) >= 0) return result.data;
        }
        return null;
      }

      // 等待結果期間玩家可能關掉彈窗、改充值別的方案：這時 current 已經換成新的一筆，
      // 舊的結果只更新頁面上的訊息與點數，不能蓋掉新彈窗的內容。
      function isStale(flow) { return flow !== current; }

      function showResultFor(flow, message, kind) {
        if (isStale(flow)) setMessage(topupMessage, message, kind);
        else showResult(message, kind);
      }

      async function waitForResult(flow) {
        showResultFor(flow, '付款已送出，等待金流通知…', 'pending');
        var order = await pollTopup(flow.orderNo);
        if (!order) {
          showResultFor(flow, '還沒收到付款結果，請稍後到充值訂單查看。這筆訂單不會重複付款。', 'pending');
          return;
        }
        showResultFor(flow, order.message, order.state === 'success' ? 'win' : 'error');
        updateAvailable(order.available);
      }

      document.querySelectorAll('[data-topup]').forEach(function (button) {
        button.addEventListener('click', function () {
          current = {
            packageId: Number(button.dataset.topup),
            requestId: randomUuid(),
          };
          resetDialog();
          text('topup-amount', formatTwd(button.dataset.price));
          text('topup-points', Number(button.dataset.points).toLocaleString('en-US') + ' 點');
          topupDialog.showModal();
        });
      });

      topupDialog.querySelectorAll('[data-topup-close]').forEach(function (button) {
        button.addEventListener('click', function () { topupDialog.close(); });
      });
      // 關閉時清掉付款資料，不留在頁面上。
      topupDialog.addEventListener('close', function () {
        payForms.forEach(function (form) { form.reset(); });
        clearSensitive();
      });

      // 步驟 1 → 2：建立訂單（還不付款）。
      createButton.addEventListener('click', async function () {
        var flow = current;
        var method = topupDialog.querySelector('input[name=topup-method]:checked').value;
        // 換了付款方式就是另一筆充值。
        if (flow.method && flow.method !== method) flow.requestId = randomUuid();
        flow.method = method;
        createButton.disabled = true;
        chooseError.textContent = '';

        var result = await requestJson(topupUrl, {
          method: 'POST',
          headers: jsonHeaders(),
          body: JSON.stringify({ package_id: flow.packageId, request_id: flow.requestId, method: method }),
        });
        // 已經換成別的充值：這筆訂單還沒付款，逾期自動失效，不用再處理。
        if (isStale(flow)) return;
        createButton.disabled = false;

        if (!result.data) {
          if (result.redirected) { window.location.reload(); return; }
          chooseError.textContent = '連線異常，請再按一次，不會重複建立訂單';
          return;
        }
        if (!result.ok) {
          chooseError.textContent = result.data.message || '無法建立訂單，請稍後再試';
          // 503 不確定有沒有建立，沿用同一個 request_id；其他錯誤下一次就是新的充值。
          if (result.status !== 503) flow.requestId = randomUuid();
          return;
        }

        if (result.data.state === 'awaiting_payment') {
          showPayment(result.data);
        } else if (result.data.state === 'processing') {
          flow.orderNo = result.data.order_no;
          await waitForResult(flow);
        } else {
          showResult(result.data.message, result.data.state === 'success' ? 'win' : 'error');
          updateAvailable(result.data.available);
        }
      });

      // 步驟 2 → 3：送出付款資料。mock-pay.js 的格式檢查不通過時已經擋下（defaultPrevented）。
      payForms.forEach(function (form) {
        form.addEventListener('submit', async function (event) {
          if (event.defaultPrevented) return;
          event.preventDefault();

          var flow = current;
          var submit = form.querySelector('button[type=submit]');
          var payload = {};
          new FormData(form).forEach(function (value, key) { payload[key] = value; });
          submit.disabled = true;
          clearFormErrors();

          var result = await requestJson(topupUrl + '/' + encodeURIComponent(flow.orderNo) + '/pay', {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify(payload),
          });
          payload = null;
          submit.disabled = false;

          if (!result.data) {
            if (result.redirected) { window.location.reload(); return; }
            // 不確定有沒有送出，不自動重送，改查詢結果。
            await waitForResult(flow);
            return;
          }
          // 付款已送出但玩家換成別的充值：只在頁面上顯示這一筆的結果。
          if (isStale(flow)) {
            if (result.ok && result.data.state !== 'awaiting_payment') await waitForResult(flow);
            return;
          }
          clearSensitive();
          if (result.status === 422) {
            payError.textContent = result.data.message;
            Object.keys(result.data.errors || {}).forEach(function (field) {
              var box = topupDialog.querySelector('[data-error-for="' + field + '"]');
              var input = document.getElementById(field);
              if (box) box.textContent = result.data.errors[field];
              else payError.textContent = result.data.errors[field];
              if (input) input.setAttribute('aria-invalid', 'true');
            });
            return;
          }
          if (result.status === 503 && result.data.uncertain) {
            await waitForResult(flow);
            return;
          }
          if (!result.ok) {
            showResult(result.data.message || '無法付款，請稍後再試', 'error');
            return;
          }

          if (result.data.state === 'success' || result.data.state === 'failed') {
            showResult(result.data.message, result.data.state === 'success' ? 'win' : 'error');
            updateAvailable(result.data.available);
          } else {
            await waitForResult(flow);
          }
        });
      });
    }
  }

  /** 和 WheelOutcome.php 相同的規則，但完全在瀏覽器計算。 */
  async function verifiedRoll(serverSeed, clientSeed, publicId) {
    var encoder = new TextEncoder();
    var key = await crypto.subtle.importKey('raw', encoder.encode(serverSeed), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    for (var attempt = 0; attempt < 1000; attempt++) {
      var message = encoder.encode(clientSeed + ':' + publicId + ':' + attempt);
      var digest = await crypto.subtle.sign('HMAC', key, message);
      var value = new DataView(digest).getUint32(0, false);
      if (value < 4294967200) return value % 100;
    }
    throw new Error('無法計算');
  }

  async function sha256Hex(text) {
    var digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return Array.prototype.map.call(new Uint8Array(digest), function (v) { return v.toString(16).padStart(2, '0'); }).join('');
  }

  function initVerify(root) {
    var segments = JSON.parse(root.dataset.segments);

    function multiplierFor(roll) {
      var upTo = 0;
      for (var i = 0; i < segments.length; i++) {
        upTo += segments[i].weight;
        if (roll < upTo) return segments[i].multiplier_pct;
      }
      return null;
    }

    root.querySelectorAll('[data-verify]').forEach(function (button) {
      button.addEventListener('click', async function () {
        var output = button.parentElement.querySelector('[data-verify-result]');
        var d = button.dataset;
        try {
          var hashOk = (await sha256Hex(d.serverSeed)) === d.hash;
          var roll = await verifiedRoll(d.serverSeed, d.clientSeed, d.publicId);
          var resultOk = roll === Number(d.roll) && multiplierFor(roll) === Number(d.multiplier);
          output.textContent = hashOk && resultOk ? '驗證通過' : '驗證失敗';
          output.className = 'badge ' + (hashOk && resultOk ? 'ok' : 'err');
        } catch (e) {
          output.textContent = '無法驗證';
          output.className = 'badge err';
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var app = document.getElementById('wheel-app');
    if (app) initGame(app);
    var verify = document.getElementById('wheel-verify');
    if (verify) initVerify(verify);
  });
})();
