#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: apply_lock.py
Path: ops/wp_lock/apply_lock.py
Purpose:
  将 wp-lock.json（由 export_lock.py 生成）应用到“线上 WordPress 站点”，实现：
  - Core 版本对齐
  - wordpress.org 免费源 插件/主题 的版本对齐
  - 可选：按 lock 中的 status 对齐启用/停用（插件）和激活主题

Design notes:
  1) 这是我们自定义的“锁定/同步”脚本，不是 wp-cli 官方命令。
     所以 --dry-run / --verbose 是否支持，取决于脚本版本本身。
  2) wp-cli 官方的 theme/plugin install 都支持 --version（从 wordpress.org 拉指定版本）。:contentReference[oaicite:0]{index=0}
  3) wp-cli 的 list 输出字段在不同版本/环境可能不同，本脚本优先使用 --format=json 并做字段自适配。

Usage examples:
  # 预演（不执行，只打印将要做的动作）
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --dry-run --verbose

  # 正式执行
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json

Important:
  - 仅适用于 wordpress.org 免费源插件/主题；自研插件/子主题应放入 skip 列表（lock 里也会记录）。
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
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
    """
    kind: 'plugin' or 'theme'
    We accept multiple possible keys to stay compatible with different exporters / wp-cli versions.
    """
    if kind == "plugin":
        v = _safe_get(item, "slug", "name")
        return str(v).strip() if v else None

    # theme
    v = _safe_get(item, "stylesheet", "slug", "name")
    return str(v).strip() if v else None


def _get_item_version(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "version")
    return str(v).strip() if v else None


def _get_item_status(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "status")
    return str(v).strip() if v else None


def _index_installed(items: List[Dict[str, Any]], kind: str) -> Dict[str, Dict[str, Any]]:
    """
    Build map: id -> item
    For plugins wp-cli json typically uses key 'name'; for themes often includes 'stylesheet'.
    """
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


def build_plan(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
) -> Tuple[List[PlanStep], List[str]]:
    """
    Return (plan_steps, notes)
    """
    notes: List[str] = []
    steps: List[PlanStep] = []

    # --- current state ---
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

    # --- plugins ---
    skip_plugins = set([str(x).strip() for x in (lock.get("skip_plugins") or []) if str(x).strip()])
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
            notes.append(f"Skip plugin (custom/whitelist): {p_id}")
            continue

        want_ver = _get_item_version(p)
        want_status = _get_item_status(p)  # 'active' / 'inactive'
        cur = installed_plugins.get(p_id)

        if cur is None:
            # install exact version if provided
            sub = ["plugin", "install", p_id, "--force"]
            if want_ver:
                sub.append(f"--version={want_ver}")
            steps.append(PlanStep(title=f"Install plugin {p_id} ({want_ver or 'latest'})", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
        else:
            cur_ver = _get_item_version(cur)
            if want_ver and cur_ver != want_ver:
                # force reinstall to exact version
                sub = ["plugin", "install", p_id, "--force", f"--version={want_ver}"]
                steps.append(PlanStep(title=f"Pin plugin {p_id} {cur_ver} -> {want_ver}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
            else:
                notes.append(f"Plugin version ok: {p_id} ({cur_ver})")

        # enforce status
        if want_status in {"active", "inactive"}:
            # re-read current status from cur if available
            cur_status = _get_item_status(cur) if cur else None
            if want_status == "active" and cur_status != "active":
                steps.append(PlanStep(title=f"Activate plugin {p_id}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", p_id])))
            elif want_status == "inactive" and cur_status == "active":
                steps.append(PlanStep(title=f"Deactivate plugin {p_id}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "deactivate", p_id])))

    # --- themes ---
    skip_themes = set([str(x).strip() for x in (lock.get("skip_themes") or []) if str(x).strip()])
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
            notes.append(f"Skip theme (custom/whitelist): {t_id}")
            continue

        want_ver = _get_item_version(t)
        want_status = _get_item_status(t)  # active/inactive
        cur = installed_themes.get(t_id)

        if cur is None:
            sub = ["theme", "install", t_id, "--force"]
            if want_ver:
                sub.append(f"--version={want_ver}")
            # if lock expects active, we can activate during install
            if want_status == "active":
                sub.append("--activate")
            steps.append(PlanStep(title=f"Install theme {t_id} ({want_ver or 'latest'})", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
        else:
            cur_ver = _get_item_version(cur)
            if want_ver and cur_ver != want_ver:
                # update to a specific version
                sub = ["theme", "update", t_id, f"--version={want_ver}"]
                steps.append(PlanStep(title=f"Pin theme {t_id} {cur_ver} -> {want_ver}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub)))
            else:
                notes.append(f"Theme version ok: {t_id} ({cur_ver})")

            # activate if needed
            cur_status = _get_item_status(cur)
            if want_status == "active" and cur_status != "active":
                steps.append(PlanStep(title=f"Activate theme {t_id}", cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", t_id])))

    return steps, notes


def main() -> None:
    parser = argparse.ArgumentParser(description="Apply wp-lock.json to a WordPress site using wp-cli.")
    parser.add_argument("--site-dir", required=True, help="目标站点根目录（含 wp-config.php）")
    # keep compatibility: --lock-file is canonical, --lock as alias
    parser.add_argument("--lock-file", "--lock", dest="lock_file", required=True, help="wp-lock.json 路径")
    parser.add_argument("--allow-root", action="store_true", help="传递 --allow-root 给 wp-cli（服务器 root 执行时需要）")
    parser.add_argument("--wp", default="wp", help="wp-cli 命令（例如 /usr/local/bin/wp）")
    parser.add_argument("--dry-run", action="store_true", help="只打印计划，不执行任何变更")
    parser.add_argument("--verbose", action="store_true", help="输出详细命令与执行信息")
    args = parser.parse_args()

    site_dir = _norm_path(args.site_dir)
    lock_file = _norm_path(args.lock_file)
    wp_bin = args.wp

    if not os.path.isdir(site_dir):
        raise SystemExit(f"site-dir not found: {site_dir}")
    if not os.path.isfile(lock_file):
        raise SystemExit(f"lock-file not found: {lock_file}")

    lock = load_lock(lock_file)

    # Build plan (also validates wp-cli works & can connect)
    try:
        plan, notes = build_plan(
            wp_bin=wp_bin,
            allow_root=args.allow_root,
            site_dir=site_dir,
            lock=lock,
            verbose=args.verbose,
        )
    except subprocess.CalledProcessError as exc:
        msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
        raise SystemExit(f"wp-cli failed:\n{msg}") from exc
    except FileNotFoundError as exc:
        raise SystemExit(f"Cannot execute wp-cli: {wp_bin}") from exc

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    if notes and args.verbose:
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

    # Execute plan
    for step in plan:
        try:
            _ = _run(step.cmd, verbose=args.verbose)
        except subprocess.CalledProcessError as exc:
            msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
            raise SystemExit(f"[apply_lock] Step failed: {step.title}\ncmd={' '.join(step.cmd)}\n{msg}") from exc

    # Final quick summary
    core_ver = _run(_build_wp_cmd(wp_bin, site_dir, args.allow_root, ["core", "version"]), verbose=args.verbose)
    print(f"[apply_lock] DONE. core_version={core_ver}")


if __name__ == "__main__":
    main()
