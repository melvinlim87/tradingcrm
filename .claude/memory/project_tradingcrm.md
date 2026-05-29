---
name: TradingCRM project context
description: TradingCRM 项目的核心需求、技术栈与架构决策 — 用于后续 session 快速恢复上下文
type: project
originSessionId: 8119c595-21a5-40b1-84cd-c97a639b7e49
---
**项目:** TradingCRM — MT5 多账户监控 + AI 货币分析 dashboard (read-only)

**Stack:**
- Backend: Laravel 11 + PHP 8.3
- DB: MySQL 8
- Frontend: Inertia.js + Vue 3 + Tailwind + shadcn-vue
- Charts: TradingView Widget (分析页) + ApexCharts (performance)
- Queue: database driver,worker via NSSM Windows Service
- Scheduler: Laravel + Windows Task Scheduler (每分钟跑 `schedule:run`)
- Web server: Nginx via Laragon Full (停掉 IIS)

**关键决策:**
- EA → HTTP POST `/api/ea/push` 每 10 秒,header `X-EA-Token` 验证(token in `.env`)
- 单一 broker (RS Finance),多 MT5 账户,read-only 不下单
- 多用户全员 admin,留 viewer role schema
- Dashboard 10 秒 polling 刷新
- 时区 GMT+8 (Asia/Singapore)
- Mobile responsive 必做
- Telegram:forum group `-1003995294956`,bot token in .env;topic 映射存 `telegram_topics` 表 (account_1..5, overall_reporting, risk_management, daily/weekly/monthly_report);drawdown 默认阈值 2.0%;每账户 alert 同时发 account topic + risk_management topic
- AI 分析:**OpenRouter**(default `anthropic/claude-3.5-sonnet`),event-driven 流程:
  1. AnalysisController 建 `currency_analyses` (pending) + `chart_requests` (pending)
  2. ChartExporter EA poll `/api/ea/chart-requests/pending` 每 10s
  3. EA 对 H4/D1/W1 各开 chart、加 MA50+MACD+RSI、ChartScreenShot、POST PNG binary 到 `/api/ea/chart-exports`
  4. 第 3 张图收到 → EaChartController dispatch `AnalyzeCurrencyJob`
  5. Job 读 `chart_exports.public_url` + `forex_news` (last/this/next week) → render prompt → OpenRouter multimodal
  6. 解析 JSON → 存 `currency_analyses`
  7. Job 等图阶段用 `$this->release(30)` self-defer,超 10min timeout
- AI prompt 不写死:放在 `storage/app/prompts/currency_analysis.md`,用 `{{var}}` placeholder,每次 job 跑都重读。每次分析的完整 prompt snapshot 存进 `currency_analyses.prompt_used`
- ChartApiService 已 deprecated 改成 stub,charts 全部由 MT5 EA 自出(`ea/ChartExporter.mq5`)
- News scrape:重写为 `ScrapeForexNewsCommand`(signature `news:scrape`),每天一次抓 ForexFactory last/this/next week JSON 三个 endpoint
- AI 结果可导出报表

**支援货币 (单一货币分析):** AUD, CAD, EUR, GBP, CHF, NZD, USD, SGD, JPY

**Why:** 用户在 trading 行业,需要同时监控多个 MT5 账户的实时表现 + 用 AI 辅助每周货币分析判断。Read-only 减少风险;统一 dashboard 取代逐个开 MT5 terminal。

**How to apply:**
- 任何 code 改动 follow Laravel 11 conventions
- API endpoint 处理 EA payload 时记得 upsert account + 写 snapshot + 全量替换 open/pending + dedupe history
- 写前端时优先 mobile responsive,sidebar 是必要的
- 时区相关一律 Asia/Singapore
- 文档 plan.md 与 setup.md 在项目根目录,改动需求时同步更新

**EA payload 已知缺漏(建议补完整):** position/pending/history 的 type/volume/opened_at/swap/commission/current_price/open_price;account 的 currency/leverage/margin/free_margin;history 只送最新 20 笔(backend 必须按 ticket upsert 累积)。

**等待用户提供:**
1. EA payload 字段补送(决定要不要改 EA)
2. VPS 域名 / IP — 配 Nginx + HTTPS
3. MT5 账户号到 telegram_topics.mt5_account_id 的绑定(等账户接进来)
4. OpenRouter API key(用户自己申请)
5. MT5 WebRequest allow-list 加 Laravel 后端域名
