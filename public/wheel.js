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

    // 遊戲內充值：連線失敗時沿用同一個 request_id，再按一次也只會有一張訂單。
    var pendingRequests = {};

    async function pollTopup(orderNo) {
      var deadline = Date.now() + POLL_LIMIT_MS;
      while (Date.now() < deadline) {
        await sleep(POLL_INTERVAL_MS);
        var result = await requestJson(topupUrl + '/' + encodeURIComponent(orderNo));
        if (result.data && (result.data.state === 'success' || result.data.state === 'failed')) return result.data;
      }
      return null;
    }

    document.querySelectorAll('[data-topup]').forEach(function (button) {
      button.addEventListener('click', async function () {
        var packageId = button.dataset.topup;
        pendingRequests[packageId] = pendingRequests[packageId] || randomUuid();
        button.disabled = true;
        setMessage(topupMessage, '充值處理中', 'pending');

        var result = await requestJson(topupUrl, {
          method: 'POST',
          headers: jsonHeaders(),
          body: JSON.stringify({ package_id: Number(packageId), request_id: pendingRequests[packageId] }),
        });

        if (!result.data) {
          if (result.redirected) { window.location.reload(); return; }
          setMessage(topupMessage, '連線異常，請再按一次，不會重複充值', 'error');
          button.disabled = false;
          return;
        }
        if (result.status === 503) {
          setMessage(topupMessage, result.data.message, 'error');
          button.disabled = false;
          return;
        }

        // 伺服器已明確回應，下一次按下就是新的充值。
        delete pendingRequests[packageId];

        if (!result.ok) {
          setMessage(topupMessage, result.data.message, 'error');
          button.disabled = false;
          return;
        }

        var order = result.data.state === 'processing' ? await pollTopup(result.data.order_no) : result.data;
        if (!order) {
          setMessage(topupMessage, '充值處理中，請稍後到充值訂單查看', 'pending');
        } else {
          setMessage(topupMessage, order.message, order.state === 'success' ? 'win' : 'error');
          updateAvailable(order.available);
        }
        button.disabled = false;
      });
    });
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
