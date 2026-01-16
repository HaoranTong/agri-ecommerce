#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: export_lock.py
Path: ops/wp_lock/export_lock.py
Purpose:
  从“线下（本地）WordPress 站点”导出 Core / 插件 / 主题 的版本与启停状态锁定文件 wp-lock.json。

Important (与你最新冻结要求一致):
  1) 线下为真源：线上必须与线下绝对一致（版本 + 启停 + 删除未启用/多余项）。
  2) 自研项（Git 白名单发布）仍需要纳入 lock 的“启停状态对齐”：
     - 在 lock 中保留它们（包含 version/status）
     - 但标记 managed_by="git"，提示 apply_lock 不负责安装/更新/卸载，只负责启停对齐。

Design goals:
  - Windows 兼容：wp-cli 常为 .bat/.cmd，使用 cmd.exe /c 更稳定
  - 字段兼容：wp-cli list 的 --fields 在不同环境字段可能不同，本脚本自动探测 fields 组合并回退
  - lock schema_version=2

Usage (Windows / Laragon):
  python ops\\wp_lock\\export_lock.py --site-dir "E:\\laragon\\www\\agri-ecommerce" --out "ops\\wp_lock\\wp-lock.json"
  指定 wp-cli：
  python ops\\wp_lock\\export_lock.py --wp "E:\\wp-cli\\wp.bat" --site-dir "..." --out "..."
  开启探测日志（首次推荐）：
  python ops\\wp_lock\\export_lock.py --verbose --site-dir "..." --out "..."
