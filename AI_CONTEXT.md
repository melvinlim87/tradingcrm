# QuantATM — AI Context Sheet

> **Read this first.** Single-document onboarding for any AI agent who needs to work in this codebase. After reading, you should be able to make non-trivial changes without grepping aimlessly.

---

## 1. What this project is

**QuantATM** is a Laravel 12 + Vue 3 (Inertia) web dashboard that monitors MT5 retail-forex trading accounts in real time and runs weekly AI-generated technical analysis on currency pairs.

- **Read-only**: never places trades, only ingests + analyses.
- **Multi-tenant** via roles (`administrator` / `admin` / `user`) — see §6.
- **Production**: Windows Server (`C:\webserver\site\TradingCRM`), Laragon stack (Nginx + PHP 8.3 + MySQL 8).
- **Database name stays `tradingcrm`** — only the *branding* was renamed from TradingCRM to QuantATM.
- **Project root on dev machine**: `C:\Users\JunWe\Documents\Work\TradingCRM` (folder name not renamed).

---

## 2. Tech stack at a glance

| Layer | Tech |
|---|---|
| Backend | Laravel 12, PHP 8.3, MySQL 8 |
| Frontend | Vue 3 SFC via Inertia.js, Tailwind CSS, Chart.js (`vue-chartjs`) |
| Auth | Laravel Breeze (session-based) |
| MT5 link | MQL5 EA → HTTP POST → `/api/ea/*` (Bearer token in `services.ea.token`) |
| AI vision/text | OpenRouter (default model `qwen/qwen2.5-vl-72b-instruct`) |
| Chart screenshots | Aisita REST API → 3 base64 PNGs (M15/H1/H4). EA path is fallback. |
| Market data | Yahoo Finance v8 chart API (OHLC, no key) — text-only fallback |
| News | ForexFactory scrape (daily 06:00 SGT) + MT5 calendar (EA-pushed) + broker .htm files |
| Scheduling | Windows Task Scheduler runs `php artisan schedule:run` every minute. **No queue worker** — jobs use `dispatchSync()` / `dispatchAfterResponse()` |
| Alerts | Telegram (drawdown breaches per-account topic routing) |
| Timezone | All UI in **Asia/Singapore (GMT+8)**. All DB times in **UTC**. |

---

## 3. Repository map

