<?php

return [
    // 固定匯率與退款規則。
    'points_per_twd' => 100,
    'refund_min_points' => 10000,
    // 送出退款後最多等多久讓背景工作完成凍結，畫面上的可用點數才會立即正確（毫秒）。
    'refund_freeze_wait_ms' => 3000,
    // 客服信箱：新工單與玩家回覆的通知寄到這裡（本機 Mailpit）。
    'support_inbox' => env('SUPPORT_INBOX', 'support@topup-demo.test'),
    'refund_points_step' => 100,

    'internal_api_url' => env('INTERNAL_API_URL', 'http://web'),
    // 充值端呼叫玩家端、小遊戲呼叫扣點 API、SSO token 都用 game_api_secret。
    'game_api_secret' => env('GAME_API_SECRET'),
    // 玩家端呼叫充值端用 topup_api_secret。
    'topup_api_secret' => env('TOPUP_API_SECRET'),
    'mock_gateway_secret' => env('MOCK_GATEWAY_SECRET'),

    'signature_window_seconds' => 300,
    'sso_ttl_seconds' => 60,
    'order_ttl_minutes' => 30,
    'review_overdue_hours' => (int) env('REVIEW_OVERDUE_HOURS', 72),
    'mock_delay_seconds' => 30,

    // 單一帳號連續失敗幾次後鎖定多久（玩家與管理員相同）。
    'lockout_attempts' => 5,
    'lockout_minutes' => 15,

    'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', '127.0.0.1,::1'))))),
];
