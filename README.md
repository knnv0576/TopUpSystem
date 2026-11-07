# 點數充值、紅包與撥款退款 DEMO

只在本機測試的點數系統：玩家充值取得點數、用紅包贈送點數、申請把點數退成款項，再經兩層管理員審核後撥款。

**所有金流都是模擬的。** 不要輸入真實卡號、帳號或個人資料。應用程式也拒絕以 `APP_ENV=production` 啟動。

> 若要改成實際上線，請先請律師評估。點數可儲值、可互轉又可換回現金的組合，可能屬於需要許可的電子支付業務；用這種點數玩機率遊戲，也可能涉及賭博相關法規。不能只把模擬金流換成真實金流。

## 技術棧

- PHP 8.5.12 以上（已修補 PHP2Shell：CVE-2026-109618、CVE-2026-109619；版本過低時映像建置會失敗）
- Laravel 13、PostgreSQL 18、nginx，全部用 Docker 執行

## 啟動

需要 Docker Desktop。

```sh
docker compose build app
docker compose run --rm --no-deps -T app php docker/init-env.php   # 產生 .env 與隨機密鑰（要在 init 之前，否則套件安裝的最後一步會失敗）
docker compose run --rm --no-deps -T init                          # 憑證、目錄權限，並把套件安裝到 vendor volume
docker compose up -d
docker compose exec app php artisan migrate --seed --force --database=pgsql_owner   # 會顯示管理員的初始密碼，只顯示這一次
```

**資料庫帳號**：應用程式平常用 `DB_USERNAME`（`topup_app`），只有讀寫資料的權限，不是資料表擁有者，改不了結構與觸發器。資料表擁有者 `DB_OWNER_USERNAME` 只給 migration 使用，所以 migration 一律加上 `--database=pgsql_owner`；忘了加會因為權限不足而失敗，不會改到任何東西。應用程式帳號由 migration 建立，密碼取自 `DB_PASSWORD`。

從舊版升級（`.env` 裡還沒有 `DB_OWNER_USERNAME`）：把原本的 `DB_USERNAME`、`DB_PASSWORD` 改名為 `DB_OWNER_USERNAME`、`DB_OWNER_PASSWORD`，再新增 `DB_USERNAME=topup_app` 與一組新的隨機 `DB_PASSWORD`（至少 16 個字元），然後執行 migration 並重啟 `app queue scheduler`。

開啟 <https://localhost:8443>。憑證是自簽的，瀏覽器會顯示警告，這是本機環境的正常現象。

**效能**：專案在 Windows 磁碟上時，容器經由 9p 共享讀檔，非常慢，所以 `vendor` 放在 Docker volume（`vendor`），不使用專案資料夾裡的 `vendor`。`init` 服務會比對 `composer.lock`，有變動才重新執行 `composer install`；安裝套件請在容器裡執行 `docker compose exec app composer require …`，再執行 `docker compose run --rm --no-deps init` 更新記錄。OPcache 每 5 秒檢查一次程式檔，改完程式最多等 5 秒生效。

| 入口 | 網址 | 帳號 |
|---|---|---|
| 玩家中心 | `/game` | 自行註冊 |
| 充值網站 | 從玩家中心按「前往充值網站」 | 單次 token 登入 |
| 管理後台 | `/admin` | `reviewer`（初審）、`supervisor`（覆核） |
| 本機信箱 | <http://localhost:8025>（Mailpit） | 不需登入；系統寄出的信都在這裡，不會真的寄出 |

管理員第一次登入時：輸入初始密碼 → 用驗證器 App 掃描畫面上的 QR Code（或手動輸入金鑰，類型選依時間）並輸入驗證碼。QR Code 由 `bacon/bacon-qr-code` 在伺服器上產生，密鑰不會送到外部服務，頁面也設定為不可快取 → 更改密碼。驗證碼只能使用一次，連續操作時要等下一組。

### 在其他設備上執行

```sh
git clone <儲存庫網址> TopUpSystem
cd TopUpSystem
```

接著照上面的「啟動」步驟從頭執行。需要注意：

