# docs/DEPLOYMENT.md

# 部署与发布操作手册（Gitee → 宝塔 WebHook → Staging/Prod + WP-Lock 自动对齐）

## 1. 适用范围与目标

### 1.1 适用站点

* Staging：`staging.fanbaoer.com`
* Prod：`fanbaoer.com`

### 1.2 目标（冻结）

1. **代码发布**：仅发布 Git 白名单（自研插件/子主题/翻译等）。
2. **WordPress Core + 第三方插件/主题**：以线下（Laragon）为真源，通过 `wp-lock.json` 自动对齐到线上。
3. **全自动**：`push → webhook → 自动部署 → 自动 apply_lock → 验证`，无需手工干预。

---

## 2. 分支与环境映射（硬规则）

* `trial` 分支 → 仅部署到 **staging**
* `master` 分支 → 仅部署到 **prod**

发布原则：

* 先 `trial → staging` 验证通过，再进入 `master → prod`。

---

## 3. 服务器目录约定（真实路径）

### 3.1 站点目录

* Staging 站点目录：`/www/wwwroot/staging.fanbaoer.com`
* Prod 站点目录：`/www/wwwroot/fanbaoer.com`

### 3.2 Git 工作区

* Staging Git 工作区：`/www/git/agri-ecommerce-staging`
* Prod Git 工作区：`/www/git/agri-ecommerce-prod`

### 3.3 备份目录（部署脚本使用）

* Staging 备份目录：`/www/backup/fanbaoer_backups_staging`
* Prod 备份目录：`/www/backup/fanbaoer_backups_prod`

---

## 4. 发布内容范围（Git 白名单 + WP-Lock）

### 4.1 Git 白名单发布（只发布自研）

仅允许发布以下路径（示例）：

* `wp-content/themes/astra-child/`
* `wp-content/plugins/myshop-core/`
* `wp-content/languages/loco/`

禁止行为：

* 线上站点目录内出现 `.git`
* 直接在站点目录手工改业务代码（除少数环境配置文件，如 `wp-config.php`）

### 4.2 WP-Lock 管理范围（第三方）

由 `ops/wp_lock/wp-lock.json` 决定并自动对齐：

* WordPress Core 版本
* 第三方插件：版本 + 启用状态 + 多余项删除策略
* 第三方主题：版本 + 启用状态 + 多余项删除策略（包含父主题保护）

> 自研（`myshop-core` / `astra-child`）默认不通过 WP-CLI 安装/更新（走 Git 白名单），但**启停状态是否强制对齐**由 WP-Lock 策略决定（建议强制对齐，便于“绝对一致”）。

---

## 5. 一次性配置（必须完成）

### 5.1 允许 WP-CLI 更新，但禁止后台文件修改（关键）

你要求 hooks 自动更新 core/插件/主题，因此必须允许 **WP-CLI 写入文件**，但仍应禁止后台安装更新。

在 `wp-config.php` 中加入/确认以下配置（可直接复制粘贴）：

```php
// 后台禁止安装/更新/编辑（安全基线）
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_MODS', true);

// 仅对 WP-CLI 放行（允许部署 hooks 自动更新 core/插件/主题）
if (defined('WP_CLI') && WP_CLI) {
    define('DISALLOW_FILE_MODS', false);
}
```

---

## 6. 标准发布流程（唯一 SOP）

### 6.1 线下准备（真源）

在本地站点（例如 `E:\laragon\www\agri-ecommerce`）完成：

1. 梳理插件与主题：该启用的启用、该禁用的禁用。
2. 全部升级到最新版本（开发阶段不考虑兼容性，目标是验证流程可靠）。
3. 验证线下站点功能正常（至少能打开首页与关键页面）。

### 6.2 导出锁文件（线下执行）

导出 `ops/wp_lock/wp-lock.json`（具体命令见 `docs/WP_LOCK.md`）。

### 6.3 推送 trial（触发 staging 自动部署 + 自动对齐）

```bash
git checkout trial
git add -A
git commit -m "chore: update wp-lock and release"
git push origin trial
```

### 6.4 staging 验证（必须）

服务器命令验证：

