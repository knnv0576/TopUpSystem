// 模擬付款頁的輸入格式與即時檢查。伺服器會再驗證一次，這裡只是提早提示。
// 放在外部檔案，符合 CSP（不允許行內腳本）。
document.addEventListener('DOMContentLoaded', function () {
  var BRANDS = { visa: 'Visa', mastercard: 'Mastercard', jcb: 'JCB', amex: 'American Express' };

  function digits(value) { return value.replace(/[\s-]/g, ''); }

  function luhn(number) {
    var sum = 0;
    for (var i = 0; i < number.length; i++) {
      var d = +number[number.length - 1 - i];
      if (i % 2 === 1) { d *= 2; if (d > 9) d -= 9; }
      sum += d;
    }
    return sum % 10 === 0;
  }

  function brand(number) {
    var two = +number.slice(0, 2), four = +number.slice(0, 4), len = number.length;
    if (number[0] === '4' && [13, 16, 19].indexOf(len) >= 0) return 'visa';
    if (((two >= 51 && two <= 55) || (four >= 2221 && four <= 2720)) && len === 16) return 'mastercard';
    if (four >= 3528 && four <= 3589 && len >= 16 && len <= 19) return 'jcb';
    if ((two === 34 || two === 37) && len === 15) return 'amex';
    return null;
  }

  // 輸入中先依開頭判斷卡別，用來決定分組與安全碼長度。
  function brandPrefix(number) {
    if (/^3[47]/.test(number)) return 'amex';
    if (/^4/.test(number)) return 'visa';
    if (/^(5[1-5]|2[2-7])/.test(number)) return 'mastercard';
    if (/^35/.test(number)) return 'jcb';
    return null;
  }

  function groupCard(number) {
    if (brandPrefix(number) === 'amex') {
      return [number.slice(0, 4), number.slice(4, 10), number.slice(10, 15)].filter(Boolean).join(' ');
    }
    return number.replace(/(\d{4})(?=\d)/g, '$1 ');
  }

  var checks = {
    name: function (v) {
      v = v.trim();
      if (v === '') return '請輸入持卡人姓名';
      if (!/^[A-Za-z][A-Za-z .\-]{1,49}$/.test(v)) return '持卡人姓名請輸入卡片上的英文姓名（2 到 50 個英文字母，可含空格、句點、連字號）';
      return '';
    },
    number: function (v) {
      var n = digits(v);
      if (n === '') return '請輸入卡號';
      if (!/^\d{13,19}$/.test(n)) return '卡號必須是 13 到 19 位數字';
      if (!luhn(n)) return '卡號檢查碼不正確，請確認是否輸入錯誤';
      if (!brand(n)) return '不支援這個卡別（支援 Visa、Mastercard、JCB、American Express）';
      return '';
    },
    expiry: function (v) {
      var m = /^(0[1-9]|1[0-2])\/(\d{2})$/.exec(v.trim());
      if (!m) return '有效期限請以 MM/YY 格式輸入，例如 08/29';
      var end = new Date(2000 + +m[2], +m[1], 0, 23, 59, 59);
      var now = new Date();
      if (end < now) return '這張卡已過期';
      if (end > new Date(now.getFullYear() + 20, now.getMonth(), now.getDate())) return '有效期限不正確';
      return '';
    },
    cvv: function (v, form) {
      v = v.trim();
      if (!/^\d{3,4}$/.test(v)) return '安全碼必須是 3 或 4 位數字';
      var b = brand(digits(form.querySelector('[data-check=number]').value));
      var len = b === 'amex' ? 4 : 3;
      if (b && v.length !== len) return '這個卡別的安全碼是 ' + len + ' 位數字';
      return '';
    },
    digits: function (v, form, input) {
      var n = digits(v), len = +input.dataset.length;
      if (!new RegExp('^\\d{' + len + '}$').test(n)) return input.dataset.label + '必須是 ' + len + ' 位數字';
      return '';
    },
    code: function (v, form, input) {
      if (!/^[A-Z0-9]{14}$/.test(digits(v).toUpperCase())) return input.dataset.label + '必須是 14 個英文字母或數字';
      return '';
    },
    amount: function (v, form, input) {
      v = v.trim();
      if (!/^\d{1,9}$/.test(v)) return input.dataset.label + '必須是整數';
      if (+v !== +input.dataset.amount) return input.dataset.label + '必須等於應繳金額 ' + (+input.dataset.amount).toLocaleString('en-US') + ' 元';
      return '';
    },
  };

  function showError(input, message) {
    var box = input.form.querySelector('[data-error-for="' + input.name + '"]');
    if (box) box.textContent = message;
    if (message) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
  }

  function check(input) {
    var fn = checks[input.dataset.check];
    if (!fn) return true;
    var message = fn(input.value, input.form, input);
    showError(input, message);
    return message === '';
  }

  document.querySelectorAll('form.pay-form').forEach(function (form) {
    var inputs = form.querySelectorAll('[data-check]');
    inputs.forEach(function (input) {
      if (input.form.querySelector('[data-error-for="' + input.name + '"]').textContent.trim() !== '') {
        input.setAttribute('aria-invalid', 'true');
      }
      input.addEventListener('blur', function () { if (input.value !== '') check(input); });
    });
    form.addEventListener('submit', function (event) {
      var first = null;
      inputs.forEach(function (input) { if (!check(input) && !first) first = input; });
      if (first) { event.preventDefault(); first.focus(); }
    });
  });

  // 信用卡：卡號分組、顯示卡別、安全碼長度；有效期限自動補斜線。
  var number = document.getElementById('card_number');
  var cvv = document.getElementById('card_cvv');
  var expiry = document.getElementById('card_expiry');
  var brandLabel = document.getElementById('card-brand');

  function onNumberInput() {
    var n = digits(number.value).replace(/\D/g, '').slice(0, 19);
    number.value = groupCard(n);
    var b = brand(n) || brandPrefix(n);
    brandLabel.textContent = b ? BRANDS[b] : '';
    var len = b === 'amex' ? 4 : 3;
    cvv.maxLength = len;
    cvv.placeholder = len + ' 碼';
  }

  if (number) {
    number.addEventListener('input', onNumberInput);
    expiry.addEventListener('input', function (event) {
      var v = expiry.value.replace(/\D/g, '').slice(0, 4);
      if (v.length === 1 && +v > 1) v = '0' + v;
      if (v.length >= 3 || (v.length === 2 && event.inputType !== 'deleteContentBackward')) v = v.slice(0, 2) + '/' + v.slice(2);
      expiry.value = v;
    });

    // 「填入」按鈕：帶入測試卡號，其他空白欄位補上可用的測試值。
    // 用事件委派，之後才加進頁面的按鈕（遊戲內充值彈窗）也適用。
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-fill-card]');
      if (!button) return;
      var n = button.dataset.fillCard;
      number.value = n;
      onNumberInput();
      var name = document.getElementById('card_name');
      if (name.value.trim() === '') name.value = 'TEST USER';
      if (expiry.value.trim() === '' || checks.expiry(expiry.value) !== '') {
        var d = new Date();
        expiry.value = ('0' + (d.getMonth() + 1)).slice(-2) + '/' + String((d.getFullYear() + 3) % 100).padStart(2, '0');
      }
      cvv.value = brand(n) === 'amex' ? '1234' : '123';
      [name, number, expiry, cvv].forEach(check);
      number.scrollIntoView({ block: 'center' });
    });
  }

  // 超商代碼一律轉大寫。
  var cvsCode = document.getElementById('cvs_code');
  if (cvsCode) {
    cvsCode.addEventListener('input', function () { cvsCode.value = cvsCode.value.toUpperCase(); });
  }
});
