# TradingCRM — 项目规划书

> **Enterprise Portfolio Monitoring + Currency Analysis Dashboard**
> 用于监控多 broker / 多账户 MT5 交易组合,整合 AI 分析与 ForexFactory 新闻。

---

## 🚦 项目状态(最新)

✅ **Laravel 12 app 已 scaffold** — composer / npm 依赖装好,Breeze + Inertia + Vue 已 install,assets build 通过,所有路由跑通 (`php artisan route:list` 35 条)。

**还没跑的事:**
- ⏳ MySQL database `tradingcrm` 需要你手动建(我没碰 MySQL)
- ⏳ `php artisan migrate --seed` — 等你建好 DB 后跑
- ⏳ Vue 页面除 Accounts 之外只有 Welcome / Dashboard / Profile 等 Breeze 默认,**Dashboard 主页面 + Analysis 页面 + News 页面** 还没做(下一阶段)
- ⏳ EA push endpoint (`/api/ea/push`) 还没实现(等你确认 EA payload 是否补字段)
- ⏳ Drawdown alert 逻辑 + Scheduler 注册 + Reports export 还没实现

**你需要做:**
1. 装 Laragon Full(或 XAMPP,看 setup.md 推荐)
2. 在 MySQL 建 database:`CREATE DATABASE tradingcrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. `cd C:\Users\JunWe\Documents\Work\TradingCRM`
4. `php artisan migrate --seed` — 跑所有 migration 并 seed admin 用户 + telegram topics
5. `php artisan serve` 或部署到 Laragon 的虚拟主机
6. 登入:`admin@email.com` / `Admin123456!`
7. 进 **Accounts** → 添加 MT5 账户号
8. 把 `.env` 里 `EA_PUSH_TOKEN` 填好,然后在 EA 里同步,attach EA 到 MT5

---

## 1. 项目总览

| 项目 | 内容 |
|---|---|
| 项目名 | TradingCRM |
| 类型 | Web Dashboard (multi-user, mobile responsive) |
| 用途 | MT5 多账户监控 + 货币分析 + 新闻日历 + Drawdown 告警 |
| 模式 | 只读 (read-only),不下单 |
| 数据来源 | MT5 EA (HTTP POST,10s/次) + ForexFactory scrape + AI analysis |
| 时区 | **GMT+8** (Asia/Singapore) |
| 数据库名 | **`tradingcrm`** (MySQL) |
| Laravel 版本 | **12.x** (Composer 安装了最新,与 11 兼容) |
| Default admin user | `admin@email.com` / `Admin123456!` |

---

## 2. 技术栈

| 层 | 技术 | 备注 |
|---|---|---|
| Backend | **Laravel 11** (PHP 8.3) | API + Web + Queue + Scheduler |
| Database | **MySQL 8** | 单数据库 |
| Frontend | **Inertia.js + Vue 3 + Tailwind CSS** | SPA 体验,无需独立 API |
| UI Kit | **shadcn-vue** + Lucide icons | 现代化、mobile responsive |
| Charts | **TradingView Widget**(嵌入式) + **ApexCharts** 或 **Chart.js**(account performance curves) | TradingView 用于货币分析页,ApexCharts 用于 portfolio 图表 |
| Queue | **Laravel Queue (database driver)** | AI 分析 job 排队 |
| Scheduler | **Laravel Scheduler** + Windows Task Scheduler 触发 | 新闻 scrape / drawdown check |
| Notifications | **Telegram Bot API** | Drawdown alert |
| Web Server | **Nginx (via Laragon Full)** | 详见 `setup.md` |
| Auth (User) | Laravel Breeze (session-based) | login page + remember me |
| Auth (EA → API) | **Static token in `.env`** | 单一共享 token,header 验证 |

---

## 3. 系统架构

```
┌─────────────────────────────────────────────────────────────┐
│                      Windows Server VPS                      │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ MT5 Term #1  │  │ MT5 Term #2  │  │ MT5 Term #N  │      │
│  │  + EA        │  │  + EA        │  │  + EA        │      │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘      │
│         │ HTTP POST       │                  │              │
│         │ (every 10s,     │                  │              │
│         │  with token)    ▼                  │              │
│         │       ┌──────────────────┐         │              │
│         └──────▶│  Laravel API     │◀────────┘              │
│                 │  /api/ea/push    │                        │
│                 └────────┬─────────┘                        │
│                          ▼                                  │
│                 ┌──────────────────┐                        │
│                 │     MySQL 8      │                        │
│                 │  ┌────────────┐  │                        │
│                 │  │ accounts   │  │                        │
│                 │  │ snapshots  │  │                        │
│                 │  │ orders_*   │  │                        │
│                 │  │ news       │  │                        │
│                 │  │ ai_analyses│  │                        │
│                 │  └────────────┘  │                        │
│                 └────────┬─────────┘                        │
│                          ▲                                  │
│  ┌──────────────────┐    │    ┌─────────────────────────┐   │
│  │ Queue Worker     │────┘    │  Scheduler (every min)  │   │
│  │ (NSSM service)   │         │  - news scrape (daily)  │   │
│  │ - AI Analysis    │         │  - AI analysis (weekly) │   │
│  │ - Telegram alert │         │  - drawdown check (1m)  │   │
│  └──────────────────┘         └─────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │ Nginx + PHP-FPM (port 80/443)                        │   │
│  │  ├── Web Dashboard (Inertia + Vue)                   │   │
│  │  └── API endpoints                                   │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
         ▲
         │ HTTPS
         ▼
  ┌──────────────┐  ┌──────────────┐
  │  Browser PC  │  │  Browser Mob │
  └──────────────┘  └──────────────┘
