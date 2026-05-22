# TradingCRM — Windows Server 环境安装指引

> 目标:在你 16GB RAM 的 Windows Server VPS 上,搭好 **Nginx + PHP 8.3 + MySQL 8 + Laravel 11 + Queue Worker + Scheduler** 的完整生产环境。

---

## 推荐方案:**Laragon Full**

为什么不用 IIS / XAMPP:
| Web Server | 优 | 劣 | 结论 |
|---|---|---|---|
| **IIS** | Windows 原生 | PHP 配置繁琐,Laravel 路由要写 web.config,queue worker 麻烦 | ❌ 不推荐 |
| **XAMPP** | 容易装 | Apache 单 process,Windows 上服务化不友好 | ⚠️ 凑合 |
| **Laragon Full** | Nginx + PHP-FPM + MySQL + Composer + Node + Redis 一键全装,可注册成 Windows Service,Laravel friendly | 名字像玩具但其实非常稳 | ✅ **推荐** |

---

## 安装步骤

### 步骤 0:停掉 IIS(或让出 80/443 端口)

PowerShell (Admin):
```powershell
# 停 IIS
Stop-Service W3SVC
Set-Service W3SVC -StartupType Disabled

# 确认 80/443 没人占
netstat -ano | findstr ":80 "
netstat -ano | findstr ":443 "
```

> 如果将来还要用 IIS 跑其他东西,可让 Laragon 走 8080/8443,但建议先专注 TradingCRM。

---

### 步骤 1:下载并安装 Laragon Full

1. 到 https://laragon.org/download/ 下载 **Laragon Full** (约 150MB)
2. 安装路径建议:`C:\laragon\`
3. 安装时勾选:
   - ✅ Add Laragon to PATH
   - ✅ Auto virtual hosts
   - ✅ Don't put files & databases in laragon folder (建议关掉,后续会迁出)

安装完成后,Laragon 会自带:
- Nginx (latest)
- Apache (备用)
- PHP 8.3.x
- MySQL 8 (or MariaDB)
- Composer
- Node.js + npm
- Redis
- Git

---

### 步骤 2:升级 PHP 到 8.3(若 Laragon 自带不是)

1. 到 https://windows.php.net/download/ 下载 PHP 8.3 **NTS (Non-Thread-Safe) x64**
2. 解压到 `C:\laragon\bin\php\php-8.3.x-Win32-vs16-x64\`
3. 打开 Laragon → 右键 → PHP → Version → 切到 8.3.x
4. 确认 `php.ini` 启用以下扩展(去掉前面的 `;`):
   ```
   extension=curl
   extension=fileinfo
   extension=gd
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=mysqli
   extension=intl
   extension=zip
   extension=bcmath
   extension=exif
   ```
5. 调整:
   ```ini
   memory_limit = 512M
   upload_max_filesize = 50M
   post_max_size = 50M
   max_execution_time = 300
   date.timezone = "Asia/Singapore"
   ```

---

### 步骤 3:配置 MySQL

打开 Laragon → 右键 → MySQL → 启动

1. 初始 root 密码:在 Laragon → Tools → MySQL → "Change root password"
2. 创建数据库:
   ```sql
   CREATE DATABASE tradingcrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'tradingcrm'@'localhost' IDENTIFIED BY '强密码放这';
   GRANT ALL PRIVILEGES ON tradingcrm.* TO 'tradingcrm'@'localhost';
   FLUSH PRIVILEGES;
   ```
3. 测试连线:`mysql -u tradingcrm -p tradingcrm`

---

### 步骤 4:把 Laragon 注册成 Windows Service(开机自启)

```powershell
# 以管理员身份打开 cmd,进 Laragon 目录
cd C:\laragon
laragon.exe service install
```

之后:
- Laragon 会以 Windows Service 模式运行
- 在 `services.msc` 看到 `Laragon`
- 开机自启动,Nginx + MySQL + PHP-FPM 都跟着自启

---

### 步骤 5:Composer & Node 验证

```powershell
composer --version    # 应显示 Composer 2.x
node --version        # 应显示 v20+
npm --version
```

如果不是最新,去:
- Composer: https://getcomposer.org/download/
- Node: https://nodejs.org/en/download/ (建议 LTS)

---

### 步骤 6:创建 Laravel 项目

```powershell
cd C:\laragon\www
composer create-project laravel/laravel tradingcrm
cd tradingcrm

# Inertia + Vue
composer require laravel/breeze --dev
php artisan breeze:install vue
npm install
npm run build

