<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * 模擬付款頁的付款方式與格式驗證。
 *
 * 信用卡只接受 TEST_CARDS 裡的測試卡號，格式正確的真實卡號也會被拒絕；
 * 卡號、安全碼都不寫入資料庫、日誌或 session，付款結果由測試卡號決定。
 */
final class MockCheckout
{
    /** 測試卡號 => [卡別, 付款情境, 說明] */
    public const TEST_CARDS = [
        '4111111111111111' => ['visa', 'success', '付款成功'],
        '5555555555554444' => ['mastercard', 'success', '付款成功'],
        '3530111333300000' => ['jcb', 'success', '付款成功'],
        '378282246310005' => ['amex', 'success', '付款成功（安全碼 4 碼）'],
        '4000000000000002' => ['visa', 'declined', '付款失敗（發卡行拒絕交易，例如額度不足）'],
        '4000000000000036' => ['visa', 'delayed', '付款成功（延遲通知）'],
        '4000000000000044' => ['visa', 'duplicate', '付款成功（重複通知）'],
    ];

    /** 與付款資料無關、模擬金流通知被竄改的情境。 */
    public const ADVANCED_SCENARIOS = [
        'bad_signature' => '錯誤簽章',
        'amount_mismatch' => '金額不符',
    ];

    public const BRANDS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'jcb' => 'JCB',
        'amex' => 'American Express',
    ];

    /** 模擬銀行的代碼；不對應任何真實銀行。 */
    public const ATM_BANK_CODE = '999';

    /** 超商代碼的單筆上限（元）。 */
    public const CVS_LIMIT = 20000;

    public static function digits(string $value): string
    {
        return preg_replace('/[\s-]/', '', $value) ?? '';
    }

    public static function luhn(string $number): bool
    {
        $sum = 0;
        foreach (array_reverse(str_split($number)) as $i => $char) {
            $digit = (int) $char;
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $number): ?string
    {
        $two = (int) substr($number, 0, 2);
        $four = (int) substr($number, 0, 4);
        $length = strlen($number);

        return match (true) {
            $number[0] === '4' && in_array($length, [13, 16, 19], true) => 'visa',
            (($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720)) && $length === 16 => 'mastercard',
            $four >= 3528 && $four <= 3589 && $length >= 16 && $length <= 19 => 'jcb',
            ($two === 34 || $two === 37) && $length === 15 => 'amex',
            default => null,
        };
    }

    /** 依卡別分組顯示：American Express 是 4-6-5，其他每 4 碼一組。 */
    public static function group(string $number): string
    {
        if (self::brand($number) === 'amex') {
            return substr($number, 0, 4).' '.substr($number, 4, 6).' '.substr($number, 10);
        }

        return trim(chunk_split($number, 4, ' '));
    }

    public static function cvvLength(string $brand): int
    {
        return $brand === 'amex' ? 4 : 3;
    }

    /**
     * 驗證信用卡欄位，回傳 [錯誤（欄位 => 訊息）, 付款情境]。
     * 錯誤訊息不會帶出使用者輸入的卡號或安全碼。
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    public static function validateCard(array $input, Carbon $now): array
    {
        $errors = [];
        $name = trim((string) ($input['card_name'] ?? ''));
        $number = self::digits((string) ($input['card_number'] ?? ''));
        $expiry = trim((string) ($input['card_expiry'] ?? ''));
        $cvv = trim((string) ($input['card_cvv'] ?? ''));

        if ($name === '') {
            $errors['card_name'] = '請輸入持卡人姓名';
        } elseif (! preg_match('/^[A-Za-z][A-Za-z .\-]{1,49}$/', $name)) {
            $errors['card_name'] = '持卡人姓名請輸入卡片上的英文姓名（2 到 50 個英文字母，可含空格、句點、連字號）';
        }

        $brand = null;
        if ($number === '') {
            $errors['card_number'] = '請輸入卡號';
        } elseif (! preg_match('/^\d{13,19}$/', $number)) {
            $errors['card_number'] = '卡號必須是 13 到 19 位數字';
        } elseif (! self::luhn($number)) {
            $errors['card_number'] = '卡號檢查碼不正確，請確認是否輸入錯誤';
        } elseif (($brand = self::brand($number)) === null) {
            $errors['card_number'] = '不支援這個卡別（支援 Visa、Mastercard、JCB、American Express）';
        }

        if (! preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $m)) {
            $errors['card_expiry'] = '有效期限請以 MM/YY 格式輸入，例如 08/29';
        } else {
            $end = Carbon::create(2000 + (int) $m[2], (int) $m[1], 1, 0, 0, 0, $now->getTimezone())->endOfMonth();
            if ($end->lt($now)) {
                $errors['card_expiry'] = '這張卡已過期';
            } elseif ($end->gt($now->copy()->addYears(20))) {
                $errors['card_expiry'] = '有效期限不正確';
            }
        }

        $cvvLength = $brand ? self::cvvLength($brand) : null;
        if (! preg_match('/^\d{3,4}$/', $cvv)) {
            $errors['card_cvv'] = '安全碼必須是 3 或 4 位數字';
        } elseif ($cvvLength !== null && strlen($cvv) !== $cvvLength) {
            $errors['card_cvv'] = "這個卡別的安全碼是 {$cvvLength} 位數字";
        }

        if ($errors === [] && ! isset(self::TEST_CARDS[$number])) {
            $errors['card_number'] = '這是模擬金流，只接受頁面上列出的測試卡號';
        }

        return [$errors, $errors === [] ? self::TEST_CARDS[$number][1] : null];
    }

    /** 14 碼虛擬帳號：由訂單編號導出，同一張訂單固定不變，最後一碼是檢查碼。 */
    public static function virtualAccount(Order $order): string
    {
        $body = '';
        foreach (str_split(self::hash('atm', $order)) as $hex) {
            $body .= (string) (hexdec($hex) % 10);
            if (strlen($body) === 13) {
                break;
            }
        }
        for ($check = 0; $check < 10; $check++) {
            if (self::luhn($body.$check)) {
                return $body.$check;
            }
        }

        return $body.'0';
    }

    /** 14 碼超商繳費代碼：DM 開頭，避開容易看錯的 0、O、1、I。 */
    public static function storeCode(Order $order): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = 'DM';
        foreach (str_split(substr(self::hash('cvs', $order), 0, 24), 2) as $byte) {
            $code .= $alphabet[hexdec($byte) % 32];
        }

        return $code;
    }

    public static function cvsAvailable(Order $order): bool
    {
        return $order->amount <= self::CVS_LIMIT;
    }

    private static function hash(string $purpose, Order $order): string
    {
        return hash_hmac('sha256', "$purpose:{$order->order_no}", (string) config('topup.mock_gateway_secret'));
    }
}