```

---

## 4. 数据库设计 (MySQL Schema)

### 4.1 `users` (Laravel default + role)
```sql
id, name, email, password, role (admin/viewer), telegram_chat_id, created_at, updated_at
```

### 4.2 `mt5_accounts` (账户主资料,EA 第一次推送时 upsert)
```sql
id, account_number (unique), broker, nickname, currency, leverage,
balance, equity, margin, free_margin, margin_level,
floating_pnl, floating_pnl_percent,
last_ping_at, status (online/offline),
created_at, updated_at
```

### 4.3 `account_snapshots` (每 10 秒一笔,用于绘制 equity curve)
```sql
id, mt5_account_id, balance, equity, margin, free_margin,
floating_pnl, floating_pnl_percent, recorded_at
INDEX (mt5_account_id, recorded_at)
```
> ⚠️ 留 raw 数据成本高,**可加每分钟/每小时 aggregate** (cron downsample)。

### 4.4 `orders_open` (当前持仓,EA 每次推送 → 全量替换该账户的 open)
```sql
id, mt5_account_id, ticket (unique per account),
symbol, type (buy/sell), volume, open_price, current_price,
sl, tp, swap, commission, profit,
opened_at, updated_at
```

### 4.5 `orders_pending` (挂单,同 open 处理)
```sql
id, mt5_account_id, ticket,
symbol, type (buy_limit/sell_limit/buy_stop/sell_stop),
volume, price, sl, tp, expiration,
created_at, updated_at
```

### 4.6 `orders_history` (历史成交,只 insert,dedupe by ticket)
```sql
id, mt5_account_id, ticket (unique per account),
symbol, type, volume, open_price, close_price,
sl, tp, swap, commission, profit,
opened_at, closed_at, comment,
INDEX (mt5_account_id, closed_at)
```

### 4.7 `forex_news` (重新设计,migration 已写好)
```sql
id              bigint PK
title           varchar(255)
currency        varchar(8) index      -- 从 country 改名,实际就是 currency code
impact          enum('HIGH','MEDIUM','LOW','HOLIDAY')   -- normalize 一次写入,免显示时再 lowercase
forecast        varchar(255) nullable
previous        varchar(255) nullable
actual          varchar(255) nullable
measures        text nullable         -- enrichment 栏位,留 null 后续补
usual_effect    text nullable
traders_care    text nullable
notes           text nullable
event_at        datetime              -- proper UTC datetime,parsed from raw ISO 8601
raw_date        varchar(255) nullable -- 原始字串,审计 / debug 用
created_at, updated_at
UNIQUE (title, event_at)               -- DB 层 dedupe
INDEX (currency, event_at)
INDEX (event_at)
```
> 已写好 migration:`database/migrations/2026_05_22_000001_create_forex_news_table.php`
> 已写好 model:`app/Models/ForexNews.php`

### 4.8 `currency_analyses` (AI 分析结果存档 — 重新设计)
```sql
id              bigint PK
symbol          varchar(16)        -- e.g. "AUDUSD",非单一货币
week_start      date
week_end        date
charts          json               -- {"H4":"url|data:uri","D1":"...","W1":"..."}
news_snapshot   json nullable      -- {"last_week":[ids],"this_week":[ids],"next_week":[ids]}
outlook         enum('bullish','bearish','neutral') nullable
confidence      decimal(4,3)       -- 0.000–1.000
summary         text
market_structure    json           -- trend, phase, momentum, key_observations
support_resistance  json           -- supports[], resistances[] with price/strength/note
news_impact     json               -- high_impact_events[], summary
trade_ideas     json               -- [{direction, entry, sl, tp, rationale}]
prompt_used     longtext           -- 当时实际送出的完整 prompt (审计)
raw_response    json               -- OpenRouter 完整回应 (审计 / 重新解析)
openrouter_model varchar(255)      -- e.g. "anthropic/claude-3.5-sonnet"
tokens_used     int
cost_usd        decimal(10,6)
status          enum('pending','processing','completed','failed')
error_message   text nullable
user_id         FK users
created_at, updated_at
INDEX (symbol, week_start)
INDEX (status, created_at)
```
> 已写好 migration:`database/migrations/2026_05_22_000002_create_currency_analyses_table.php`

### 4.9 `drawdown_alerts` (告警设定)
```sql
id, scope (overall/account), mt5_account_id (nullable),
threshold_percent (5/10/15 等),
last_triggered_at, enabled,
created_at, updated_at
```

### 4.10 `alert_logs` (告警发送记录,避免重复轰炸)
```sql
id, drawdown_alert_id, mt5_account_id (nullable),
value_at_trigger, message, telegram_message_id,
sent_at
```

---

## 5. API 设计

### 5.1 EA → Laravel (Token 验证)

**Endpoint:** `POST /api/ea/push`
**Headers:** `Authorization: Bearer <EA_PUSH_TOKEN>` (or `X-EA-Token`)
**Push interval:** 10 秒(EA 的 `RiskPushInterval`)

**实际 EA payload 格式**(已确认,来自 Risk_Monitor_With_Quant_Trading.mq5 → `ExportRiskData()`):

```json
{
  "account": {
    "number": 12345678,
    "broker": "RS Finance",
    "server": "RSFinance-Live",
    "balance": 10000.00,
    "equity": 10523.50,
    "drawdown": 1.25
  },
  "performance": {
    "profitFactor": 1.85,
    "sharpeRatio": 1.42,
    "recoveryFactor": 2.10
  },
  "overall": {
    "EURUSD": {
      "pnl": 25.00,
      "buy_layers": 2,
      "sell_layers": 0,
      "lot_per_1000": "0.010",
      "multiplier": "-",
      "step_pips": "-"
    }
  },
  "pending_orders": [
    { "ticket": 5001, "symbol": "GBPUSD", "entry": 1.2650, "sl": 1.2600, "tp": 1.2750 }
  ],
  "positions": [
    { "ticket": 1001, "symbol": "EURUSD", "pnl": 25.00, "entry": 1.0850, "sl": 1.0800, "tp": 1.0900 }
  ],
  "history": [
    { "ticket": 999, "symbol": "GBPUSD", "pnl": 10.00, "entry": 1.2680, "sl": 0, "tp": 0 }
  ]
}
```

> ⚠️ **史上最新 20 笔成交 only** — EA 用 `hCount < 20` 限制,所以 history 是滚动窗口,不是全量。后端必须按 `ticket` upsert 累积。

### 5.1.1 EA payload 与原设计的差异 — 需要你决定如何处理

| 设计需要 | EA 现在没送 | 影响 |
|---|---|---|
| `account.currency` | ❌ | 显示金额时无法标 USD/SGD 等 |
| `account.leverage` | ❌ | 个别账户卡片缺一栏 |
| `account.margin / free_margin / margin_level` | ❌ | Dashboard 无法显示保证金占用 |
| `position.type` (buy/sell) | ❌ | 单笔订单看不出方向(只能从 overall.buy_layers/sell_layers 推) |
| `position.volume` | ❌ | 显示不到手数 |
| `position.swap / commission / current_price` | ❌ | 显示不到这些细节 |
| `position.opened_at` | ❌ | 排序与持仓时长缺 |
| `pending.type` (limit/stop/buy/sell) | ❌ | 看不出挂单方向 |
| `pending.volume / expiration` | ❌ | 显示缺 |
| `history.type / volume / open_price / opened_at / closed_at` | ❌ | History 列表能显示的字段很少 |
| `history` 全量 | ❌ 只 20 笔 | 长期 PnL 曲线 + 历史 Closed Trades 不完整 |

**建议:** 给 EA 的 `ExportRiskData()` 加上述字段(MQL5 这边都有 API 可拿,只是 JSON 没写出来)。我可以帮你改 EA 也行,你给我说一声。

**Response:** `200 OK { "status": "ok", "received_at": "..." }`

**Backend 逻辑(EaPushController 待写):**
1. 验 token → 不对返回 401
2. upsert `mt5_accounts` (key by `account.number`)
3. 写一笔 `account_snapshots`(balance/equity/drawdown/pnl)
4. 全量替换该账户的 `orders_open` + `orders_pending`(以 EA payload 为 truth)
5. `orders_history` 按 `ticket` 去重 upsert(累积而不是覆盖)
6. 触发 drawdown check → 超过 `TELEGRAM_DRAWDOWN_THRESHOLD` 发 Telegram

### 5.2 EA → Laravel: Chart Exporter (新)

**EA:** `ea/ChartExporter.mq5`(已写好)
**Polls:** `GET /api/ea/chart-requests/pending` (every 10s)
**Uploads:** `POST /api/ea/chart-exports` (raw PNG body)
**Fail:** `POST /api/ea/chart-requests/{id}/fail`

详见第 9 节「AI 分析模块」的新流程。

### 5.2 Web Dashboard 内部 API (Inertia routes)
| Route | Method | 功能 |
|---|---|---|
| `/login`, `/logout` | GET/POST | Laravel Breeze |
| `/dashboard` | GET | 主 Dashboard (img1) |
| `/dashboard/account/{id}` | GET | 单账户详情(展开三 tab) |
| `/analysis` | GET | 货币分析页 (img2) |
| `/analysis/{currency}/generate` | POST | 手动触发 AI 分析 (放入 queue) |
| `/news` | GET | 新闻日历页 |
| `/settings/alerts` | GET/POST | 设定 drawdown 告警 |
| `/reports/export` | GET | 导出报表 (PDF/Excel) |
| `/api/dashboard/refresh` | GET | 给前端 10s polling 用 |

---

## 6. 页面规格

### 6.1 主 Dashboard — `Enterprise Portfolio Monitoring` (对应 img1)

**Layout:**
```
┌─────────┬──────────────────────────────────────────────────┐
│         │ Enterprise Portfolio Monitoring  [⚙ Alert]       │
│ Sidebar ├──────────────────────────────────────────────────┤
│         │ ── Overall Portfolio Performance ──               │
│ ▸ Dash  │ ┌─────┬───────┬─────┬─────────┬──────────┐       │
│   Anal. │ │Profit│Balance│Eqty│Floating$│Floating %│       │
│   News  │ └─────┴───────┴─────┴─────────┴──────────┘       │
│   Acct  │ ┌────────────────┐  ┌────────────────────────┐   │
│   Alert │ │  Profit Curve  │  │ Past Results / Trades  │   │
│   Set.  │ │  (line chart)  │  │ [Day][Week][Custom]    │   │
│         │ └────────────────┘  └────────────────────────┘   │
│ Logout  │                                                  │
│         │ ── Individual Account Performance ──              │
│         │ ┌─ Account #12345678 (ICMarkets) ───────────┐    │
│         │ │ [Bal][Eqty][F%][F$][P$]                   │    │
│         │ │ ┌──────┐ ┌──────────┐ ┌──────────────┐    │    │
│         │ │ │Chart │ │ Floating │ │ Closed Trades│    │    │
│         │ │ │      │ │  Trades  │ │ /Past Results│    │    │
│         │ │ └──────┘ └──────────┘ └──────────────┘    │    │
│         │ └───────────────────────────────────────────┘    │
│         │ ┌─ Account #87654321 (Pepperstone) ─────────┐    │
│         │ │ ...                                        │    │
│         │ └───────────────────────────────────────────┘    │
└─────────┴──────────────────────────────────────────────────┘
```

**功能要点:**
- Overall 区:5 张 metric 卡 + Profit 曲线 + Past Results 表(可按 Day/Week/Custom Period 筛选)
- Individual 区:每个账户一张大卡,内嵌 mini chart + Floating Trades + Closed Trades
- 顶部右上角 **⚙ Drawdown Alert** 按钮 → 弹出 modal 设定 5% / 10% / 15% 阈值,Telegram 通知 (overall + individual 都能设)
- 每 **10 秒** 自动刷新(用 Inertia partial reload 或 fetch polling)
- Mobile: sidebar 折叠成 hamburger,Overall 卡片改成 2x3 grid,Individual 改成 accordion

### 6.2 货币分析页 — `Currency Analysis (Weekly)` (对应 img2)

**Layout:**
```
┌─────────┬──────────────────────────────────────────────────┐
│         │ Currency Analysis (Weekly) [22/5 – 28/5] [← →]   │
│ Sidebar ├──────────────────────────────────────────────────┤
│         │ Currency: [AUD][CAD][EUR][GBP][CHF][NZD][USD]    │
│         │           [SGD][JPY]                              │
│         │ Timeframe: [H1][H4][Daily][Weekly]                │
│         ├──────────────────────────────────────────────────┤
│         │ ▼ AUD       Bullish ↑ / Bearish ↓ Outlook        │
│         │                                                   │
│         │ Latest Currency News Summary:                     │
│         │ • AUD interest rate increase                      │
│         │ • Country's policies                              │
│         │ • Economics                                       │
│         ├──────────────────────────────────────────────────┤
│         │ ── AI Analysis ──                                 │
│         │ ┌─────────────────┐ ┌──────────────────────┐     │
│         │ │ Market Structure│ │ Support / Resistance │     │
│         │ │ ...             │ │ S1: 0.6500           │     │
│         │ │                 │ │ R1: 0.6650           │     │
│         │ └─────────────────┘ └──────────────────────┘     │
│         │                                                   │
│         │ ── TradingView Chart (AUDUSD, H4) ──             │
│         │ [TradingView Widget iframe]                       │
│         ├──────────────────────────────────────────────────┤
│         │ ── Upcoming News ──                               │
│         │ Date         | Time  | Currency | Event           │
│         │ 2026-05-23   | 10:30 | AUD      | Retail Sales    │
│         │ ...                                               │
└─────────┴──────────────────────────────────────────────────┘
```

**功能要点:**
- 9 个 currency tabs (AUD/CAD/EUR/GBP/CHF/NZD/USD/SGD/JPY)
- Timeframe 切换 (H1/H4/Daily/Weekly) → 影响 TradingView widget interval
- 顶部右上角 **Past Analysis [← →]** 翻看历史周的分析
- 选 currency 后展示该周 AI 分析结果(从 `ai_analyses` 读)
- **Market Structure** + **Support/Resistance** 两个 panel
- **TradingView Widget** 嵌入(用主流货币对,如 AUD → AUDUSD)
- 下方 **Upcoming News** 表格(从 `news` 表读,filter 该 currency + 未来时间)
- 若该周没有分析 → 显示 "Generate Now" 按钮,push job into queue

### 6.3 其他页面

| 页面 | 路径 | 内容 |
|---|---|---|
| Login | `/login` | Email + Password |
| News (full calendar) | `/news` | 完整日历:past + this week + upcoming,可按 currency / impact 筛选 |
| Alert Settings | `/settings/alerts` | 管理 drawdown 告警 + Telegram chat_id 绑定 |
| Reports | `/reports` | 选时间区间 + 账户 → 导出 PDF/Excel |
| Users | `/users` (admin only) | 加人/改 role(viewer 角色之后才做) |

---

## 7. 货币对支援清单 (从你的 sketch)

**9 个 currency**(单一货币分析):AUD, CAD, EUR, GBP, CHF, NZD, USD, SGD, JPY

**货币对**(TradingView chart 用):
```
AUDCAD, EURGBP, CADCHF, USDSGD, EURNZD, EURUSD, USDCAD, USDCHF,
USDJPY, GBPUSD, AUDUSD, NZDUSD, GBPJPY, AUDCHF, AUDNZD, CHFJPY ...
```
> 这部分整理成一个 config file(`config/currencies.php`),方便日后增减。

---

## 8. 通知系统 (Telegram with Forum Topics)

### 配置(已确认)
- **Bot Token:** in `.env` → `TELEGRAM_BOT_TOKEN`
- **Chat ID:** `-1003995294956` (forum group)
- **Drawdown threshold (default):** 2.0%(从你 Python config 沿用)

### Topic 映射(seeded into `telegram_topics` 表)
| Name | Thread ID | 用途 |
|---|---:|---|
| `account_1` | 123 | Account #1 specific alerts |
| `account_2` | 124 | Account #2 specific alerts |
| `account_3` | 125 | Account #3 specific alerts |
| `account_4` | 126 | Account #4 specific alerts |
| `account_5` | 127 | Account #5 specific alerts |
| `overall_reporting` | 128 | Portfolio-wide reports |
| `risk_management` | 129 | Drawdown / risk alerts (汇总) |
| `daily_report` | 130 | Daily summary |
| `weekly_report` | 131 | Weekly summary |
| `monthly_report` | 132 | Monthly summary |

> ⚠️ `mt5_accounts` 表里要绑定 `account_1`–`account_5` 对应的实际 MT5 账户号(seeder 留空,等账户接进来再 bind via UI / tinker)。

### Drawdown Alert 流程
1. 用户在 `/settings/alerts` 可覆写各账户的阈值(default 2%)
2. 每次 EA push 完后,内联 check 该账户 drawdown
3. 超阈值 → `TelegramService::drawdownAlert()`:
   - 发到该账户专属 topic (`account_N`)
   - 同步发一份到 `risk_management` topic(汇总视角)
   - 写 `alert_logs`(同账户同阈值 1 小时内不重发)

### Telegram message 模板
```
🚨 Drawdown Alert
Account: <code>RSF-12345</code>
Drawdown: -12.50% (threshold: 2.00%)
Equity: 8,750.00
Time: 2026-05-22 14:30 GMT+8
```

### 已写好
| 文件 | 用途 |
|---|---|
| `app/Models/TelegramTopic.php` | name → thread_id lookup + account binding |
| `app/Services/TelegramService.php` | `sendToTopic()`, `sendToAccount()`, `drawdownAlert()` |
| `database/migrations/2026_05_22_000005_create_telegram_topics_table.php` | schema |
| `database/seeders/TelegramTopicSeeder.php` | 默认 10 个 topic 映射 |

---

## 9. AI 分析模块 (使用 OpenRouter + ChartExporter EA)

### 流程 (event-driven,EA 主导出图)
```
用户选 symbol (e.g. AUDUSD)
        │
        ▼