# Tailwind 已经跟 Breeze 一起来了
```

Laragon **auto virtual hosts** 会自动把 `C:\laragon\www\tradingcrm\public` 映射到 `http://tradingcrm.test`。

> Auto vhost 改 host file: `C:\Windows\System32\drivers\etc\hosts` 自动写入 `127.0.0.1 tradingcrm.test`

---

### 步骤 7:配置 Laravel `.env`

```ini
APP_NAME=TradingCRM
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_TIMEZONE=Asia/Singapore

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tradingcrm
DB_USERNAME=tradingcrm
DB_PASSWORD=强密码放这

QUEUE_CONNECTION=database
SESSION_DRIVER=database

# EA push token (生成一个随机长字串)
EA_PUSH_TOKEN=换成你随机生成的64位字符串

# Telegram (之后补)
TELEGRAM_BOT_TOKEN=
TELEGRAM_DEFAULT_CHAT_ID=

# AI (如果你的 function 需要 API key)
AI_API_KEY=
```

生成 token 用 PowerShell:
```powershell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 64 | % {[char]$_})
```

跑 migration:
```powershell
php artisan key:generate
php artisan migrate
php artisan queue:table
php artisan migrate
```

---

### 步骤 8:Queue Worker → 注册成 Windows Service (NSSM)

#### 8.1 安装 NSSM
1. 下载 https://nssm.cc/release/nssm-2.24.zip
2. 解压,把 `win64\nssm.exe` 复制到 `C:\Windows\System32\nssm.exe`

#### 8.2 注册 Queue Worker
PowerShell (Admin):
```powershell
nssm install TradingCRM-Queue
```

弹窗里填:
| Tab | 字段 | 值 |
|---|---|---|
| Application | Path | `C:\laragon\bin\php\php-8.3.x\php.exe` |
| Application | Startup directory | `C:\laragon\www\tradingcrm` |
| Application | Arguments | `artisan queue:work --tries=3 --timeout=300 --sleep=3` |
| Details | Display name | `TradingCRM Queue Worker` |
| Details | Startup type | Automatic |
| I/O | Output (stdout) | `C:\laragon\www\tradingcrm\storage\logs\queue-stdout.log` |
| I/O | Error (stderr) | `C:\laragon\www\tradingcrm\storage\logs\queue-stderr.log` |

启动:
```powershell
nssm start TradingCRM-Queue
nssm status TradingCRM-Queue
```

> ⚠️ 之后改 code 要重启 worker:`nssm restart TradingCRM-Queue`

---

### 步骤 9:Laravel Scheduler → Windows Task Scheduler

#### 9.1 创建 Task
1. Win+R → `taskschd.msc`
2. 右侧 **Create Task...** (不是 Basic Task)
3. **General:**
   - Name: `TradingCRM Scheduler`
   - 勾 "Run whether user is logged on or not"
   - 勾 "Run with highest privileges"
4. **Triggers:** New
   - Begin the task: On a schedule
   - Daily, Recur every 1 day
   - **Repeat task every: 1 minute,for a duration of: Indefinitely**
5. **Actions:** New
   - Action: Start a program
   - Program: `C:\laragon\bin\php\php-8.3.x\php.exe`
   - Arguments: `artisan schedule:run`
   - Start in: `C:\laragon\www\tradingcrm`
6. **Conditions:** 取消勾 "Start only if on AC power" (VPS 无所谓)
7. **Settings:**
   - 勾 "Allow task to be run on demand"
   - "If the task is already running" → **Do not start a new instance**

#### 9.2 测试
PowerShell:
```powershell
schtasks /run /tn "TradingCRM Scheduler"
```

查看 Laravel scheduler 是否触发:`storage\logs\laravel.log`

---

### 步骤 10:HTTPS 配置 (Let's Encrypt for Windows)