"""

import argparse
import datetime as dt
import json
import os
import subprocess
from typing import Any, Dict, List, Optional, Sequence, Tuple


def _default_wp_bin() -> str:
    """
    Provide a robust default for wp-cli command.
    - Windows: prefer the known wp.bat path if it exists
    - Others : use 'wp'
    """
    if os.name == "nt":
        candidate = r"E:\wp-cli\wp.bat"
        if os.path.exists(candidate):
            return candidate
    return "wp"


def _build_wp_cmd(wp_bin: str, site_dir: str, args: List[str]) -> List[str]:
    """
    Build wp-cli command.
    On Windows, wrap by cmd.exe /c to reliably run .bat/.cmd.
    """
    base = [wp_bin, f"--path={site_dir}", "--skip-plugins", "--skip-themes"] + args
    if os.name == "nt":
        return [r"C:\Windows\System32\cmd.exe", "/c"] + base
    return base


def _is_invalid_field_error(output: str) -> bool:
    lowered = output.lower()
    return "invalid field" in lowered


def run_wp(wp_bin: str, site_dir: str, args: List[str]) -> str:
    cmd = _build_wp_cmd(wp_bin, site_dir, args)
    try:
        out = subprocess.check_output(cmd, stderr=subprocess.STDOUT)
        return out.decode("utf-8", errors="replace").strip()
    except subprocess.CalledProcessError as exc:
        msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
        raise RuntimeError(f"wp-cli failed: {' '.join(cmd)}\n{msg}") from exc


def run_wp_json(wp_bin: str, site_dir: str, args: List[str]) -> List[Dict[str, Any]]:
    raw = run_wp(wp_bin, site_dir, args)
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            "wp-cli returned non-JSON output when JSON was expected.\n"
            f"Command: {args}\n"
            f"Output(head 500): {raw[:500]}"
        ) from exc

    if not isinstance(data, list):
        raise RuntimeError(
            "wp-cli JSON output is not a list.\n"
            f"Command: {args}\n"
            f"Type: {type(data)}\n"
            f"Output(head 500): {raw[:500]}"
        )

    rows: List[Dict[str, Any]] = []
    for item in data:
        if isinstance(item, dict):
            rows.append(item)
    return rows


def _try_list_json_with_fields(
    wp_bin: str,
    site_dir: str,
    base_args: List[str],
    fields_candidates: Sequence[Sequence[str]],
    verbose: bool,
) -> Tuple[List[Dict[str, Any]], str]:
    """
    Try to run list command with several --fields combinations, fallback to no --fields.
    Returns (rows, used_strategy_desc).

    base_args must include the subcommand and should include '--format=json'.
    """
    args_has_format = any(a.startswith("--format=") for a in base_args)
    safe_base = base_args[:] if args_has_format else base_args + ["--format=json"]

    for fields in fields_candidates:
        fields_arg = f"--fields={','.join(fields)}"
        cmd_args = safe_base + [fields_arg]
        try:
            rows = run_wp_json(wp_bin, site_dir, cmd_args)
            desc = f"fields={','.join(fields)}"
            if verbose:
                print(f"[export_lock] list ok with {desc}")
            return rows, desc
        except RuntimeError as exc:
            msg = str(exc)
            if _is_invalid_field_error(msg):
                if verbose:
                    print(
                        f"[export_lock] list failed with fields={','.join(fields)} "
                        "(invalid field), try next"
                    )
                continue
            raise

    rows = run_wp_json(wp_bin, site_dir, safe_base)
    desc = "no-fields"
    if verbose:
        print("[export_lock] list ok with no --fields (fallback)")
    return rows, desc


def _pick_first_key(row: Dict[str, Any], keys: Sequence[str]) -> Optional[str]:
    for k in keys:
        v = row.get(k)
        if isinstance(v, str) and v.strip():
            return v.strip()
    return None


def _normalize_plugins(
    rows: List[Dict[str, Any]],
    git_plugins: Sequence[str],
) -> List[Dict[str, Any]]:
    """
    Normalize plugin rows to stable schema:
      {"slug": <plugin-dir>, "version": <ver>, "status": <status>, "managed_by": "wporg|git"}
    Candidate id keys: slug/name/plugin
    """
    git_set = {str(x).strip() for x in (git_plugins or []) if str(x).strip()}
    normalized: List[Dict[str, Any]] = []
    for r in rows:
        slug = _pick_first_key(r, ["slug", "name", "plugin"])
        if not slug:
            continue
        version = str(r.get("version", "") or "").strip()
        status = str(r.get("status", "") or "").strip()
        managed_by = "git" if slug in git_set else "wporg"
        normalized.append(
            {
                "slug": slug,
                "version": version,
                "status": status,
                "managed_by": managed_by,
            }
        )
    return normalized


def _normalize_themes(
    rows: List[Dict[str, Any]],
    git_themes: Sequence[str],
) -> List[Dict[str, Any]]:
    """
    Normalize theme rows to stable schema:
      {"stylesheet": <theme-dir>, "version": <ver>, "status": <status>, "managed_by": "wporg|git"}
    Candidate id keys: stylesheet/name/theme
    """
    git_set = {str(x).strip() for x in (git_themes or []) if str(x).strip()}
    normalized: List[Dict[str, Any]] = []
    for r in rows:
        stylesheet = _pick_first_key(r, ["stylesheet", "name", "theme"])
        if not stylesheet:
            continue
        version = str(r.get("version", "") or "").strip()
        status = str(r.get("status", "") or "").strip()
        managed_by = "git" if stylesheet in git_set else "wporg"
        normalized.append(
            {
                "stylesheet": stylesheet,
                "version": version,
                "status": status,
                "managed_by": managed_by,
            }
        )
    return normalized


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--site-dir", required=True, help="本地 WP 站点根目录（含 wp-config.php）")
    parser.add_argument("--out", required=True, help="输出 lock 文件路径")
    parser.add_argument("--wp", default=_default_wp_bin(), help="wp-cli 命令（默认自动判断）")

    # 为兼容旧参数名：仍叫 skip，但语义改为“Git 管理项清单”
    parser.add_argument(
        "--skip-plugin",
        action="append",
        default=["myshop-core"],
        help="Git 管理的插件（不走 wp-cli 安装/更新/卸载，但要写入 lock 以便启停对齐）",
    )
    parser.add_argument(
        "--skip-theme",
        action="append",
        default=["astra-child"],
        help="Git 管理的主题（不走 wp-cli 安装/更新/卸载，但要写入 lock 以便启停对齐）",
    )

    parser.add_argument("--verbose", action="store_true", help="输出探测过程（建议首次测试开启）")
    args = parser.parse_args()

    site_dir = os.path.abspath(args.site_dir)
    out_path = os.path.abspath(args.out)

    # Probe wp-cli (fail fast)
    _ = run_wp(args.wp, site_dir, ["--info"])

    # Core version
    core_version = run_wp(args.wp, site_dir, ["core", "version"]).strip()

    # Active theme info (DB options)
    active_theme = run_wp(args.wp, site_dir, ["option", "get", "stylesheet"]).strip()
    parent_theme = run_wp(args.wp, site_dir, ["option", "get", "template"]).strip()

    # Plugin list: auto-detect fields, then fallback
    plugin_fields_candidates = [
        ("name", "version", "status"),
        ("slug", "version", "status"),
        ("plugin", "version", "status"),
    ]
    plugins_rows, plugin_strategy = _try_list_json_with_fields(
        args.wp,
        site_dir,
        base_args=["plugin", "list", "--format=json"],
        fields_candidates=plugin_fields_candidates,
        verbose=args.verbose,
    )
    plugins = _normalize_plugins(plugins_rows, git_plugins=args.skip_plugin)

    # Theme list: auto-detect fields, then fallback
    theme_fields_candidates = [
        ("name", "version", "status"),
        ("stylesheet", "version", "status"),
        ("theme", "version", "status"),
    ]
    themes_rows, theme_strategy = _try_list_json_with_fields(
        args.wp,
        site_dir,
        base_args=["theme", "list", "--format=json"],
        fields_candidates=theme_fields_candidates,
        verbose=args.verbose,
    )
    themes = _normalize_themes(themes_rows, git_themes=args.skip_theme)

    # Sort for stability
    plugins = sorted(plugins, key=lambda x: x.get("slug", ""))
    themes = sorted(themes, key=lambda x: x.get("stylesheet", ""))

    git_plugins = sorted({str(x).strip() for x in (args.skip_plugin or []) if str(x).strip()})
    git_themes = sorted({str(x).strip() for x in (args.skip_theme or []) if str(x).strip()})

    lock: Dict[str, Any] = {
        "schema_version": 2,
        "generated_at": dt.datetime.utcnow().replace(microsecond=0).isoformat() + "Z",
        "core_version": core_version,
        "site_meta": {
            "active_theme": active_theme,
            "parent_theme": parent_theme,
        },
        "plugins": plugins,
        "themes": themes,
        "skip_plugins": git_plugins,
        "skip_themes": git_themes,
        "export_meta": {
            "plugin_list_strategy": plugin_strategy,
            "theme_list_strategy": theme_strategy,
        },
    }

    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(lock, f, ensure_ascii=False, indent=2)

    print(f"OK -> {out_path}")


if __name__ == "__main__":
    main()