- 只需要 Docker Desktop（Windows、macOS 或 Linux 都可以），PHP、Composer、PostgreSQL 都在容器裡。本機的 `8443` port 必須沒有被占用。
- `.env`、密鑰、自簽憑證與資料庫都**不在儲存庫裡**，每台設備會各自產生。資料不會跟著搬過去，管理員的初始密碼與 TOTP 也要在新設備重新設定。
- 第一次建置要下載映像與套件，需要網路，時間會比較久。
- 驗證指令見下方「自動化驗證」。瀏覽器流程另外需要 Node.js 與 Chrome。

沒有初始密碼，或忘記密碼、遺失 TOTP 時，在主機上執行（新密碼只顯示一次）：

```sh
docker compose exec app php artisan topup:admin-reset reviewer --password
docker compose exec app php artisan topup:admin-reset reviewer --totp
```

玩家或管理員連續失敗 5 次，帳號會鎖定 15 分鐘；上面的指令也會解除管理員的鎖定。

## 流程

**充值**：玩家選方案 → 模擬付款頁輸入付款資料（信用卡、ATM 虛擬帳號或超商代碼）並通過格式驗證 → 模擬金流送出簽章 Webhook → 驗簽、去重、核對金額 → 入點。匯率固定 1 元 = 100 點。

**紅包**：輸入玩家編號與點數 → 確認畫面 → 立即扣點 → 收件者在紅包匣領取；24 小時未領退回。

**退款**：

```
玩家申請（不可撤銷）→ 凍結點數 → 初審 → 覆核（TOTP）→ 扣點 → 模擬撥款 → 完成
                                 └─ 任一層拒絕：結案並解凍，玩家可再申請
撥款失敗 → 重試（同一個冪等鍵，不會重複付款），或結案把點數還給玩家
已撥款 → 銀行退匯 → 撥款遭退匯（列入異常清單）→ 覆核結案還點
```

點數須為 100 的倍數，單次至少 10,000 點（100 元）。退款不分點數來源，一律走模擬撥款，不退回原付款交易。

**撥款帳戶**：玩家要先在退款頁綁定撥款帳戶（名稱、銀行代碼、帳號，需要再輸入一次密碼），最多 5 個，同一個帳戶不能重複綁定。帳戶存在充值端，帳號以 `APP_KEY` 加密，畫面與稽核紀錄只顯示銀行代碼與後四碼。申請退款時從已綁定的帳戶選一個，審核通過後直接撥款到那個帳戶；申請會複製一份帳戶資料，之後刪除綁定也不影響進行中的撥款。退款 API 只接受綁定帳戶的編號，不接受直接傳入帳號。後台退款詳細頁在扣點與撥款處理中會每 2 秒自動更新。

**戶名驗證與退匯（模擬）**：綁定時要填戶名，系統先向模擬銀行確認帳號存在、戶名相符才綁定；戶名加密保存，畫面只顯示遮罩（例如「王*明」）。模擬銀行依帳號結尾決定結果：`9999` 查無帳號、`8888` 戶名不符、`7777` 可以綁定但撥款成功後約 30 秒會收到退匯通知，其他帳號都通過。退匯時申請進入「撥款遭退匯」並列入異常清單，點數維持已扣除（款項已回到公司），由覆核者輸入 TOTP 結案還點；該綁定帳戶標記為「曾被退匯」，不能再選來退款，玩家要刪除後重新綁定正確的帳戶。

**停權**：玩家立即無法登入，只看到「帳號已停權，有疑慮請聯繫管理人員」；審核中的申請與待核准的調整自動拒絕，相關的未領紅包退回；之後收到的付款不入點，轉入異常清單，解除停權後由覆核者手動補入。

## 轉盤小遊戲

這款小遊戲的主要用途是測試點數金流，入口在玩家中心的「小遊戲」。下注 100、500 或 1,000 點，結果由伺服器決定，長期回報率 95%：

| 倍率 | 0 | 0.5 | 1 | 2 | 5 | 10 |
|---|---:|---:|---:|---:|---:|---:|
| 機率 | 48% | 20% | 15% | 10% | 4% | 3% |

沒有單局或每日的業務上限，單局最多贏回 10,000 點（下注 1,000 點 × 10 倍）。贏到的點數和其他點數相同，可以申請退款。

