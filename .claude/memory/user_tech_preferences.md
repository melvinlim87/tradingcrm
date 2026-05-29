---
name: User tech stack preferences
description: 用户在 Windows VPS 上做 web app 的技术偏好与既有资源
type: user
originSessionId: 8119c595-21a5-40b1-84cd-c97a639b7e49
---
**Backend 偏好:** Laravel + MySQL(主力,已有现成 AI 分析、新闻 scrape 的 Laravel code 可复用)

**Frontend:** 没有强烈偏好,愿意接受推荐 — 接受 Inertia + Vue 3 + Tailwind 方案

**部署环境:** Windows Server VPS(16GB RAM,目前仅装 IIS),通常跑 5+ MT5 terminal

**之前经验:** 在 Linux 上用 supervisor 做定时任务,Windows 上需要等价替代方案(已建议 Laravel Scheduler + Task Scheduler + NSSM)

**通知偏好:** Telegram channel(已有 channel,bot token 后续提供)

**领域:** 外汇交易 / MT5 自动化,熟悉 EA、broker、currency pair 等术语

**How to apply:**
- 给方案时直接用 Laravel 生态(不要推 Node.js 后端、Django 等)
- Windows 环境的服务化与定时任务一律按 Laragon + NSSM + Task Scheduler 思路给
- 解释 trading 相关功能时可直接用术语,不必从零科普
- 后端建议优先复用用户既有的 PHP code,而非重写
