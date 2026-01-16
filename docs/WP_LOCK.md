<meta name="description" content="WordPress 锁机制说明">
# docs/WP_LOCK.md

# WP-Lock 机制说明（线下真源 → 导出锁 → 线上自动对齐）

## 1. 目标与冻结规则

### 1.1 目标

* 线下（Laragon）是**唯一事实源**
* 通过 `ops/wp_lock/wp-lock.json` 锁定并表达：

  * WordPress Core 版本
  * 第三方插件：版本 + 启停状态
  * 第三方主题：版本 + 启停状态
* 线上（staging/prod）在部署 hooks 中自动 apply，无需手工干预

### 1.2 冻结规则（你最新决策）

1. 线下先整理到“你要的最终状态”（启用/禁用 + 升级到最新 + 功能正常）
2. 线上必须与线下**绝对一致**
3. 线上只保留：

   * 启用插件（active）
   * 启用主题（active）
   * **父主题依赖（必须保留）**：如启用 `astra-child`，必须保留 `astra`（即使它显示为 inactive）
4. 线上处理策略：

   * 未启用插件：**删除**
   * 未启用主题：**删除**（但父主题依赖例外）
5. 自研（`myshop-core` / `astra-child`）不走 WP-CLI 安装/更新（走 Git 白名单），但启停是否对齐由策略决定（建议对齐）。

---

## 2. 锁文件位置与作用

* 锁文件路径：`ops/wp_lock/wp-lock.json`
* 作用：部署后由 `apply_lock.py` 读取并把线上对齐到 lock 指定状态

---

## 3. 锁文件结构（字段约定）

建议结构（示意）：

* `core_version`: `"6.x.x"`
* `plugins`: 列表，每项包含：

  * `slug`
  * `version`
  * `status`：`active|inactive`
* `themes`: 列表，每项包含：

  * `stylesheet`
  * `version`
  * `status`：`active|inactive`
  * `parent`：（可选）父主题 stylesheet，用于 prune 保护
* `skip`:（可选）不允许通过 WP-CLI 安装/更新的项（自研/特殊来源）

---

## 4. 线下导出锁（Windows / Laragon）

### 4.1 进入站点目录

```powershell
cd E:\laragon\www\agri-ecommerce
```

### 4.2 导出锁文件（示例）

```powershell
python .\ops\wp_lock\export_lock.py `
  --wp "wp" `
  --site-dir "E:\laragon\www\agri-ecommerce" `
  --out ".\ops\wp_lock\wp-lock.json"
```

### 4.3 导出后自检

```powershell
type .\ops\wp_lock\wp-lock.json | more
```

---

## 5. 线上自动 apply（由部署 hooks 调用）

部署脚本在 rsync 白名单发布之后调用（示例命令）：

```bash
python3 ops/wp_lock/apply_lock.py \
  --wp /usr/local/bin/wp --allow-root \
  --site-dir /www/wwwroot/<site_dir> \
  --lock-file ops/wp_lock/wp-lock.json \
  --core --plugins --themes \
  --enforce-status --prune \
  --verbose
```

参数语义（必须支持）：

* `--core`：对齐 WordPress Core（必要时执行数据库升级）
* `--plugins`：对齐插件版本
* `--themes`：对齐主题版本
* `--enforce-status`：按 lock activate/deactivate
* `--prune`：删除不需要的插件/主题（按你冻结规则执行）
* `--verbose`：打印实际执行命令与结果

---

## 6. 主题父依赖保护（必须写清楚）

如果启用的是子主题（例如 `astra-child`）：

* 线上必须保留父主题（例如 `astra`），即使父主题处于 inactive
* 因此 `--prune` 删除主题时必须满足：

  * 删除“非 active 且不是 active 主题的 parent”的主题

---

## 7. 验证一致性（核心命令）

```bash
# core
wp --allow-root --path=/www/wwwroot/<site> core version

# plugins / themes（检查 version + status）
wp --allow-root --path=/www/wwwroot/<site> plugin list --format=table
wp --allow-root --path=/www/wwwroot/<site> theme list --format=table
```

---

## 8. 本地 WordPress Core 丢失的一键修复（Laragon 常见）

```powershell
cd E:\laragon\www\agri-ecommerce
wp core download --locale=zh_CN --skip-content --force
```

如 `wp-config.php` 缺失（仅示例，按你实际配置填写数据库信息）：

```powershell
copy wp-config-sample.php wp-config.php
```

---

## 9. 安全与排障开关（建议保留）

建议 `apply_lock.py` 支持以下开关（便于排障与迭代）：

* `--dry-run`：只打印计划，不执行
* `--plugins-only` / `--themes-only`：拆分执行
* `--no-downgrade`：禁止降级（你当前阶段可不启用，但建议预留）

---

## 10. 你要跑的“完整闭环”唯一流程（总结）

1. 线下梳理启用/禁用 → 全部升级最新 → 确认站点正常
2. 运行 `export_lock.py` 生成 `ops/wp_lock/wp-lock.json`
3. 提交并 push `trial` → staging 自动部署 + 自动对齐
4. staging 验证通过后，合并/推 `master` → prod 自动部署 + 自动对齐

---

如果你现在要我继续推进到“hooks 全自动 + 启停对齐 + prune 删除”的实现阶段：你把线下更新后的 `wp-lock.json` 提交并 push `trial` 后，我就按这两份文档为准，给你一套**不需要手工介入**的最终闭环落地方案（包括部署脚本中 apply_lock 的固定插入点与验证命令）。
