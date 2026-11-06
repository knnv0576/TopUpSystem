// 放在外部檔案，符合 CSP（不允許行內腳本）。
document.addEventListener('DOMContentLoaded', function () {
  // 把單次 token 以 POST 送到充值網站，token 不會出現在網址。
  var auto = document.querySelector('form[data-autosubmit]');
  if (auto) auto.submit();

  // 退款的不可撤銷提示視窗：按下確認才會填入 acknowledged 並送出。
  document.querySelectorAll('form[data-dialog]').forEach(function (form) {
    var dialog = document.querySelector(form.dataset.dialog);
    if (!dialog) return;
    form.addEventListener('submit', function (event) {
      if (form.dataset.confirmed === '1') return;
      event.preventDefault();
      if (!form.reportValidity()) return;
      dialog.showModal();
    });
    dialog.querySelector('[data-dialog-cancel]').addEventListener('click', function () {
      dialog.close();
    });
    dialog.querySelector('[data-dialog-ok]').addEventListener('click', function () {
      form.querySelector('input[name=acknowledged]').value = '1';
      form.dataset.confirmed = '1';
      dialog.close();
      form.submit();
    });
  });

  // 一般的二次確認。
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  // 玩家中心的點數數字從 0 跑到實際值（只是畫面效果；系統設定減少動態效果時直接顯示）。
  if (document.body.classList.contains('theme-game') && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    // 有 id 的數字會由頁面腳本即時更新（例如轉盤的可用點數），不套用。
    document.querySelectorAll('.stat .value:not([id])').forEach(function (el) {
      var target = Number(el.textContent.replace(/[^\d-]/g, ''));
      if (!isFinite(target) || target === 0) return;
      var start = performance.now(), duration = 700, final = el.textContent;
      function step(now) {
        var t = Math.min(1, (now - start) / duration), eased = 1 - Math.pow(1 - t, 3);
        el.textContent = t < 1 ? Math.round(target * eased).toLocaleString('en-US') : final;
        if (t < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }
});
