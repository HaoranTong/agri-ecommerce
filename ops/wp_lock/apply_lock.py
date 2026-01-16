#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ops/wp_lock/apply_lock.py

功能说明
--------
将 `wp-lock.json`（由 export_lock.py 从线下站点导出）应用到目标 WordPress 站点，实现：

- WP Core 版本对齐（可选：在 --plugins-only/--themes-only 模式下自动跳过）
- 插件/主题版本对齐（以 lock 为准，允许升级/降级）
- （可选）插件/主题启用状态对齐（activate/deactivate）
- （可选）严格清理（prune）：删除线上多余/未启用组件，使线上与线下口径一致

与你当前主线的关键点
----------------------
1) 线下（Laragon 本地站点）为真源，lock 以线下为准。
2) Git 白名单管理的组件（例如：myshop-core / astra-child）不通过 wp-cli 安装/更新/删除，
   但可以（可选）做启用状态对齐（activate/deactivate / theme activate）。
3) 兼容不同 wp-cli 对确认参数的差异：
   - 本脚本**不使用** `--yes`
   - 对可能需要确认的卸载/删除操作，脚本会自动向 STDIN 写入 `y\n`，确保在 hooks/CI 中非交互执行不挂起。
4) lock 的 schema_version 兼容：支持 1 和 2（自动兼容字段名差异）。

用法示例
--------
# 严格模式（推荐你当前阶段）：版本对齐 + 状态对齐 + 删除多余/未启用
python3 ops/wp_lock/apply_lock.py \
  --wp /usr/local/bin/wp --allow-root \
  --site-dir /www/wwwroot/staging.fanbaoer.com \
  --lock-file ops/wp_lock/wp-lock.json \
  --strict --verbose

# 仅演练（不落地）
python3 ops/wp_lock/apply_lock.py ... --strict --dry-run --verbose

"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Set, Tuple


@dataclass
class PlanStep:
    """A planned wp-cli action."""

    title: str
    cmd: List[str]
    needs_confirm: bool = False  # if True, send "y\n" to stdin when executing


def _eprint(msg: str) -> None:
    print(msg, file=sys.stderr)


def _norm_path(p: str) -> str:
    return os.path.abspath(os.path.expanduser(p))


def _build_wp_cmd(wp_bin: str, site_dir: str, allow_root: bool, subargs: List[str]) -> List[str]:
    cmd = [wp_bin]
    if allow_root:
        cmd.append("--allow-root")
    cmd.append(f"--path={site_dir}")
    cmd.extend(subargs)
    return cmd


def _run(cmd: List[str], verbose: bool, input_text: Optional[str] = None) -> str:
    if verbose:
        print(f"[apply_lock] run: {' '.join(cmd)}")

    proc = subprocess.run(
        cmd,
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    out = (proc.stdout or "").strip()
    if proc.returncode != 0:
        raise RuntimeError(out or f"Command failed: {' '.join(cmd)}")
    return out


def _run_json(cmd: List[str], verbose: bool) -> List[Dict[str, Any]]:
    raw = _run(cmd, verbose=verbose)
    if not raw:
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            "wp-cli returned non-JSON output when JSON was expected.\n"
            f"cmd={' '.join(cmd)}\n"
            f"output(head 500): {raw[:500]}"
        ) from exc

    if not isinstance(data, list):
        raise RuntimeError(
            "wp-cli JSON output is not a list.\n"
            f"cmd={' '.join(cmd)}\n"
            f"type={type(data)}\n"
            f"output(head 500): {raw[:500]}"
        )

    rows: List[Dict[str, Any]] = []
    for item in data:
        if isinstance(item, dict):
            rows.append(item)
    return rows


def _pick_first(d: Dict[str, Any], keys: List[str]) -> Optional[Any]:
    for k in keys:
        if k in d and d[k] is not None:
            return d[k]
    return None