POST /analysis/generate { symbol: "AUDUSD" }
        │
        ▼
AnalysisController::generate()
  → 建 CurrencyAnalysis row (status=pending)
  → 建 ChartRequest row (status=pending, linked to analysis)
  → 立刻回 202 { analysis_id, chart_request_id }
        │
        ▼
ChartExporter EA (每 10 秒 poll)
  → GET /api/ea/chart-requests/pending
  → 拿到 [{id, symbol}]
  → 对每个 timeframe (H4/D1/W1):
      • ChartOpen(symbol, tf)
      • 加 MA(50) + MACD(12/26/9) + RSI(14)
      • Sleep 1.5s 等 chart render
      • ChartScreenShot → MQL5/Files/
      • 读 PNG binary → POST /api/ea/chart-exports
          headers: X-Symbol, X-Timeframe, X-Request-Id
  → Backend 写入 chart_exports + Storage::disk('public')
        │
        ▼
EaChartController::upload() — 收到第 3 张图时:
  → ChartRequest status = completed
  → dispatch AnalyzeCurrencyJob(analysis_id)
        │
        ▼
[Queue Worker] AnalyzeCurrencyJob::handle()
  1. 读 ChartRequest → imageUrlMap() = {H4:url, D1:url, W1:url}
  2. 拆 symbol → ["AUD","USD"]
  3. ForexNews 查 last/this/next week 三组新闻
  4. PromptRenderer::render() — 读 storage/app/prompts/currency_analysis.md
  5. OpenRouterService::analyze(prompt, charts)
     → multimodal:public chart URL 作为 image_url 送入
     → response_format: json_object
  6. 解析 JSON → 更新 CurrencyAnalysis (status=completed)
        │
        ▼