**一局的狀態**：

```
committed → debit_pending → payout_pending → settled
                 ├→ rejected（點數不足、停權或扣點逾時；判定前先作廢扣點，沒有扣點）
                 └→ integrity_hold（檢查碼不符；payout_pending 也可能進入）
```

- 結果在扣點前就固定。玩家斷線或關頁不會取消這一局，可到遊戲紀錄頁查看。
- 同一局只接受第一次送出的內容，內容不同回 `409`。請求不能指定結果或獎勵。
- 扣點或發獎中斷時，排程 `minigame:reconcile` 每分鐘用同一個冪等鍵重試；超過 10 分鐘的局會在後台標示。
- **扣點期限 5 分鐘**（`debit_void_after_seconds`）：開局後 5 分鐘仍沒扣點成功，就先作廢扣點，再判定這一局未成立，玩家看到「處理逾時，這一局沒有成立，也沒有扣點」。點數不足或停權時也是先作廢、再判定。作廢後，之前卡住才送達的扣點不會生效。
  - 作廢時發現扣點其實已經生效，就不作廢，這一局繼續發獎。
  - 作廢 API 也連不上時，局維持處理中，等服務恢復再作廢；確認不了帳本前不判定失敗。
  - 已扣點的發獎沒有期限，會一直重試到發出，避免玩家付了點數卻拿不到獎勵。
- 檢查碼不符的局進入 `integrity_hold`：停止處理、不發獎、寫入稽核紀錄，局本身保留作為證據。需要補償時，由初審發起人工調整、覆核者核准，並在理由註明局號。

**公平性驗證**：開局前公布伺服器種子的雜湊，結算後公開種子，玩家可以在紀錄頁用瀏覽器自行驗算。這能證明結果未被事後更改，不能證明賠率符合宣稱；賠率由功能測試窮舉檢查。

**遊戲內充值**：不接真實金流，但走完整的訂單、模擬付款、模擬金流 Webhook 與入點流程，方案沿用後台上架方案。按下方案後跳出彈窗：選付款方式（信用卡、ATM 虛擬帳號或超商代碼）→ 建立訂單 → 在彈窗輸入付款資料 → 等待結果。付款資料的驗證與測試卡號都和模擬付款頁相同，由遊戲端以簽章 API 轉送給充值端驗證，不寫入資料庫、日誌或 session，送出後畫面上的卡號與安全碼會清空。

- 請求不能指定付款情境，結果由付款資料決定（例如測試卡號）。覆核者可在測試面板設定下一次的結果，會蓋過付款資料，只生效一次。
- 一張訂單只能送出一次付款，同時送出也只有一個生效。
- 沒付款就關掉彈窗，訂單逾期後顯示「已過期」；送出付款後卻逾期才算失敗。
- 玩家看到的失敗一律是「充值失敗，請聯繫客服」，包括發卡行拒絕；實際狀態在後台看。

**帳務核對**：後台「小遊戲」頁，或執行下面的指令。只讀取、不修改資料，會比對帳本與錢包、已完成訂單與入點、每一局與帳目、作廢後沒有扣點，以及檢查碼。有不一致時回傳非 0。

```sh
docker compose exec app php artisan minigame:check
```

已有資料的環境要先執行 `docker compose exec app php artisan migrate --force --database=pgsql_owner`，並用 `docker compose restart queue scheduler` 讓背景工作載入新程式。

**客服**：玩家從玩家中心或充值網站的「客服」提出問題（類別、主旨、描述，可選擇相關訂單與回覆通知 Email），失敗的訂單頁也有「聯繫客服」。訊息裡出現卡號（通過 Luhn 檢查的 13 到 19 位數字）會被拒絕。初審與覆核都能在後台「客服工單」回覆或結案；玩家回覆後工單回到待處理，已結案的工單不能再回覆。新工單與玩家回覆會通知 `SUPPORT_INBOX`，客服回覆會通知玩家留下的 Email，信件都由佇列寄到 Mailpit。`MAIL_MAILER` 只允許 `smtp`（且主機必須是 `mailpit`）、`array`、`log`，設定成其他值時應用程式拒絕啟動。