def _norm_status(value: Any) -> str:
    """Normalize status to one of: active | inactive | parent | (empty)."""
    if value is None:
        return ""

    # bool-like
    if isinstance(value, bool):
        return "active" if value else "inactive"

    s = str(value).strip().lower()
    if not s:
        return ""

    if s in {"active", "enabled", "on", "true", "1"}:
        return "active"
    if s in {"inactive", "disabled", "off", "false", "0"}:
        return "inactive"
    if s in {"parent", "required"}:
        return "parent"

    # wp-cli 常见：active / inactive
    return s


def _get_item_id(item: Dict[str, Any], kind: str) -> Optional[str]:
    if kind == "plugin":
        v = _pick_first(item, ["slug", "name", "plugin"])
        return str(v).strip() if isinstance(v, str) and v.strip() else (str(v).strip() if v else None)

    # theme
    v = _pick_first(item, ["stylesheet", "slug", "name", "theme"])
    return str(v).strip() if isinstance(v, str) and v.strip() else (str(v).strip() if v else None)


def _get_item_version(item: Dict[str, Any]) -> str:
    v = _pick_first(item, ["version"])
    return str(v).strip() if v is not None else ""


def _get_item_status(item: Dict[str, Any]) -> str:
    # 支持 status/active/is_active
    raw = _pick_first(item, ["status", "active", "is_active"])
    return _norm_status(raw)


def _index_by_id(items: List[Dict[str, Any]], kind: str) -> Dict[str, Dict[str, Any]]:
    out: Dict[str, Dict[str, Any]] = {}
    for it in items:
        it_id = _get_item_id(it, kind)
        if it_id:
            out[it_id] = it
    return out


def load_lock(lock_file: str) -> Dict[str, Any]:
    with open(lock_file, "r", encoding="utf-8") as f:
        raw = json.load(f)

    if not isinstance(raw, dict):
        raise ValueError("Invalid lock file: root must be an object")

    schema = raw.get("schema_version", 1)
    try:
        schema_int = int(schema)
    except Exception:
        schema_int = 1

    if schema_int not in (1, 2):
        raise ValueError(f"Unsupported schema_version={schema}, expected 1 or 2")

    # core version key compatibility
    core_version = raw.get("core_version") or raw.get("wp_core") or raw.get("core") or raw.get("wordpress")
    if not core_version:
        raise ValueError("Invalid lock file: missing core_version")

    plugins = raw.get("plugins") or raw.get("plugin") or []
    themes = raw.get("themes") or raw.get("theme") or []

    # skip list compatibility
    skip_plugins = raw.get("skip_plugins")
    skip_themes = raw.get("skip_themes")
    if skip_plugins is None or skip_themes is None:
        skip_block = raw.get("skip")
        if isinstance(skip_block, dict):
            if skip_plugins is None:
                skip_plugins = skip_block.get("plugins") or skip_block.get("plugin")
            if skip_themes is None:
                skip_themes = skip_block.get("themes") or skip_block.get("theme")

    skip_plugins = skip_plugins or []
    skip_themes = skip_themes or []

    # normalize plugin/theme arrays
    norm_plugins: List[Dict[str, Any]] = []
    if isinstance(plugins, list):
        for p in plugins:
            if not isinstance(p, dict):
                continue
            pid = _get_item_id(p, "plugin")
            if not pid:
                continue
            norm_plugins.append(
                {
                    "slug": pid,
                    "version": _get_item_version(p),
                    "status": _get_item_status(p),
                }
            )

    norm_themes: List[Dict[str, Any]] = []
    if isinstance(themes, list):
        for t in themes:
            if not isinstance(t, dict):
                continue
            tid = _get_item_id(t, "theme")
            if not tid:
                continue
            norm_themes.append(
                {
                    "stylesheet": tid,
                    "version": _get_item_version(t),
                    "status": _get_item_status(t),
                }
            )

    return {
        "schema_version": schema_int,
        "core_version": str(core_version).strip(),
        "plugins": norm_plugins,
        "themes": norm_themes,
        "skip_plugins": [str(x).strip() for x in (skip_plugins or []) if str(x).strip()],
        "skip_themes": [str(x).strip() for x in (skip_themes or []) if str(x).strip()],
    }


def _should_process(status: str, scope: str) -> bool:
    if scope == "all":
        return True
    return status == "active"