```bash
/usr/local/bin/wp --allow-root --path=/www/wwwroot/staging.fanbaoer.com core version
/usr/local/bin/wp --allow-root --path=/www/wwwroot/staging.fanbaoer.com plugin list --format=table
/usr/local/bin/wp --allow-root --path=/www/wwwroot/staging.fanbaoer.com theme list --format=table
```

页面验证：

* 打开 `staging.fanbaoer.com` 首页
* 打开任意 Elementor 页面，确认样式与组件正常
* 如 Elementor 提示重建 CSS/数据：按提示执行一次即可（常见现象）

### 6.5 发布到 prod（master）

推荐流程：合并 `trial → master` 后推送 master：

```bash
git checkout master
git pull origin master
git merge --no-ff trial
git push origin master
```

prod 自动部署与 staging 完全一致（包含自动 apply_lock）。

### 6.6 prod 验证（必须）

```bash
/usr/local/bin/wp --allow-root --path=/www/wwwroot/fanbaoer.com core version
/usr/local/bin/wp --allow-root --path=/www/wwwroot/fanbaoer.com plugin list --format=table
/usr/local/bin/wp --allow-root --path=/www/wwwroot/fanbaoer.com theme list --format=table
```

---

## 7. 部署脚本内部步骤（用于排障与迭代）

部署脚本（staging/prod）应包含并按顺序执行：

1. 开启维护模式（Nginx + WP）
2. 备份站点目录（保留最近 N 份）
3. 同步 Git 工作区（fetch/reset 到目标分支）
4. rsync 白名单发布到站点目录
5. 执行 WP-Lock 自动对齐（apply_lock：core + plugins + themes + 启停 + 删除）
6. 关闭维护模式
7. 健康检查（curl + Host 头）

---

## 8. 手工执行部署（仅排障使用）

在宝塔 WebHook 插件中找到脚本路径后执行（示例）：

```bash
bash -n "/www/server/panel/plugin/webhook/script/<script_id>"
bash "/www/server/panel/plugin/webhook/script/<script_id>"
```

---

## 9. 回滚（最快恢复线上）

优先使用备份包回滚站点目录（示例 prod）：

```bash
ls -lt /www/backup/fanbaoer_backups_prod/ | head
cd /www/wwwroot/fanbaoer.com
tar -xzf /www/backup/fanbaoer_backups_prod/backup-YYYYMMDD-HHMMSS.tar.gz -C .
```

---

## 10. 常见故障最短排查

### 10.1 wp-cli 报数据库连接问题

检查 MySQL 服务与 socket 形态：

```bash
ss -lntp | grep 3306 || true
ps -ef | egrep "mysqld|mariadb" | grep -v grep || true
```

确保 `wp-config.php` 的 `DB_HOST` 与宝塔 MySQL socket 一致（例如 `localhost:/tmp/mysql.sock`），且不要重复定义。

下面这份就是 staging vs prod hooks 脚本差异点核对清单（≤10 行），你直接粘到部署操作手册末尾即可：

DEPLOY_ENV：staging=staging；prod=prod

GIT_DIR：staging=/www/git/agri-ecommerce-staging；prod=/www/git/agri-ecommerce-prod

SITE_DIR：staging=/www/wwwroot/staging.fanbaoer.com；prod=/www/wwwroot/fanbaoer.com

BACKUP_DIR：staging=/www/backup/fanbaoer_backups_staging；prod=/www/backup/fanbaoer_backups_prod

TARGET_BRANCH：staging=trial；prod=master

HEALTH_CHECK_HOST：staging=staging.fanbaoer.com；prod=fanbaoer.com

STATE_FILE：由 DEPLOY_ENV + TARGET_BRANCH 自动区分（不用手改，但要确认路径格式一致）

并发锁 LOCK_DIR：建议分别命名（staging 用 staging.lockdir；prod 用 prod.lockdir）

其他配置应保持一致：REPO_URL / NGINX_RELOAD_CMD / 白名单(ALLOW_*) / WP_CLI / WP-Lock 路径(ops/wp_lock/...)

一致性验收：日志必须同时出现 ✅ 发布完成（白名单） + ✅ WP-Lock 对齐完成 + ✅ 健康检查通过