```
app/
├── Console/Commands/
│   ├── GenerateWeeklyAnalysisCommand.php   ← `analysis:generate-weekly` (text+chart, no EA dep)
│   ├── ScrapeForexNewsCommand.php          ← `news:scrape` (daily 06:00)
│   └── PruneAccountSnapshotsCommand.php    ← `snapshots:prune` (daily 03:15)
├── Http/Controllers/
│   ├── DashboardController.php             ← /dashboard — multi-acc overview
│   ├── AnalysisController.php              ← /analysis — AI weekly outlook
│   ├── Mt5AccountController.php            ← /accounts — CRUD
│   ├── UserController.php                  ← /users + /admins (role-scoped)
│   └── Api/
│       ├── EaPushController.php            ← POST /api/ea/push (account+positions+history)
│       ├── EaChartController.php           ← chart-requests pending+upload (legacy/fallback)
│       └── EaNewsController.php            ← MT5 calendar + broker-news ingestion
├── Jobs/
│   └── AnalyzeCurrencyJob.php              ← Dispatches OpenRouter call. Auto-selects chart source.
├── Services/
│   ├── OpenRouterService.php               ← Wraps /api/v1/chat/completions, parses JSON
│   ├── AisitaChartService.php              ← Fetches 3 base64 PNGs from Aisita
│   ├── MarketDataService.php               ← Yahoo Finance OHLC + stats (text-only fallback)
│   ├── PromptRenderer.php                  ← {{var}} substitution. Reads storage/ then resources/
│   └── TelegramService.php                 ← Topic-routed sendMessage
├── Models/  (Eloquent)
│   ├── Mt5Account            (mt5_accounts)
│   ├── AccountSnapshot       (account_snapshots) — 10s ticks, downsampled by snapshots:prune
│   ├── OrderOpen / OrderPending / OrderHistory
│   ├── CurrencyAnalysis      (currency_analyses)
│   ├── ChartRequest / ChartExport — EA-driven legacy path
│   ├── ForexNews             (forex_news) — multi-source: forexfactory / mt5 / mt5_broker_news
│   ├── NewsFetchRequest      (news_fetch_requests) — on-demand MT5 calendar refresh
│   ├── TelegramTopic         (telegram_topics)
│   ├── AlertLog              (alert_logs) — drawdown alert dedupe
│   └── User                  (role: administrator | admin | user)
resources/
├── js/
│   ├── Pages/                ← Inertia Vue pages
│   │   ├── Dashboard.vue
│   │   ├── Analysis/Index.vue
│   │   ├── Accounts/Index.vue
│   │   └── Users/Index.vue
│   ├── Components/
│   │   └── PortfolioChart.vue ← Chart.js wrapper, white theme, single-series
│   └── Layouts/AuthenticatedLayout.vue
└── prompts/
    └── currency_analysis.md   ← AI prompt template, git-tracked default
storage/app/prompts/
└── currency_analysis.md       ← Admin override (gitignored, overrides resources/ if present)
routes/
├── web.php       ← all /dashboard /analysis /accounts /users etc.
├── api.php       ← all /api/ea/* (verify.ea middleware)
└── console.php   ← scheduler: analysis (Mon 07:00), news scrape (daily 06:00), prune (daily 03:15)
ea/
├── TradingCRM_EA.mq5         ← Single combined EA (Risk Monitor + Chart Exporter + News Pusher) v3.71
└── Risk_Monitor_With_Quant_Trading_v2.mq5  ← legacy reference only, do not install
config/services.php           ← All third-party creds (openrouter / aisita / ea / telegram)
database/migrations/          ← chronological, 2026_05_22_* are the QuantATM ones
tests/Feature/WalkthroughTest.php  ← 18 e2e tests covering every user flow + EA push
```

---

## 4. Data flow — the 5 things to remember

### 4.1 EA → Backend (every 10 s, push)

```
MT5 + TradingCRM_EA.mq5
   │  POST /api/ea/push { account, positions, pending_orders, history, performance, ... }
   ▼
EaPushController::store()
   • Reject if account_number not in mt5_accounts
   • Single DB transaction: updateAccount → writeSnapshot → replaceOpenPositions
                            → replacePendingOrders → upsertHistory
   • Then checkDrawdownAlert() → TelegramService if breach
```

Notes:
- `replaceOpenPositions` / `replacePendingOrders` are **delete-then-insert** — never accumulate stale rows.
- `upsertHistory` is keyed on `(mt5_account_id, ticket)` — safe to re-send.
- `last_ping_at` updates on every push → drives the green "online" badge.
- If account-level updates appear but positions stay 0, EA is sending empty arrays (verify in MT5 Toolbox → Trade tab).

### 4.2 Generate Weekly Analysis (CLI or "Generate Now" button)

```
[trigger]
  CLI: php artisan analysis:generate-weekly [--symbols=AAA,BBB] [--force]
              [--include-traded] [--traded-only]
  UI:  POST /analysis/generate → Bus::dispatchAfterResponse(new AnalyzeCurrencyJob)
   ▼
AnalyzeCurrencyJob::handle()
   • Picks chart source in priority:
       1. AisitaChartService (M15/H1/H4 base64 PNGs)      → mode = aisita_vision
       2. EA-uploaded ChartRequest (H4/D1/W1) if exists   → mode = ea_vision
       3. Yahoo Finance OHLC text                         → mode = text_only
   • Pulls news from forex_news (HIGH-impact, past 1 month + this/next week)
   • PromptRenderer.render() with {{symbol}} {{market_data}} {{news_*}} {{response_schema}}
   • OpenRouterService.analyze(prompt, images) → JSON {outlook, bias_score, confidence, summary, ...}
   • Saves to currency_analyses, status → completed
```

**Why two paths exist:** Aisita is the production default (no EA dependency, always works). EA path remains as fallback for vision analysis when the user prefers MT5-rendered charts.

### 4.3 News pipeline (three sources)