def _get_active_theme_from_lock(lock: Dict[str, Any]) -> Optional[str]:
    for t in lock.get("themes", []):
        if _get_item_status(t) == "active":
            return _get_item_id(t, "theme")
    return None


def _try_get_parent_theme(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    active_theme: str,
    verbose: bool,
) -> Optional[str]:
    """Try to detect parent theme of an active theme via wp-cli.

    If wp-cli doesn't support the used fields/format, return None.
    """
    # Try JSON first
    cmd = _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "get", active_theme, "--format=json"])
    try:
        raw = _run(cmd, verbose=verbose)
        data = json.loads(raw)
        if isinstance(data, dict):
            parent = data.get("template") or data.get("parent") or data.get("parent_theme")
            if isinstance(parent, str) and parent.strip() and parent.strip() != active_theme:
                return parent.strip()
    except Exception:
        pass

    # Fallback: try field=template (plain)
    cmd2 = _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "get", active_theme, "--field=template"])
    try:
        out = _run(cmd2, verbose=verbose)
        parent2 = out.strip()
        if parent2 and parent2 != active_theme and parent2.lower() != "null":
            return parent2
    except Exception:
        return None

    return None


def build_plan(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    scope: str,
    enforce_status: bool,
    prune: bool,
    plugins_only: bool,
    themes_only: bool,
) -> Tuple[List[PlanStep], List[str]]:
    notes: List[str] = []
    steps: List[PlanStep] = []

    # --- core ---
    if not plugins_only and not themes_only:
        cur_core = _run(_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"]), verbose=verbose)
        desired_core = str(lock.get("core_version", "")).strip()
        if desired_core and cur_core != desired_core:
            steps.append(
                PlanStep(
                    title=f"Update core {cur_core} -> {desired_core}",
                    cmd=_build_wp_cmd(
                        wp_bin,
                        site_dir,
                        allow_root,
                        ["core", "update", f"--version={desired_core}", "--force"],
                    ),
                )
            )
            # 通常 core 更新后需要 update-db（即便无变化也安全）
            steps.append(
                PlanStep(
                    title="Update core database (wp core update-db)",
                    cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "update-db"]),
                )
            )
        else:
            notes.append(f"Core already matches: {cur_core}")
    else:
        notes.append("Core skipped (plugins-only/themes-only mode)")

    # --- read installed state ---
    installed_plugins = _index_by_id(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]), verbose=verbose),
        "plugin",
    )
    installed_themes = _index_by_id(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]), verbose=verbose),
        "theme",
    )

    skip_plugins: Set[str] = set(lock.get("skip_plugins") or [])
    skip_themes: Set[str] = set(lock.get("skip_themes") or [])

    desired_plugins = lock.get("plugins") or []
    desired_themes = lock.get("themes") or []

    desired_plugin_ids: Set[str] = set()
    desired_theme_ids: Set[str] = set()

    # --- plugins: install/pin + status enforcement ---
    if not themes_only:
        for p in desired_plugins:
            pid = _get_item_id(p, "plugin")
            if not pid:
                continue
            desired_plugin_ids.add(pid)

            want_ver = _get_item_version(p)
            want_status = _get_item_status(p)

            if not _should_process(want_status, scope):
                # scope=active 时跳过 inactive（但 prune 可能后续处理）
                if verbose:
                    notes.append(f"Skip plugin by scope={scope}: {pid} (status={want_status})")
                continue

            cur = installed_plugins.get(pid)
            cur_ver = _get_item_version(cur) if cur else ""
            cur_status = _get_item_status(cur) if cur else ""

            # Git-managed: skip install/update/delete, but allow status enforcement
            if pid in skip_plugins:
                notes.append(f"Git-managed plugin (skip install/update/delete): {pid}")
                if enforce_status:
                    if want_status == "active" and cur_status != "active":
                        steps.append(
                            PlanStep(
                                title=f"Activate plugin {pid}",
                                cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", pid]),
                            )
                        )
                    if want_status == "inactive" and cur_status == "active":
                        steps.append(
                            PlanStep(
                                title=f"Deactivate plugin {pid}",
                                cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "deactivate", pid]),
                            )
                        )
                continue

            # Not installed
            if cur is None:
                sub = ["plugin", "install", pid, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(
                    PlanStep(
                        title=f"Install plugin {pid} ({want_ver or 'latest'})",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                    )
                )
            else:
                if want_ver and cur_ver and want_ver != cur_ver:
                    sub = ["plugin", "install", pid, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin plugin {pid} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    if verbose:
                        notes.append(f"Plugin version ok: {pid} ({cur_ver or want_ver or 'unknown'})")

            if enforce_status:
                if want_status == "active" and cur_status != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate plugin {pid}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", pid]),
                        )
                    )
                if want_status == "inactive" and cur_status == "active":
                    steps.append(
                        PlanStep(
                            title=f"Deactivate plugin {pid}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "deactivate", pid]),
                        )
                    )
    else:
        notes.append("Plugins skipped (themes-only mode)")

    # --- themes: install/pin + status enforcement ---
    if not plugins_only:
        for t in desired_themes:
            tid = _get_item_id(t, "theme")
            if not tid:
                continue
            desired_theme_ids.add(tid)

            want_ver = _get_item_version(t)
            want_status = _get_item_status(t)

            if not _should_process(want_status, scope):
                if verbose:
                    notes.append(f"Skip theme by scope={scope}: {tid} (status={want_status})")
                continue

            cur = installed_themes.get(tid)
            cur_ver = _get_item_version(cur) if cur else ""

            if tid in skip_themes:
                notes.append(f"Git-managed theme (skip install/update/delete): {tid}")
                # theme 状态对齐：仅对 active 做 activate
                if enforce_status and want_status == "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate theme {tid}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", tid]),
                        )
                    )
                continue

            if cur is None:
                sub = ["theme", "install", tid, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(
                    PlanStep(
                        title=f"Install theme {tid} ({want_ver or 'latest'})",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                    )
                )
            else:
                if want_ver and cur_ver and want_ver != cur_ver:
                    sub = ["theme", "install", tid, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin theme {tid} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    if verbose:
                        notes.append(f"Theme version ok: {tid} ({cur_ver or want_ver or 'unknown'})")

            if enforce_status and want_status == "active":
                steps.append(
                    PlanStep(
                        title=f"Activate theme {tid}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", tid]),
                    )
                )
    else:
        notes.append("Themes skipped (plugins-only mode)")

    # --- prune: plugins ---
    if prune and not themes_only:
        # 1) uninstall plugins marked inactive in lock (except skip)
        for p in desired_plugins:
            pid = _get_item_id(p, "plugin")
            if not pid or pid in skip_plugins:
                continue
            want_status = _get_item_status(p)
            if want_status == "inactive" and pid in installed_plugins:
                steps.append(
                    PlanStep(
                        title=f"Uninstall plugin {pid} (status=inactive)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "uninstall", pid, "--deactivate"]),
                        needs_confirm=True,
                    )
                )

        # 2) uninstall extra plugins not in lock (except skip)
        for installed_id, installed_item in installed_plugins.items():
            if installed_id in skip_plugins:
                continue
            if installed_id not in desired_plugin_ids:
                steps.append(
                    PlanStep(
                        title=f"Uninstall extra plugin {installed_id} (not in lock)",
                        cmd=_build_wp_cmd(
                            wp_bin,
                            site_dir,
                            allow_root,
                            ["plugin", "uninstall", installed_id, "--deactivate"],
                        ),
                        needs_confirm=True,
                    )
                )

    # --- prune: themes ---
    if prune and not plugins_only:
        # keep set:
        # - active theme(s) from lock
        # - parent theme of active theme (auto-detect best-effort)
        # - all skip themes (git-managed)
        keep: Set[str] = set(skip_themes)

        active_theme = _get_active_theme_from_lock(lock)
        if active_theme:
            keep.add(active_theme)
            parent = _try_get_parent_theme(wp_bin, allow_root, site_dir, active_theme, verbose=verbose)
            if parent:
                keep.add(parent)

        # If we still don't know parent, fall back to keeping themes present in lock
        # (safe: prevents deleting required parent when wp-cli cannot report it)
        if active_theme and len(keep) <= len(skip_themes) + 1:
            for t in desired_themes:
                tid = _get_item_id(t, "theme")
                if tid:
                    keep.add(tid)

        # Delete installed themes not in keep
        for installed_id in list(installed_themes.keys()):
            if installed_id in keep:
                continue
            steps.append(
                PlanStep(
                    title=f"Delete theme {installed_id} (not in keep)",
                    cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "delete", installed_id]),
                    needs_confirm=True,
                )
            )

    return steps, notes


