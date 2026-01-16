#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: export_lock.py
Path: ops/wp_lock/export_lock.py
Purpose:
  从“线下（本地）WordPress站点”导出 Core/插件/主题 的版本锁定文件 wp-lock.json（免费源专用）。

Design goals:
  1) Windows: wp-cli 通常是 .bat/.cmd，使用 cmd.exe /c 执行更稳定
  2) wp-cli 不同版本/不同实现对 list 命令的 --fields 可用字段集合不完全一致
     本脚本采用“自动探测字段 + 兜底”的方式：
       - 优先尝试若干组 fields
       - 若遇到 Invalid field，则自动降级尝试下一组
       - 最终兜底：不使用 --fields，仅依赖 --format=json 输出，再做键名兼容映射
  3) lock 内部字段保持稳定：
       plugins:  {"slug": "<plugin-dir>", "version": "...", "status": "..."}
       themes:   {"stylesheet": "<theme-dir>", "version": "...", "status": "..."}
     其中 plugin-dir/theme-dir 可能来自 wp-cli 输出的 name/slug/stylesheet 等字段之一。

Usage:
  python ops\\wp_lock\\export_lock.py --site-dir "E:\\laragon\\www\\agri-ecommerce" --out "ops\\wp_lock\\wp-lock.json"
  可显式指定 wp-cli：
  python ops\\wp_lock\\export_lock.py --wp "E:\\wp-cli\\wp.bat" --site-dir "..." --out "..."
  开启调试输出（推荐你现在测一次）：
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
    """
    Detect wp-cli invalid field error.
    Example: "Error: Invalid field: slug."
    """
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
    """
    Run wp-cli and parse JSON output as a list of dict rows.
    """
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

    # Ensure row is dict-like
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

    base_args must include the subcommand and must include '--format=json' or we will add it.
    """
    # Ensure --format=json exists
    args_has_format = any(a.startswith("--format=") for a in base_args)
    safe_base = base_args[:] if args_has_format else base_args + ["--format=json"]

    # First try candidates
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
            # Only downgrade on invalid field errors, otherwise bubble up
            if _is_invalid_field_error(msg):
                if verbose:
                    print(f"[export_lock] list failed with fields={','.join(fields)} (invalid field), try next")
                continue
            raise

    # Fallback: no --fields
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


def _normalize_plugins(rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Normalize plugin rows to stable schema:
      {"slug": <plugin-dir>, "version": <ver>, "status": <status>}
    Candidate id keys: slug/name/plugin
    """
    normalized: List[Dict[str, Any]] = []
    for r in rows:
        slug = _pick_first_key(r, ["slug", "name", "plugin"])
        if not slug:
            continue
        version = str(r.get("version", "") or "").strip()
        status = str(r.get("status", "") or "").strip()
        normalized.append({"slug": slug, "version": version, "status": status})
    return normalized


def _normalize_themes(rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Normalize theme rows to stable schema:
      {"stylesheet": <theme-dir>, "version": <ver>, "status": <status>}
    Candidate id keys: stylesheet/name/theme
    """
    normalized: List[Dict[str, Any]] = []
    for r in rows:
        stylesheet = _pick_first_key(r, ["stylesheet", "name", "theme"])
        if not stylesheet:
            continue
        version = str(r.get("version", "") or "").strip()
        status = str(r.get("status", "") or "").strip()
        normalized.append({"stylesheet": stylesheet, "version": version, "status": status})
    return normalized


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--site-dir", required=True, help="本地 WP 站点根目录（含 wp-config.php）")
    parser.add_argument("--out", required=True, help="输出 lock 文件路径")
    parser.add_argument("--wp", default=_default_wp_bin(), help="wp-cli 命令（默认自动判断）")
    parser.add_argument("--skip-plugin", action="append", default=["myshop-core"])
    parser.add_argument("--skip-theme", action="append", default=["astra-child"])
    parser.add_argument("--verbose", action="store_true", help="输出探测过程（建议首次测试开启）")
    args = parser.parse_args()

    site_dir = os.path.abspath(args.site_dir)
    out_path = os.path.abspath(args.out)

    # Probe wp-cli (fail fast)
    _ = run_wp(args.wp, site_dir, ["--info"])

    # Core version
    core_version = run_wp(args.wp, site_dir, ["core", "version"]).strip()

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
    plugins = _normalize_plugins(plugins_rows)

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
    themes = _normalize_themes(themes_rows)

    # Apply skip lists (for Git-managed items)
    skip_plugins = set(args.skip_plugin or [])
    skip_themes = set(args.skip_theme or [])

    plugins = sorted(
        [p for p in plugins if p.get("slug") not in skip_plugins],
        key=lambda x: x.get("slug", ""),
    )
    themes = sorted(
        [t for t in themes if t.get("stylesheet") not in skip_themes],
        key=lambda x: x.get("stylesheet", ""),
    )

    lock: Dict[str, Any] = {
        "schema_version": 1,
        "generated_at": dt.datetime.utcnow().replace(microsecond=0).isoformat() + "Z",
        "core_version": core_version,
        "plugins": plugins,
        "themes": themes,
        "skip_plugins": sorted(list(skip_plugins)),
        "skip_themes": sorted(list(skip_themes)),
        # 记录本次探测使用的策略，便于将来升级排查（不影响 apply）
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
