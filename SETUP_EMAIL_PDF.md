# 让 E-Receipt 真的寄出 PDF

目前的状态：`MAIL_MODE = 'dev'`，而且 `vendor/` 里只有 Stripe。
所以现在收据只会写进 `storage/mail.log`，也没有 PDF。

下面四步做完就会真的寄到 Gmail 收件匣。

---

## 第 1 步：装 Composer（如果还没装）

`vendor/` 里已经有 Stripe，代表你之前跑过 Composer，那这步可以跳过。

确认一下：开 CMD，输入

```
composer -V
```

有版本号就是装好了。如果显示「不是内部或外部命令」，去 <https://getcomposer.org/Composer-Setup.exe>
下载安装（一路 Next，它会自动侦测 XAMPP 的 php.exe）。装完**关掉 CMD 重开**。

---

## 第 2 步：装 Dompdf 和 PHPMailer

开 CMD，切到专案资料夹：

```
cd /d D:\Web-Based-Integrated-Systems-Assignment
composer require dompdf/dompdf phpmailer/phpmailer
```

跑完 `vendor/` 里应该多出 `dompdf/` 和 `phpmailer/` 两个资料夹。

`composer.json` 已经帮你写好依赖了，所以其实直接跑 `composer install` 也可以。

> **装不上？** 常见原因是 PHP 的 openssl 没开。
> 开 `C:\xampp\php\php.ini`，找到 `;extension=openssl`，把前面的分号删掉，
> 存档后重启 Apache。

---

## 第 3 步：确认 Gmail App Password

`lib/config.php` 里的 `SMTP_PASS` 必须是 **App Password**，不是你平常登入的密码。

产生方式：

1. 到 <https://myaccount.google.com/security>
2. 先开启 **两步骤验证**（没开就没有 App Password 这个选项）
3. 搜寻「应用程式密码 / App passwords」
4. 建立一个，名字随便打（例如 `Mobile2U`）
5. 会给你 16 个字元，例如 `abcd efgh ijkl mnop`，整串贴进 `SMTP_PASS`（空格可留可不留）

> **注意：学校信箱可能不行。** 你现在填的是 `@student.tarc.edu.my`，
> 这是 Google Workspace 帐号，很多学校的管理员会**停用 App Password**。
> 如果第 4 步测试一直失败，换一个个人 Gmail 帐号来寄，收件人还是可以填任何信箱。

---

## 第 4 步：切换成真的寄信

开 `lib/config.php`，把这行：

```php
define('MAIL_MODE', 'dev');
```

改成：

```php
define('MAIL_MODE', 'prod');
```

---

## 第 5 步：跑 E-Receipt 的 SQL

在 phpMyAdmin 选 `mobile2u` → SQL 分页 → 贴上 **`database/migration_07_ereceipt.sql`** → Go。

> 不要重跑整个 `migration_password_reset.sql`。
> phpMyAdmin 遇到第一个错误就停，你之前已经跑过前面几段，
> 重跑会卡在「Duplicate column name」，第 7 段永远执行不到。

---

## 第 6 步：验证

登入 admin，左侧选单点 **Mail & PDF**（`/admin/mail_test.php`）。

这一页会逐项检查：

| 检查项 | 没过的话怎么办 |
|--------|---------------|
| Composer autoloader loaded | 跑 `composer install` |
| Dompdf installed | 跑 `composer require dompdf/dompdf` |
| PHPMailer installed | 跑 `composer require phpmailer/phpmailer` |
| PHP openssl extension | 改 php.ini 后重启 Apache |
| MAIL_MODE is "prod" | 改 `lib/config.php` |
| From address matches SMTP account | 已自动处理 |
| storage/receipts writable | 给资料夹写入权限 |
| Receipt columns exist | 跑 migration SQL |

全绿之后，在同一页最下面填一个收件信箱，按 **Send Test Message**。
它会把最新一张订单的 PDF 当附件寄出去 —— 跟真正的收据走完全一样的路径。

收到了，就代表整条链路通了。

---

## PDF 下载在哪里

装好 Dompdf 之后，这三个地方会出现 **Download PDF** 按钮：

- 会员：订单详情页 `/order_detail.php?id=N` 右侧
- 会员：收据页 `/receipt.php?id=N` 顶部工具列
- 管理员：订单详情页 `/admin/order_detail.php?id=N` 右侧

按下去会直接下载 `Receipt-MU-2026-000042.pdf`。

寄出去的 PDF 会同时存一份在 `storage/receipts/`，
这个资料夹用 `.htaccess` 挡住了直接存取，只能透过 `receipt.php` 验证身分后才拿得到。

**没装 Dompdf 也能交作业**：按钮不会出现，但收据页还在，
按 **Print** 选「另存为 PDF」一样有 PDF，只是不是伺服器产生的。

---

## Demo 当天的建议

真的寄信依赖网路和 Google 的服务，示范时挂掉会很难看。两个作法：

**保险作法** — demo 前一晚录一段影片或截图，证明信真的收到了，
当天把 `MAIL_MODE` 切回 `dev`，现场展示 `storage/mail.log` 加上 PDF 下载。
PDF 是本地产生的，绝对不会失败。

**正常作法** — 保持 `prod`，但事先在同一个网路环境测过一次。

两种都能拿到分数，第二种比较好看，第一种比较稳。

---

## ⚠️ 交作业前一定要做

`lib/config.php` 现在同时存着：

- 你的 **Stripe secret key**
- 你的 **Gmail App Password**

**App Password 等于一把可以寄信的钥匙**，交出去的 ZIP 里带着它，
任何拿到档案的人都能用你的名义寄信。交之前请务必：

1. 到 Google 帐号安全性页面**撤销**那个 App Password（几秒钟的事）
2. 把 `SMTP_PASS` 和 `STRIPE_SECRET_KEY` 换成占位字串
3. 如果这个专案推过 GitHub，**历史 commit 里也会有**，记得也要撤销