**接受的付款方式**：充值網站頁尾列出 Visa、Mastercard、JCB、American Express、ATM 轉帳與超商代碼。卡別圖示取自 simple-icons（CC0），商標屬各品牌所有，說明見 `public/img/payments/NOTICE.txt`。

## 測試工具

- **模擬付款頁**：三種付款方式，前端即時檢查、伺服器再驗證一次。
  - 信用卡：英文姓名、卡號（13 到 19 碼、Luhn 檢查碼、卡別）、有效期限（MM/YY，不可過期）、安全碼（American Express 4 碼，其他 3 碼）。**只接受頁面上的測試卡號**，格式正確的真實卡號也會被拒絕；付款結果由測試卡號決定：`4111 1111 1111 1111` 成功、`4000 0000 0000 0002` 發卡行拒絕交易（玩家看到「發卡行拒絕交易」，其他原因的失敗仍只顯示「充值失敗，請聯繫客服」）、`4000 0000 0000 0036` 延遲通知、`4000 0000 0000 0044` 重複通知（另有 Mastercard、JCB、American Express 成功卡）。卡號與安全碼不寫入資料庫、日誌或 session，驗證失敗時要重新輸入。
  - ATM 虛擬帳號：銀行代碼 `999`（模擬銀行）與由訂單導出的 14 碼虛擬帳號，轉帳時帳號與金額都要相符。
  - 超商代碼：由訂單導出的 14 碼繳費代碼，單筆上限 20,000 元，繳費金額要相符。
  - 進階測試：不需付款資料，直接送出錯誤簽章或金額不符的通知。
  - 只有待付款且未過期的訂單能付款；送出付款每分鐘最多 20 次。
- **後台測試面板**（覆核者）：重置測試資料（稽核紀錄與轉盤開關會保留）、讓紅包到期、讓申請逾時、設定下一次撥款結果與下一次遊戲內充值結果。面板不能直接修改點數。

## 自動化驗證

```sh
# 功能測試（使用 topup_test 資料庫）
docker compose exec app php artisan test

# 併發測試：對實際服務同時送出相同請求
docker compose exec app php tests/e2e/concurrency.php

# 真實瀏覽器的端對端流程（需要本機 Chrome；會先清空開發資料）
docker compose exec app php artisan migrate:fresh --seed --force --database=pgsql_owner
docker compose restart queue scheduler
npm install --prefix <暫存資料夾> playwright-core
NODE_PATH=<暫存資料夾>/node_modules node tests/e2e/browser-e2e.cjs <截圖資料夾>
```

## 架構重點

| 位置 | 內容 |
|---|---|
| `app/DemoGame/` | 玩家端：帳號、錢包與帳本、紅包、扣點與加點 API |
| `app/MiniGame/` | 轉盤：狀態機、結果計算、檢查碼、帳務核對 |
| `app/Services/` | 充值端：訂單、Webhook、入點、退款狀態機、人工調整、停權 |
| `app/Payments/` | `PaymentGateway` 介面與 `MockGateway` |
| `app/Game/` | `GameGateway` 介面與 `HttpGameGateway` |
| `tests/e2e/` | 併發測試與瀏覽器流程 |

- 玩家端與充值端的資料表分開，只透過簽章 HTTP API 溝通。
- 內部 API 與 Webhook 只開在 Docker 內部的 port 80；對外的 HTTPS 會回 404。
- 點數異動一律經過 `WalletService`：鎖定錢包、以冪等鍵去重，並與帳本同交易寫入。
- 小遊戲只透過簽章 API 操作點數與建立訂單。帳務核對是例外，它會唯讀查詢兩端的資料表。

### 給小遊戲使用的點數 API

#### 扣點

`POST http://web/api/points/debit`（只能從 Docker 內部呼叫）

```json
{ "player_no": "PABC12345", "points": 100, "idempotency_key": "game-round-1", "purpose": "小遊戲下注" }
```

需帶 `X-Timestamp`、`X-Nonce`、`X-Signature` 標頭，以 `GAME_API_SECRET` 簽章，規則見 `app/Support/ApiSignature.php`。只扣可用餘額；回應 `422 insufficient_points` 或 `403 player_suspended` 時不會扣點。

