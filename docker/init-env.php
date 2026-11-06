<?php

// 由 .env.example 產生 .env，並為空白的密鑰填入隨機值。已存在的 .env 不會被覆蓋，除非加上 --force。

$root = dirname(__DIR__);
$target = "$root/.env";

if (file_exists($target) && ! in_array('--force', $argv, true)) {
    fwrite(STDERR, ".env already exists; use --force to overwrite\n");
    exit(1);
}

$generators = [
    'APP_KEY' => fn () => 'base64:'.base64_encode(random_bytes(32)),
    'DB_PASSWORD' => fn () => bin2hex(random_bytes(24)),
    'GAME_API_SECRET' => fn () => bin2hex(random_bytes(32)),
    'TOPUP_API_SECRET' => fn () => bin2hex(random_bytes(32)),
    'MOCK_GATEWAY_SECRET' => fn () => bin2hex(random_bytes(32)),
];

$lines = file("$root/.env.example", FILE_IGNORE_NEW_LINES);
foreach ($lines as &$line) {
    foreach ($generators as $key => $generate) {
        if ($line === "$key=") {
            $line = "$key=".$generate();
        }
    }
}

file_put_contents($target, implode("\n", $lines)."\n");
echo ".env written\n";