| `forex_news.source` | Sourced by | Frequency |
|---|---|---|
| `forexfactory` | `news:scrape` command (scrapes Forex Factory HTML) | daily 06:00 SGT |
| `mt5` | EA pushes `MqlCalendarValue` via `POST /api/ea/news` | every 900s + on-demand via NewsFetchRequest |
| `mt5_broker_news` | EA scans `MQL5/Files/news/*.htm` → `POST /api/ea/news/broker` | every 300s |

On-demand "Refresh MT5 News" button: creates `NewsFetchRequest` → EA polls `/api/ea/news-requests/pending` → pulls calendar → marks complete/fail. If EA reports `mt5_calendar_empty (err=4001)`, the broker has the calendar feed disabled — fall back on ForexFactory.

### 4.4 Dashboard rendering (every 10 s reload)

`DashboardController::index()` runs **3 batched SQL queries** to avoid N+1:
1. `loadOrdersBatched()` × 3 (open / pending / history)
2. `aggregateProfitSeriesByAccount()` — single `GROUP BY mt5_account_id, DATE(close_at SGT) SUM(pnl)`
3. `mergeAccountProfitSeries()` — sum across accounts for the Overall chart

`PortfolioChart.vue` is a single-series Chart.js wrapper used both for **overall** (sums) and **per-account** (one chart per account) sections, white theme, emerald (positive) / red (negative).

### 4.5 Drawdown alerts

On every EA push, `checkDrawdownAlert()` compares current DD% vs `drawdown_alert_threshold` (default 2.0%). If breached AND no alert for this account in past 60 min (dedup via `alert_logs`), fires `TelegramService::send($accountId, $message)` which:
1. Finds the `TelegramTopic` bound to this account → looks up `chat_id` + `message_thread_id`
2. POSTs to Telegram Bot API

---

## 5. The Aisita chart API (current chart source)

```
POST https://dev-backend.aisita.ai/api/gold-chart
Authorization: Bearer <AISITA_CHART_TOKEN>
{ "symbol": "EURUSD" }

→ { "success": true, "data": [
      { "type":"image", "content_type":"image/png", "data":"<base64>" },   // M15
      { "type":"image", "content_type":"image/png", "data":"<base64>" },   // H1
      { "type":"image", "content_type":"image/png", "data":"<base64>" },   // H4
   ]}
```

Stored at `storage/app/public/charts/aisita/{analysisId}/{tf}.png`, public URL `…/storage/charts/aisita/…`. Requires `php artisan storage:link` to be run once.

---

## 6. Roles (`users.role`)

| Role | Can see | Can manage |
|---|---|---|
| **administrator** | All accounts, all users, "added by" column | Create admins + users + all accounts |
| **admin** | Only accounts they created | Create users, manage own accounts |
| **user** | Only accounts they're attached to via `user_accounts` | Nothing |

Scoping is enforced inside controllers via `User::visibleAccountsQuery()`. **Always use this** when introducing new queries that touch `mt5_accounts`.

---

## 7. Auth & API security

- **Web routes**: Laravel session auth via Breeze.
- **API routes** (`/api/ea/*`): custom `verify.ea` middleware reads `Authorization: Bearer <token>`, compares to `config('services.ea.token')`. Token lives in `.env` `EA_PUSH_TOKEN`.
- EA inputs include `InpEaToken` — must match `.env`.

---

## 8. Critical env vars

```env
APP_NAME=QuantATM
APP_TIMEZONE=Asia/Singapore   # not the default UTC
APP_URL=https://quant.lazetrader.com

DB_CONNECTION=mysql
DB_DATABASE=tradingcrm        # kept original name

EA_PUSH_TOKEN=<64-char hex>

OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_MODEL=qwen/qwen2.5-vl-72b-instruct
OPENROUTER_TEMPERATURE=0.9

AISITA_CHART_URL=https://dev-backend.aisita.ai/api/gold-chart
AISITA_CHART_TOKEN=...

TELEGRAM_BOT_TOKEN=...
TELEGRAM_DEFAULT_CHAT_ID=...
TELEGRAM_DRAWDOWN_THRESHOLD=2.0
```

---

## 9. Common operations