前端 polling GET /analysis/{id}/status
  → 返回 analysis.status + chart_request.received_timeframes
  → 完成后 reload 显示
```

### 容错
- ChartRequest 等图超过 **10 分钟** → 自动 fail,显示 timeout
- EA 单张图上传失败 → 下次 poll 重试(symbol 失败会调 `/fail` 终止)
- AnalyzeCurrencyJob 等图阶段用 `$this->release(30)` 把自己丢回 queue,30 秒后再 check
- OpenRouter 失败 → `tries=3`, `backoff=30`

### Response JSON Schema (固定,后端按这个解析)
```json
{
  "outlook": "bullish | bearish | neutral",
  "confidence": 0.75,
  "summary": "One paragraph weekly outlook ...",
  "market_structure": {
    "trend": "uptrend | downtrend | sideways",
    "phase": "accumulation | markup | distribution | markdown",
    "momentum": "strong | moderate | weak",
    "key_observations": ["...", "..."]
  },
  "support_resistance": {
    "supports":    [{"price": 0.6500, "strength": "strong", "note": "..."}],
    "resistances": [{"price": 0.6650, "strength": "medium", "note": "..."}]
  },
  "news_impact": {
    "high_impact_events": ["...", "..."],
    "summary": "..."
  },
  "trade_ideas": [
    {"direction": "long", "entry": 0.6520, "stop_loss": 0.6480, "take_profit": 0.6600, "rationale": "..."}
  ]
}
```

### Prompt 管理(可随时调整)
- **位置:** `storage/app/prompts/currency_analysis.md`
- 用 `{{variable}}` placeholder,变量:`symbol`, `current_date`, `news_last_week`, `news_this_week`, `news_next_week`, `response_schema`
- 修改后 **无需重启 worker**(每次 job 跑都重新读文件)
- 每次分析的 prompt 完整快照存进 `currency_analyses.prompt_used`,审计 / 回溯方便

### OpenRouter 配置
- `.env`:
  ```
  OPENROUTER_API_KEY=sk-or-v1-xxx
  OPENROUTER_MODEL=anthropic/claude-3.5-sonnet
  OPENROUTER_TEMPERATURE=0.3
  ```
- Multimodal:把 3 张 chart 作为 `image_url` content 项目和 prompt 一起送
- 支持 image URL 或 base64 data URI

### Chart Source (替换外部 API,改用 EA 自出图)
- 不再使用外部 Chart API
- MT5 上跑 `ea/ChartExporter.mq5`,attach 到任一图表上
- EA 自动加这些 indicators 到导出图(可在 EA inputs 调参数):
  - **Moving Average** — 50 SMA on close (main window)
  - **MACD** — 12/26/9 (subwindow 1)
  - **RSI** — 14 (subwindow 2)
- 截图规格:1920×1080 PNG,200 根 K 线
- 图存到 `storage/app/public/charts/<request_id>/<SYMBOL>_<TF>_<random>.png`
- 公开 URL 通过 `php artisan storage:link` 配 `/storage/charts/...`
- OpenRouter 直接 fetch 这些 URL 看图(多模态)

### 排队
- Queue driver: `database`(`.env` 设 `QUEUE_CONNECTION=database`)
- Worker 用 **NSSM** 注册成 Windows Service,详见 `setup.md`
- Job timeout 600s,tries 2,backoff 30s

### 已写好的文件
| 类型 | 路径 |
|---|---|
| Migration (analyses) | `database/migrations/2026_05_22_000002_create_currency_analyses_table.php` |
| Migration (chart_requests) | `database/migrations/2026_05_22_000003_create_chart_requests_table.php` |
| Migration (chart_exports) | `database/migrations/2026_05_22_000004_create_chart_exports_table.php` |
| Model (Analysis) | `app/Models/CurrencyAnalysis.php` |
| Model (ChartRequest) | `app/Models/ChartRequest.php` |
| Model (ChartExport) | `app/Models/ChartExport.php` |
| Service (OpenRouter) | `app/Services/OpenRouterService.php` |
| Service (Prompt) | `app/Services/PromptRenderer.php` |
| Service (Chart) | `app/Services/ChartApiService.php` (DEPRECATED stub) |
| Job | `app/Jobs/AnalyzeCurrencyJob.php` |
| Controller (Web) | `app/Http/Controllers/AnalysisController.php` |
| Controller (EA) | `app/Http/Controllers/Api/EaChartController.php` |
| Middleware (EA auth) | `app/Http/Middleware/VerifyEaToken.php` |
| Prompt | `storage/app/prompts/currency_analysis.md` |
| **MT5 EA** | `ea/ChartExporter.mq5` |
| Config snippet | `snippets/config-services-additions.php` |
| ENV snippet | `snippets/env-additions.txt` |
| Routes snippet | `snippets/routes-additions.php` |

---

## 10. 新闻 Scrape 模块 (重写版)

**新 command:** `App\Console\Commands\ScrapeForexNewsCommand` (signature `news:scrape`)

**改进点:**
- 一次抓 3 endpoints(last + this + next week)
- `date` parse 成 proper UTC datetime → `event_at` column
- `country` → `currency`(语义正确)
- impact 写入时 normalize 成 `HIGH/MEDIUM/LOW/HOLIDAY`(免显示时 lowercase 头痛)
- `firstOrCreate` 取代 `where->exists + create`,少一次 query
- DB 层 UNIQUE `(title, event_at)` 防 race
- 既有 row 时,若 `actual` / `forecast` 有新值会自动 update(让 actual 值持续刷新)
- Source URL 失败不会中断,继续跑其他 endpoint
- 全程包 try/catch,详细 Log

**Source:**
```
https://nfs.faireconomy.media/ff_calendar_lastweek.json
https://nfs.faireconomy.media/ff_calendar_thisweek.json
https://nfs.faireconomy.media/ff_calendar_nextweek.json
```

**排程:** 在 `app/Console/Kernel.php` 注册:
```php
$schedule->command('news:scrape')
         ->dailyAt('06:00')
         ->timezone('Asia/Singapore')
         ->onOneServer()
         ->withoutOverlapping();