#### 10.1 安装 win-acme
1. 下载 https://www.win-acme.com/
2. 解压到 `C:\tools\win-acme\`

#### 10.2 配置 Nginx vhost (TLS)
编辑 `C:\laragon\etc\nginx\sites-enabled\auto.tradingcrm.test.conf` (Laragon 自动生成的) 或自己新建:

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    root C:/laragon/www/tradingcrm/public;
    index index.php;

    ssl_certificate     C:/tools/win-acme/certs/yourdomain.com-crt.pem;
    ssl_certificate_key C:/tools/win-acme/certs/yourdomain.com-key.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 限制 EA push body size
    client_max_body_size 10M;
}

# HTTP → HTTPS redirect
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

重启 Nginx:Laragon 右键 → Nginx → Reload

#### 10.3 跑 win-acme 申请证书
```powershell
cd C:\tools\win-acme
.\wacs.exe
```
选 `M` Create certificate (manual input) → 输入 domain → 选 HTTP-01 验证 → 完成。

> 证书会自动续期(win-acme 会自己装一个 Windows scheduled task)。

---

### 步骤 11:Windows Firewall 规则

打开 80 / 443:
```powershell
New-NetFirewallRule -DisplayName "HTTP"  -Direction Inbound -LocalPort 80  -Protocol TCP -Action Allow
New-NetFirewallRule -DisplayName "HTTPS" -Direction Inbound -LocalPort 443 -Protocol TCP -Action Allow
```

> MySQL (3306) 不要对外开放,只 localhost。

---

### 步骤 12:把 EA 的请求 IP 加白名单 (建议)

如果 EA 跑在同一台 VPS 上,EA 用 `http://127.0.0.1/api/ea/push` 即可,绕过外网。

如果 EA 在外部:
- 在 Laravel middleware 加 IP whitelist (`config/api.php` → `allowed_ea_ips`)
- 或在 Nginx `/api/ea/push` location 加 `allow / deny`

---

### 步骤 13:日志 & 监控

#### Laravel logs
- 应用日志:`C:\laragon\www\tradingcrm\storage\logs\laravel.log`
- Queue worker:`storage\logs\queue-*.log`

#### Nginx logs
- `C:\laragon\etc\nginx\logs\access.log`
- `C:\laragon\etc\nginx\logs\error.log`

#### 自动清理 log (避免爆盘)
Laravel 自带 log rotation,在 `config/logging.php`:
```php
'channels' => [
    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => 'debug',
        'days' => 14,   // 留 14 天
    ],
],
```

`.env`:
```
LOG_CHANNEL=daily
```

---

### 步骤 14:MySQL 备份 (建议)

PowerShell script: `C:\scripts\backup-mysql.ps1`
```powershell
$date = Get-Date -Format "yyyyMMdd-HHmm"
$backupDir = "C:\backups\mysql"
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

& "C:\laragon\bin\mysql\mysql-8.0.x\bin\mysqldump.exe" `
    -u tradingcrm -p"强密码" `
    --single-transaction --routines --triggers `
    tradingcrm > "$backupDir\tradingcrm-$date.sql"

# 留 7 天
Get-ChildItem $backupDir -Filter "*.sql" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-7) } |
    Remove-Item
```

挂到 Task Scheduler,每天 03:00 跑。

---

## 完工 Checklist

- [ ] IIS 已停 / 让出 80, 443
- [ ] Laragon Full 安装完成
- [ ] PHP 8.3 + 必要扩展启用
- [ ] MySQL 8 + `tradingcrm` 数据库 + user
- [ ] Laragon 注册为 Windows Service
- [ ] Laravel 项目跑起来 (`http://tradingcrm.test` 能开)
- [ ] `.env` 配置完成 (含 `EA_PUSH_TOKEN`)
- [ ] Migration 跑完
- [ ] NSSM 注册 Queue Worker service,running
- [ ] Task Scheduler 跑 `schedule:run` 每分钟
- [ ] win-acme 申请 HTTPS 证书
- [ ] Firewall 80/443 开放
- [ ] MySQL daily backup 设定
- [ ] Telegram bot 设定 (等你 token)

---

## 常见踩坑提醒

1. **PHP 不要装 Thread-Safe (TS) 版本**,要 **NTS**,FPM 才能跑。
2. **Laragon auto vhost** 改 hosts 文件,如果有 antivirus 会拦,要放行。
3. **MySQL `caching_sha2_password` vs `mysql_native_password`** — Laravel 11 没问题,如果用旧 client 报 auth error,改用 native:
   ```sql
   ALTER USER 'tradingcrm'@'localhost' IDENTIFIED WITH mysql_native_password BY '强密码';
   ```
4. **Queue worker 改 code 后不会自动 reload**,要 `nssm restart TradingCRM-Queue`。
5. **Windows Task Scheduler 不会 raise error**,记得跑完看 Laravel log 确认。
6. **PHP 8.3 + Laragon 自带的可能比较旧**,建议手动下载最新 patch (8.3.x)。
7. **VPS 跑 5 个 MT5 + Laravel + MySQL + Queue + Nginx** 在 16GB 应付有余,但留意 MT5 占内存,可以关掉它们的 chart 渲染省 RAM。

---

需要我帮你写 PowerShell 一键安装脚本(自动跑步骤 0–6)的话告诉我。
