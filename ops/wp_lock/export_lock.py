#!/usr/bin/env python3
"""ops/wp_lock/export_lock.py

WordPress 锁文件导出工具（站点 -> wp-lock.json）

目标
- 以“线下站点”为基线，导出可用于线上严格同步的 lock 文件。
- **只导出 active 插件**（符合主线：线上只安装激活插件，未激活全部删除）。
- **只导出 active 主题**，并自动补齐其父主题（template）为 required（父主题必须保留，否则 child theme 无法工作）。
- 支持标记 git/自研白名单：skip_plugins / skip_themes（不会被 apply_lock 安装/删除，但可对齐启停）。

输出 schema
- schema_version: 2
- core_version: str
- plugins: [{name, version, status:"active"}]
- themes: [{stylesheet, version, status:"active"|"required"}]
- skip_plugins / skip_themes: [str]

使用示例（在线下站点目录执行）
  python3 ops/wp_lock/export_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --out ops/wp_lock/wp-lock.json \
    --skip-plugin myshop-core \
    --skip-theme astra-child \
    --verbose
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional


def _norm_path(p: str) -> str:
    return os.path.abspath(os.path.expanduser(p.strip()))


def _run(cmd: List[str], verbose: bool = False) -> str:
    if verbose:
        print(f"[export_lock] run: {' '.join(cmd)}")
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "").strip()
        raise RuntimeError(f"wp-cli failed: {' '.join(cmd)}\n{err}")
    return (proc.stdout or "").strip()


def _run_json(cmd: List[str], verbose: bool = False) -> List[Dict[str, Any]]:
    out = _run(cmd, verbose=verbose)
    if not out:
        return []
    data = json.loads(out)
    if isinstance(data, list):
        return [x for x in data if isinstance(x, dict)]
    raise ValueError("Unexpected JSON output (not a list)")


def _build_wp_cmd(wp_bin: str, site_dir: str, allow_root: bool, args: List[str]) -> List[str]:
    cmd = [wp_bin]
    if allow_root:
        cmd.append("--allow-root")
    cmd.append(f"--path={site_dir}")
    cmd.extend(args)
    return cmd


def _strip_php_suffix(s: str) -> str:
    s = s.strip()
    if s.lower().endswith(".php"):
        return s[:-4]
    return s


def _pick_plugin_id(item: Dict[str, Any]) -> str:
    # wp-cli 常见字段：name / plugin（可能是 hello.php 或 hello-dolly/hello.php）
    for k in ("name", "slug"):
        v = item.get(k)
        if isinstance(v, str) and v.strip():
            return _strip_php_suffix(v.strip())

    v = item.get("plugin")
    if isinstance(v, str) and v.strip():
        base = v.strip().split("/", 1)[0]
        return _strip_php_suffix(base)

    return ""


def _pick_theme_id(item: Dict[str, Any]) -> str:
    for k in ("stylesheet", "name"):
        v = item.get(k)
        if isinstance(v, str) and v.strip():
            return v.strip()
    return ""


def _version(item: Optional[Dict[str, Any]]) -> str:
    if not item:
        return ""
    v = item.get("version")
    return str(v).strip() if v is not None else ""


def main() -> None:
    parser = argparse.ArgumentParser(description="Export wp-lock.json from a WordPress site (active-only).")
    parser.add_argument("--wp", required=True, help="Path to wp-cli binary")
    parser.add_argument("--allow-root", action="store_true", help="Pass --allow-root to wp-cli")
    parser.add_argument("--site-dir", required=True, help="WordPress site path (the WP root)")
    parser.add_argument("--out", required=True, help="Output lock file path (e.g. ops/wp_lock/wp-lock.json)")
    parser.add_argument("--skip-plugin", action="append", default=[], help="Git-managed/custom plugin slug to skip install/delete (repeatable)")
    parser.add_argument("--skip-theme", action="append", default=[], help="Git-managed/custom theme stylesheet to skip install/delete (repeatable)")
    parser.add_argument("--verbose", action="store_true", help="Verbose logs")
    args = parser.parse_args()

    wp_bin = _norm_path(args.wp)
    site_dir = _norm_path(args.site_dir)
    out_file = _norm_path(args.out)
    verbose = bool(args.verbose)
    allow_root = bool(args.allow_root)

    # core
    core_version = _run(_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"]), verbose=verbose).strip()

    # plugins (active-only)
    plugin_list = _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]), verbose=verbose)
    exported_plugins: List[Dict[str, Any]] = []
    for p in plugin_list:
        pid = _pick_plugin_id(p)
        status = str(p.get("status") or "").strip().lower()
        if not pid or status != "active":
            continue
        exported_plugins.append({"name": pid, "version": _version(p), "status": "active"})

    exported_plugins.sort(key=lambda x: x["name"])

    # themes：只导出 active + 自动补 parent 为 required
    theme_list = _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]), verbose=verbose)
    theme_index: Dict[str, Dict[str, Any]] = {}
    active_theme_id = ""
    for t in theme_list:
        tid = _pick_theme_id(t)
        if not tid:
            continue
        theme_index[tid] = t
        status = str(t.get("status") or "").strip().lower()
        if status == "active":
            active_theme_id = tid

    exported_themes: List[Dict[str, Any]] = []
    if active_theme_id:
        exported_themes.append({"stylesheet": active_theme_id, "version": _version(theme_index.get(active_theme_id)), "status": "active"})

        # 通过 theme get 获取 template（父主题）
        try:
            info_raw = _run(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "get", active_theme_id, "--format=json"]), verbose=verbose)
            info = json.loads(info_raw) if info_raw else {}
            parent_id = str(info.get("template") or "").strip() if isinstance(info, dict) else ""
        except Exception:
            parent_id = ""

        if parent_id and parent_id != active_theme_id:
            exported_themes.append({"stylesheet": parent_id, "version": _version(theme_index.get(parent_id)), "status": "required"})

    # skip lists
    skip_plugins = sorted({_strip_php_suffix(str(x).strip()) for x in args.skip_plugin if str(x).strip()})
    skip_themes = sorted({str(x).strip() for x in args.skip_theme if str(x).strip()})

    lock: Dict[str, Any] = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "core_version": core_version,
        "plugins": exported_plugins,
        "themes": exported_themes,
        "skip_plugins": skip_plugins,
        "skip_themes": skip_themes,
    }

    os.makedirs(os.path.dirname(out_file), exist_ok=True)
    with open(out_file, "w", encoding="utf-8") as f:
        json.dump(lock, f, ensure_ascii=False, indent=2)
        f.write("\n")

    print(f"[export_lock] site={site_dir}")
    print(f"[export_lock] out={out_file}")
    print(f"[export_lock] core={core_version}")
    print(f"[export_lock] plugins(active)={len(exported_plugins)} themes={len(exported_themes)}")


if __name__ == "__main__":
    main()
