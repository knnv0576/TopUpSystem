// 充值頁：依選擇的方案與付款方式更新右側結帳欄。實際金額以伺服器建立的訂單為準。
// 放在外部檔案，符合 CSP（不允許行內腳本）。
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('topup-form');
  if (!form) return;
  var fmt = function (n) { return Number(n).toLocaleString('en-US'); };

  function update() {
    var pkg = form.querySelector('input[name=package_id]:checked');
    var method = form.querySelector('input[name=method]:checked');
    document.getElementById('sum-name').textContent = pkg ? pkg.dataset.name : '請選擇方案';
    document.getElementById('sum-points').textContent = pkg ? fmt(pkg.dataset.points) + ' 點' : '—';
    document.getElementById('sum-method').textContent = method ? method.dataset.label : '—';
    var price = document.getElementById('sum-price');
    price.textContent = pkg ? 'NT$' + fmt(pkg.dataset.price) : '—';
    price.classList.remove('bump');
    void price.offsetWidth; // 重新觸發數字跳動動畫
    price.classList.add('bump');
    document.getElementById('topup-submit').disabled = !pkg;
  }

  form.addEventListener('change', update);
  form.addEventListener('submit', function () {
    var button = document.getElementById('topup-submit');
    button.disabled = true;
    button.textContent = '建立訂單中…';
  });
  update();
});