```bash
# Run all tests (18 in WalkthroughTest)
php artisan test --filter=WalkthroughTest

# Trigger weekly analysis for all 15 default pairs
php artisan analysis:generate-weekly --force

# Generate for only currently-traded symbols (from open/pending orders)
php artisan analysis:generate-weekly --traded-only --force

# Build frontend (Vite)
npm run build

# Clear caches (run after .env or config/* changes)
php artisan config:clear
php artisan view:clear

# One-time public storage link (after fresh clone)
php artisan storage:link
```

---

## 10. Gotchas — read before editing

1. **No queue worker.** Don't use `dispatch()` expecting it to run later — use `dispatchSync()` (CLI) or `Bus::dispatchAfterResponse()` (HTTP). The `jobs` table exists but nothing reads it.
2. **`storage/app/` is .gitignored.** Anything that must survive a fresh `git pull` deploy belongs in `resources/`. Prompt template lookup is layered: `storage/app/prompts/` (admin override) → `resources/prompts/` (shipped default).
3. **Windows file-permission trap.** Production `storage/logs/laravel.log` needs ACL write for IIS_IUSRS / Users — failed log writes cascade into job failures.
4. **`OpenRouterService` returns wrapped result:** `{parsed, raw, model, tokens}`. The `parsed` value goes through `extractJson()` which tolerates markdown code-fences but **does not** retry — if AI hedges to `{"bias_score":"50"}` (string), `clampBiasScore()` casts it.
5. **`account_name` defaults to NULL.** Dashboard shows orange "(Unnamed — click to set name)" linking to `/accounts` when empty. Don't assume it's populated.
6. **Broker symbol suffixes.** `EURUSD` on user's broker may actually be `EURUSD.m` or `EURUSD#`. The EA's `ResolveBrokerSymbol()` tries 16 suffixes + scans full broker tree. Backend stores the canonical (un-suffixed) symbol.
7. **`apostrophe in symbols`.** Symbols are always uppercase, stripped to `[A-Z]+`, length 6 or 7 (XAUUSD). Always normalize before queries.
8. **Time zones.** DB columns are UTC. Display is GMT+8. Use `Carbon\CarbonImmutable::now('Asia/Singapore')` for date-bucketing logic, then `.utc()` before WHERE clauses against `event_at` / `closed_at`.
9. **Dashboard refresh = full Inertia reload every 10 s** (`router.reload({ only: ['accounts', 'overall'] })`). Don't add expensive new computeds without considering this.
10. **WalkthroughTest uses `Bus::fake()`** in `test_analysis_generate_creates_records_and_redirects` to prevent `AnalyzeCurrencyJob` from actually calling OpenRouter during CI.

---

## 11. Known integrations / external dependencies

| Service | Purpose | Failure behaviour |
|---|---|---|
| OpenRouter | LLM for analysis | Job catches → marks analysis `failed` with error_message |
| Aisita | Chart screenshots | `AnalyzeCurrencyJob` falls back to EA charts, then to Yahoo OHLC |
| Yahoo Finance | OHLC for text-only mode | Logs warning, throws → analysis fails |
| ForexFactory | Calendar scraping | `news:scrape` logs error, exits non-zero |
| Telegram | Drawdown alerts | Logs error, alert not delivered (next breach re-tries) |
| MT5 EA | Account + chart + calendar push | Backend works without it but data goes stale |

---

## 12. When in doubt

- **"Where does X come from?"** Grep `app/Services/` first. Almost all external IO is wrapped here.
- **"Which controller renders this page?"** `routes/web.php` → controller → look for `Inertia::render('PagePath/Index', [...])`.
- **"Where are EA endpoints?"** `routes/api.php` — all under `/api/ea/` prefix with `verify.ea` middleware.
- **"What runs on a schedule?"** `routes/console.php` is the only place.
- **"Did the migration ship?"** `database/migrations/2026_05_22_*` — chronological. `php artisan migrate:status` to verify.
- **"What's the test coverage?"** Single feature test file: `tests/Feature/WalkthroughTest.php` (18 cases). Always run after non-trivial changes.

---

*Last updated 2026-05-27. If the file/folder layout drifts, refresh §3 first.*