```
通过 Windows Task Scheduler 每分钟跑 `php artisan schedule:run`(详见 `setup.md`)

**已写好的文件:**
| 类型 | 路径 |
|---|---|
| Migration | `database/migrations/2026_05_22_000001_create_forex_news_table.php` |
| Model | `app/Models/ForexNews.php` |
| Command | `app/Console/Commands/ScrapeForexNewsCommand.php` |

---

## 11. 安全 & 权限

| 项目 | 措施 |
|---|---|
| EA → API 鉴权 | Static token in `.env` (`EA_PUSH_TOKEN`),Middleware 检查 `X-EA-Token` header |
| Web 鉴权 | Laravel Breeze session-based,login required |
| Role | `admin` (现阶段全员),`viewer` (留 schema,之后启用) |
| HTTPS | win-acme (Let's Encrypt for Windows),详见 `setup.md` |
| Rate limit | API endpoints 限 60/min per IP |
| CORS | 不开放跨域 (前后端同域) |
| SQL 注入 | Eloquent ORM |
| XSS | Vue 自动 escape |
| 备份 | 每日 mysqldump → 本地 + 异地 (待定) |

---

## 12. 开发阶段拆解 (建议)

### Phase 1 — 基建 (week 1)
- [ ] VPS 环境安装(见 `setup.md`)
- [ ] Laravel 11 项目初始化 + Breeze + Inertia + Vue
- [ ] 数据库 migration (所有表)
- [ ] Sidebar layout + login page

### Phase 2 — EA 数据流 (week 2)
- [ ] `POST /api/ea/push` endpoint + token middleware
- [ ] EA payload 解析 + DB 写入逻辑
- [ ] 联调你的 EA(请准备 EA 推送格式)
- [ ] 数据正确写入 MySQL 验证

### Phase 3 — Main Dashboard (week 2–3)
- [ ] Overall metrics + profit curve
- [ ] Individual account cards (chart + open + history)
- [ ] 10s polling 刷新
- [ ] Past Results filter (day/week/custom)
- [ ] Mobile responsive 调整

### Phase 4 — Drawdown Alert + Telegram (week 3)
- [ ] Alert settings page
- [ ] Drawdown check 逻辑
- [ ] Telegram bot 接入 (等你 token)
- [ ] Throttle / alert_logs

### Phase 5 — News + Analysis 页 (week 4)
- [ ] News 表 + 现有 scrape function 接入
- [ ] 货币 tab 切换
- [ ] TradingView widget 嵌入
- [ ] AI Analysis 现有 code 接入 + queue
- [ ] Past analysis 翻页

### Phase 6 — Reports + Polish (week 5)
- [ ] Export PDF/Excel
- [ ] 错误处理 / loading states
- [ ] 完整 mobile responsive QA
- [ ] HTTPS 上线

---

## 13. 我需要你提供的东西 (Code / 配置)

| # | 项目 | 状态 |
|---|---|---|
| 1 | EA HTTP POST payload 格式 | ✅ 已确认(Risk_Monitor_With_Quant_Trading.mq5) |
| 2 | ~~现有 AI 分析~~ | ✅ 已重写 |
| 3 | ~~现有 News scrape~~ | ✅ 已重写 |
| 4 | ~~Chart API 规格~~ | ✅ 改用 EA 出图 (ChartExporter.mq5) |
| 5 | Telegram bot token + chat_id + topics | ✅ 已收 |
| 6 | OpenRouter default model | ✅ default = `anthropic/claude-3.5-sonnet`,可在 `.env` 改 |

**仍待你确认 / 提供:**

7. **EA payload 字段补全**(见 5.1.1 节)— 需要决定要不要补送 type/volume/opened_at/currency/leverage/margin。建议补,我可以帮你改 EA
8. **VPS 域名 / IP** — 配 Nginx + HTTPS
9. **MT5 账户号 → Telegram topic 绑定** — seeder 只留了 5 个 placeholder topic,实际账户号要填进 `mt5_accounts.id` ↔ `telegram_topics.mt5_account_id`
10. **OpenRouter API key** — 你自己去 openrouter.ai 申请填 `.env`
11. **WebRequest allow-list** — 在 MT5 → Tools → Options → Expert Advisors → 把你的 Laravel 后端域名加进允许列表(EA 才能 POST)

---

## 14. 已确认的需求清单 ✅

- [x] 单 broker (RS Finance),多 MT5 账户
- [x] CRM 只读
- [x] EA → HTTP POST → Laravel API → MySQL
- [x] EA 鉴权:`.env` token,header 验证
- [x] AI 分析:**OpenRouter**,Job 排队,Chart API + 上周/本周/下周新闻 一起送
- [x] AI prompt 可随时调整(放在 `storage/app/prompts/currency_analysis.md`)
- [x] AI 输出固定 JSON schema
- [x] News scrape:**重写**,每日一次(上周+本周+下周)
- [x] 多用户,全员 admin,留 viewer
- [x] 前端:Inertia + Vue 3 + Tailwind (Sidebar layout)
- [x] VPS:16GB RAM Windows Server,目前只有 IIS
- [x] 数据刷新:10 秒一次
- [x] Charts:TradingView Widget(分析页) + Candlestick(performance)
- [x] Mobile responsive
- [x] Telegram 通知 (drawdown alert)
- [x] AI 结果保存 + 可导出报表
- [x] 时区:GMT+8