def execute_plan(steps: List[PlanStep], verbose: bool, dry_run: bool) -> None:
    if dry_run:
        print("[apply_lock] DRY-RUN done. No changes were applied.")
        return

    for step in steps:
        try:
            _run(step.cmd, verbose=verbose, input_text=("y\n" if step.needs_confirm else None))
        except Exception as exc:
            print(f"[apply_lock] Step failed: {step.title}")
            print(f"cmd={' '.join(step.cmd)}")
            msg = str(exc)
            if msg:
                print(msg)
            raise SystemExit(2) from exc


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--wp", default="wp", help="wp-cli binary path")
    parser.add_argument("--allow-root", action="store_true", help="pass --allow-root to wp-cli")
    parser.add_argument("--site-dir", required=True, help="WordPress site directory (contains wp-config.php)")
    parser.add_argument("--lock-file", required=True, help="Path to wp-lock.json")

    parser.add_argument("--dry-run", action="store_true", help="Only print planned steps, do not apply")
    parser.add_argument("--verbose", action="store_true", help="Verbose output")

    parser.add_argument(
        "--plugins-only",
        action="store_true",
        help="Only process plugins (skip core + themes)",
    )
    parser.add_argument(
        "--themes-only",
        action="store_true",
        help="Only process themes (skip core + plugins)",
    )

    parser.add_argument(
        "--scope",
        choices=["active", "all"],
        default="active",
        help="active: only lock items with status=active; all: process active+inactive",
    )
    parser.add_argument(
        "--enforce-status",
        action="store_true",
        help="Enforce activation state according to lock (activate/deactivate)",
    )
    parser.add_argument(
        "--prune",
        action="store_true",
        help="Remove inactive/extra plugins/themes (make site match lock)",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Shortcut for: --scope all --enforce-status --prune",
    )

    args = parser.parse_args()

    wp_bin = args.wp
    site_dir = _norm_path(args.site_dir)
    lock_file = _norm_path(args.lock_file)

    if args.plugins_only and args.themes_only:
        raise SystemExit("--plugins-only and --themes-only cannot be used together")

    lock = load_lock(lock_file)

    # strict overrides
    scope = args.scope
    enforce_status = args.enforce_status
    prune = args.prune
    if args.strict:
        scope = "all"
        enforce_status = True
        prune = True

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    print(
        "[apply_lock] mode="
        + ("STRICT" if args.strict else "NORMAL")
        + f" scope={scope} enforce_status={str(enforce_status).lower()} prune={str(prune).lower()}"
        + f" plugins_only={args.plugins_only} themes_only={args.themes_only}"
    )

    # Fail fast: can we query site?
    _ = _run(_build_wp_cmd(wp_bin, site_dir, args.allow_root, ["core", "version"]), verbose=args.verbose)

    steps, notes = build_plan(
        wp_bin=wp_bin,
        allow_root=args.allow_root,
        site_dir=site_dir,
        lock=lock,
        verbose=args.verbose,
        scope=scope,
        enforce_status=enforce_status,
        prune=prune,
        plugins_only=args.plugins_only,
        themes_only=args.themes_only,
    )

    for n in notes:
        print(f"[apply_lock] note: {n}")

    print(f"[apply_lock] Planned steps: {len(steps)}")
    for i, st in enumerate(steps, start=1):
        print(f"  {i:02d}. {st.title}")
        print(f"      cmd: {' '.join(st.cmd)}")

    execute_plan(steps, verbose=args.verbose, dry_run=args.dry_run)


if __name__ == "__main__":
    main()
