#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: apply_lock.py
Path: ops/wp_lock/apply_lock.py
Purpose:
  将 wp-lock.json（export_lock.py 导出）应用到“线上 WordPress 站点”，实现版本对齐。

Key decisions (你已确认的方案B + 你的新要求):
  - 默认只处理“线下处于 active 的插件/主题”（scope=active）。
    线下未启用(inactive)的一律跳过：不安装、不更新、不降级、不激活。
  - 默认不改线上启用状态（不做 activate/deactivate），只做安装/版本对齐。
    如果你未来想确保 active 的一定是 active，可加 --enforce-status。

Why:
  - 变更范围最小：只有线下正在用的组件才同步到线上。
  - 风险最低：避免把线下测试用/未启用插件带到线上，避免不必要降级。

Usage:
  # 预演：只看计划，不执行
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --dry-run --verbose

  # 分批执行（更安全）：
  # 1) 先只同步插件
  python3 ops/wp_lock/apply_lock.py ... --plugins-only --dry-run --verbose
  python3 ops/wp_lock/apply_lock.py ... --plugins-only --verbose
  # 2) 再同步主题
  python3 ops/wp_lock/apply_lock.py ... --themes-only --dry-run --verbose
  python3 ops/wp_lock/apply_lock.py ... --themes-only --verbose

Options:
  --scope active   (默认) 只处理 status=active
  --scope all              处理 active + inactive（全量对齐，风险更高）
  --enforce-status         按 lock 的 status 执行 activate/deactivate（默认不做）
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple


@dataclass
class PlanStep:
    title: str
    cmd: List[str]


def _norm_path(p: str) -> str:
    return os.path.abspath(os.path.expanduser(p))


def _build_wp_cmd(wp_bin: str, site_dir: str, allow_root: bool, subargs: List[str]) -> List[str]:
    cmd = [wp_bin]
    if allow_root:
        cmd.append("--allow-root")
    cmd.append(f"--path={site_dir}")
    cmd.extend(subargs)
    return cmd


def _run(cmd: List[str], verbose: bool) -> str:
    if verbose:
        print(f"[apply_lock] run: {' '.join(cmd)}")
    out = subprocess.check_output(cmd, stderr=subprocess.STDOUT)
    return out.decode("utf-8", errors="replace").strip()


def _run_json(cmd: List[str], verbose: bool) -> List[Dict[str, Any]]:
    raw = _run(cmd, verbose=verbose)
    if not raw:
        return []
    try:
        data = json.loads(raw)
        if isinstance(data, list):
            return data
    except json.JSONDecodeError:
        pass
    raise RuntimeError(f"wp-cli JSON output parse failed.\ncmd={' '.join(cmd)}\noutput:\n{raw}")


def _safe_get(d: Dict[str, Any], *keys: str) -> Optional[Any]:
    for k in keys:
        if k in d:
            return d.get(k)
    return None


def _get_item_id(item: Dict[str, Any], kind: str) -> Optional[str]:
    if kind == "plugin":
        v = _safe_get(item, "slug", "name")
        return str(v).strip() if v else None
    v = _safe_get(item, "stylesheet", "slug", "name")
    return str(v).strip() if v else None


def _get_item_version(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "version")
    return str(v).strip() if v else None


def _get_item_status(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "status")
    return str(v).strip() if v else None


def _index_installed(items: List[Dict[str, Any]], kind: str) -> Dict[str, Dict[str, Any]]:
    mapped: Dict[str, Dict[str, Any]] = {}
    for it in items:
        it_id = _get_item_id(it, kind=kind)
        if not it_id:
            continue
        mapped[it_id] = it
    return mapped