相同冪等鍵不會重複扣點。會先查帳本再查停權：已成功的扣點重試時回 `200`，即使玩家已被停權；點數不同時回 `409 idempotency_conflict`；已作廢的鍵回 `409 debit_voided`，不會扣點。

#### 作廢扣點

`POST http://web/api/points/debit/void`（只能從 Docker 內部呼叫，簽章方式相同）

```json
{ "player_no": "PABC12345", "points": 100, "idempotency_key": "game-round-1" }
```

呼叫端要判定扣點失敗前先呼叫。和扣點鎖定同一個錢包，所以兩者只有一個會生效：

- 還沒扣點：寫入一筆 0 點的作廢紀錄（類型 `game_debit_void`，不顯示在玩家明細），回 `200 {"status":"voided"}`。之後同鍵的扣點一律回 `409 debit_voided`。
- 已經扣點：不作廢，回 `200 {"status":"debited"}`。點數與帳本不同時回 `409 idempotency_conflict`。
- 可以安全重複呼叫。

#### 加點

`POST http://web/api/points/credit`（只能從 Docker 內部呼叫，簽章方式相同）

```json
{ "player_no": "PABC12345", "points": 500, "debit_idempotency_key": "game-round-1", "purpose": "小遊戲獎勵" }
```

- 必須有對應的扣點紀錄，否則回 `409 debit_not_found`。
- 不得超過「帳本裡的下注額 × 賠率表最高倍率」，否則回 `409 payout_exceeds_limit`。
- 一筆下注最多發獎一次；相同內容重送回 `200`，點數不同回 `409 idempotency_conflict`。
- 不拒絕已停權的玩家，因為已下注的局要結清；新的下注會被扣點 API 擋下。

## 安全設計

- 對外只綁定 `127.0.0.1:8443`；PostgreSQL 不對外。
- 後台：密碼 + TOTP + IP 白名單。白名單只看連線來源 IP，不採用 `X-Forwarded-For`。
- 後台使用獨立的 session cookie（`demo-admin-session`，只送往 `/admin`，關閉瀏覽器即失效），和玩家端互不共用。閒置 15 分鐘或登入超過 8 小時要重新登入（`ADMIN_IDLE_MINUTES`、`ADMIN_ABSOLUTE_HOURS`）。
- 高風險操作（核准退款、結案還點、核准調整、停權與解除、補入點、停用帳號、新增與修改方案）要再驗證一次 TOTP。方案的稽核紀錄保留修改前後的名稱、價格與上下架狀態。
- 應用程式的資料庫帳號只有讀寫權限，不是資料表擁有者也不是超級使用者：改不了結構與觸發器，不能修改或刪除稽核紀錄，也不能執行系統指令。功能測試會逐項檢查。
- 管理員各有隨機初始密碼，首次登入必須更改；覆核者可以停用其他帳號。連續失敗 5 次鎖定 15 分鐘。
- 玩家與管理員都可以改密碼。改密碼後，其他裝置會被登出並提示「帳號密碼已變更，請重新登入」，玩家的充值網站登入也會一起失效；改密碼的裝置保持登入。
- 模擬帳號以 `APP_KEY` 加密儲存，結案後刪除密文，只留銀行代碼與後四碼。
- 稽核紀錄只能新增，資料庫觸發器會拒絕更新、刪除與 `TRUNCATE`；登入成敗與來源 IP 都會記錄。簽章錯誤的金流通知寫入 `security.log`（`webhook.rejected`）。
- 功能測試不寫入實際的日誌檔（`LOG_CHANNEL=null`、`LOG_SECURITY_ENABLED=false`）。
- 所有 PHP 程序以非 root 執行；例外堆疊不帶函式參數；映像鎖定 digest。

做法與 ISO/IEC 27001:2022 控制項的對照、刻意不做的項目及理由，見 [docs/iso27001-mapping.md](docs/iso27001-mapping.md)。這不是合規證明。

## 不包含

真實金流與撥款、實名驗證、正式部署、轉盤以外的遊戲內容、多人或隨機紅包、Email 驗證、監控告警、對帳報表、自動備份。
