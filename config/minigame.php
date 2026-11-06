<?php

return [
    'wheel' => [
        // 檔位都是 100 的倍數，0.5 倍的獎勵一定是整數。
        'bets' => [100, 500, 1000],
        'target_rtp_pct' => 95,
        // 依序對應 roll 0–99。份數總和必須是 100；回報率由測試窮舉檢查。
        'segments' => [
            ['key' => 'x0', 'label' => '0 倍', 'weight' => 48, 'multiplier_pct' => 0],
            ['key' => 'x05', 'label' => '0.5 倍', 'weight' => 20, 'multiplier_pct' => 50],
            ['key' => 'x1', 'label' => '1 倍', 'weight' => 15, 'multiplier_pct' => 100],
            ['key' => 'x2', 'label' => '2 倍', 'weight' => 10, 'multiplier_pct' => 200],
            ['key' => 'x5', 'label' => '5 倍', 'weight' => 4, 'multiplier_pct' => 500],
            ['key' => 'x10', 'label' => '10 倍', 'weight' => 3, 'multiplier_pct' => 1000],
        ],
    ],
    // 排程只補做這麼久沒有更新的局，避開正在進行中的請求（內部 API 逾時為 10 秒）。
    'retry_after_seconds' => 60,
    // 開局後這麼久仍沒扣點成功，就作廢扣點、判定這一局未成立。等於簽章時間窗，
    // 超過後重新簽章的舊請求也會被拒。已扣點的發獎不受這個期限影響，會一直重試。
    'debit_void_after_seconds' => 300,
    // 超過這個時間仍未完成的局在後台標示。
    'alert_after_minutes' => 10,
];