def load_lock(lock_file: str) -> Dict[str, Any]:
    with open(lock_file, "r", encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise ValueError("Invalid lock file: root must be an object")
    schema = data.get("schema_version")
    if schema != 1:
        raise ValueError(f"Unsupported schema_version={schema}, expected 1")
    if not data.get("core_version"):
        raise ValueError("Invalid lock file: missing core_version")
    data.setdefault("plugins", [])
    data.setdefault("themes", [])
    data.setdefault("skip_plugins", [])
    data.setdefault("skip_themes", [])
    return data


def _should_process(status: Optional[str], scope: str) -> bool:
    """
    scope:
      - active: only status==active
      - all:    active + inactive (and others)
    """
    if scope == "all":
        return True
    return status == "active"


def build_plan(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    scope: str,
    enforce_status: bool,
    plugins_only: bool,
    themes_only: bool,
) -> Tuple[List[PlanStep], List[str]]:
    notes: List[str] = []
    steps: List[PlanStep] = []

    # --- core ---
    if not plugins_only and not themes_only:
        cur_core_cmd = _build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"])
        cur_core = _run(cur_core_cmd, verbose=verbose)
        desired_core = str(lock["core_version"]).strip()

        if cur_core != desired_core:
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
        else:
            notes.append(f"Core already matches: {cur_core}")
    else:
        notes.append("Core skipped (plugins-only/themes-only mode)")

    # --- plugins ---
    if not themes_only:
        skip_plugins = set(str(x).strip() for x in (lock.get("skip_plugins") or []) if str(x).strip())
        desired_plugins = lock.get("plugins") or []

        installed_plugins = _index_installed(
            _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]), verbose=verbose),
            kind="plugin",
        )

        for p in desired_plugins:
            p_id = _get_item_id(p, kind="plugin")
            if not p_id:
                continue
            if p_id in skip_plugins:
                if verbose:
                    notes.append(f"Skip plugin (custom/whitelist): {p_id}")
                continue

            want_status = _get_item_status(p)
            if not _should_process(want_status, scope):
                if verbose:
                    notes.append(f"Skip plugin by scope={scope}: {p_id} (status={want_status})")
                continue

            want_ver = _get_item_version(p)
            cur = installed_plugins.get(p_id)

            if cur is None:
                sub = ["plugin", "install", p_id, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(PlanStep(title=f"Install plugin {p_id} ({want_ver or 'latest'})", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
            else:
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    sub = ["plugin", "install", p_id, "--force", f"--version={want_ver}"]
                    steps.append(PlanStep(title=f"Pin plugin {p_id} {cur_ver} -> {want_ver}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
                else:
                    if verbose:
                        notes.append(f"Plugin version ok: {p_id} ({cur_ver})")

            if enforce_status and want_status == "active":
                cur_status = _get_item_status(cur) if cur else None
                if cur_status != "active":
                    steps.append(PlanStep(title=f"Activate plugin {p_id}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", p_id])))

    else:
        notes.append("Plugins skipped (themes-only mode)")

    # --- themes ---
    if not plugins_only:
        skip_themes = set(str(x).strip() for x in (lock.get("skip_themes") or []) if str(x).strip())
        desired_themes = lock.get("themes") or []

        installed_themes = _index_installed(
            _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]), verbose=verbose),
            kind="theme",
        )

        for t in desired_themes:
            t_id = _get_item_id(t, kind="theme")
            if not t_id:
                continue
            if t_id in skip_themes:
                if verbose:
                    notes.append(f"Skip theme (custom/whitelist): {t_id}")
                continue

            want_status = _get_item_status(t)
            if not _should_process(want_status, scope):
                if verbose:
                    notes.append(f"Skip theme by scope={scope}: {t_id} (status={want_status})")
                continue

            want_ver = _get_item_version(t)
            cur = installed_themes.get(t_id)

            if cur is None:
                sub = ["theme", "install", t_id, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(PlanStep(title=f"Install theme {t_id} ({want_ver or 'latest'})", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
            else:
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    sub = ["theme", "update", t_id, f"--version={want_ver}"]
                    steps.append(PlanStep(title=f"Pin theme {t_id} {cur_ver} -> {want_ver}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
                else:
                    if verbose:
                        notes.append(f"Theme version ok: {t_id} ({cur_ver})")

            if enforce_status and want_status == "active":
                cur_status = _get_item_status(cur) if cur else None
                if cur_status != "active":
                    steps.append(PlanStep(title=f"Activate theme {t_id}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", t_id])))

    else:
        notes.append("Themes skipped (plugins-only mode)")

    return steps, notes


def main() -> None:
    parser = argparse.ArgumentParser(description="Apply wp-lock.json to a WordPress site using wp-cli.")
    parser.add_argument("--site-dir", required=True, help="目标站点根目录（含 wp-config.php）")
    parser.add_argument("--lock-file", "--lock", dest="lock_file", required=True, help="wp-lock.json 路径")
    parser.add_argument("--allow-root", action="store_true", help="传递 --allow-root 给 wp-cli（服务器 root 执行时需要）")
    parser.add_argument("--wp", default="wp", help="wp-cli 命令（例如 /usr/local/bin/wp）")

    parser.add_argument("--dry-run", action="store_true", help="只打印计划，不执行任何变更")
    parser.add_argument("--verbose", action="store_true", help="输出详细命令与执行信息")

    parser.add_argument("--plugins-only", action="store_true", help="只处理插件（不处理主题/核心）")
    parser.add_argument("--themes-only", action="store_true", help="只处理主题（不处理插件/核心）")

    parser.add_argument(
        "--scope",
        choices=["active", "all"],
        default="active",
        help="处理范围：active=只处理启用项（默认，最安全）；all=全量对齐（含inactive）",
    )
    parser.add_argument(
        "--enforce-status",
        action="store_true",
        help="按 lock 的 status 做 activate（scope=active 时仅确保 active）/（scope=all 时可能也会 deactivate）",
    )

    args = parser.parse_args()

    if args.plugins_only and args.themes_only:
        raise SystemExit("Cannot use --plugins-only and --themes-only together.")

    site_dir = _norm_path(args.site_dir)
    lock_file = _norm_path(args.lock_file)
    wp_bin = args.wp

    if not os.path.isdir(site_dir):
        raise SystemExit(f"site-dir not found: {site_dir}")
    if not os.path.isfile(lock_file):
        raise SystemExit(f"lock-file not found: {lock_file}")

    lock = load_lock(lock_file)

    try:
        plan, notes = build_plan(
            wp_bin=wp_bin,
            allow_root=args.allow_root,
            site_dir=site_dir,
            lock=lock,
            verbose=args.verbose,
            scope=args.scope,
            enforce_status=args.enforce_status,
            plugins_only=args.plugins_only,
            themes_only=args.themes_only,
        )
    except subprocess.CalledProcessError as exc:
        msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
        raise SystemExit(f"wp-cli failed:\n{msg}") from exc
    except FileNotFoundError:
        raise SystemExit(f"Cannot execute wp-cli: {wp_bin}") from None

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    print(f"[apply_lock] scope={args.scope} enforce_status={args.enforce_status} plugins_only={args.plugins_only} themes_only={args.themes_only}")

    if args.verbose:
        for n in notes:
            print(f"[apply_lock] note: {n}")

    if not plan:
        print("[apply_lock] No changes needed. (Already aligned)")
        return

    print(f"[apply_lock] Planned steps: {len(plan)}")
    for i, step in enumerate(plan, 1):
        print(f"  {i:02d}. {step.title}")
        if args.verbose or args.dry_run:
            print(f"      cmd: {' '.join(step.cmd)}")

    if args.dry_run:
        print("[apply_lock] DRY-RUN done. No changes were applied.")
        return

    for step in plan:
        try:
            _ = _run(step.cmd, verbose=args.verbose)
        except subprocess.CalledProcessError as exc:
            msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
            raise SystemExit(f"[apply_lock] Step failed: {step.title}\ncmd={' '.join(step.cmd)}\n{msg}") from exc

    core_ver = _run(_build_wp_cmd(wp_bin, site_dir, args.allow_root, ["core", "version"]), verbose=args.verbose)
    print(f"[apply_lock] DONE. core_version={core_ver}")


if __name__ == "__main__":
    main()
