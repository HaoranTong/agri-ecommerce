#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: apply_lock.py
Path: ops/wp_lock/apply_lock.py
Purpose:
  将 wp-lock.json（export_lock.py 导出）应用到“线上 WordPress 站点”，实现：
  - Core 版本对齐
  - 插件/主题版本对齐
  - 启停状态对齐（activate/deactivate）
  - 删除未启用或多余的插件/主题（prune）

Modes:
  1) 安全模式（默认，兼容你旧流程）：
     - scope=active：只处理 lock 中 status=active 的插件/主题（主题的 parent 也视为必须处理）
     - 默认不改启停，不删除
  2) 严格模式（满足你最新冻结要求）：
     - --strict 一键启用：全量对齐 + 启停对齐 + 删除多余/未启用
     - 线上最终只保留线下启用插件；主题只保留“active theme + parent theme（如有）”

Important:
  - skip_plugins / skip_themes（Git 管理项）：
    apply_lock 不通过 wp-cli 安装/更新/卸载这些目录，但会按 lock 做启停对齐。
    若你要“删除自研项”，应修改部署白名单（否则 rsync 下一次又会同步回来）。

Usage (server):
  # 预演（推荐）
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --dry-run --verbose

  # 严格执行（hooks 中就用这一条）
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --verbose
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Set, Tuple


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
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            f"wp-cli JSON output parse failed.\ncmd={' '.join(cmd)}\noutput(head 800):\n{raw[:800]}"
        ) from exc
    if isinstance(data, list):
        return [x for x in data if isinstance(x, dict)]
    raise RuntimeError(f"wp-cli JSON output is not a list.\ncmd={' '.join(cmd)}\noutput:\n{raw}")


def _safe_get(d: Dict[str, Any], *keys: str) -> Optional[Any]:
    for k in keys:
        if k in d:
            return d.get(k)
    return None


def _get_item_id(item: Dict[str, Any], kind: str) -> Optional[str]:
    if kind == "plugin":
        v = _safe_get(item, "slug", "name", "plugin")
        return str(v).strip() if v else None
    v = _safe_get(item, "stylesheet", "slug", "name", "theme")
    return str(v).strip() if v else None


def _get_item_version(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "version")
    return str(v).strip() if v else None


def _get_item_status(item: Dict[str, Any]) -> Optional[str]:
    v = _safe_get(item, "status")
    return str(v).strip() if v else None


def _get_item_managed_by(item: Dict[str, Any]) -> str:
    v = _safe_get(item, "managed_by")
    s = str(v).strip().lower() if v else ""
    return s if s in ("git", "wporg") else "wporg"


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
    if schema not in (1, 2):
        raise ValueError(f"Unsupported schema_version={schema}, expected 1 or 2")

    if not data.get("core_version"):
        raise ValueError("Invalid lock file: missing core_version")

    data.setdefault("plugins", [])
    data.setdefault("themes", [])
    data.setdefault("skip_plugins", [])
    data.setdefault("skip_themes", [])
    data.setdefault("site_meta", {})

    # schema v1: 补齐 managed_by
    if schema == 1:
        skip_plugins = {str(x).strip() for x in (data.get("skip_plugins") or []) if str(x).strip()}
        skip_themes = {str(x).strip() for x in (data.get("skip_themes") or []) if str(x).strip()}

        for p in data.get("plugins") or []:
            if isinstance(p, dict) and "managed_by" not in p:
                pid = _get_item_id(p, "plugin")
                p["managed_by"] = "git" if (pid and pid in skip_plugins) else "wporg"

        for t in data.get("themes") or []:
            if isinstance(t, dict) and "managed_by" not in t:
                tid = _get_item_id(t, "theme")
                t["managed_by"] = "git" if (tid and tid in skip_themes) else "wporg"

    return data


def _should_process_theme_status(status: Optional[str], scope: str) -> bool:
    """
    scope:
      - active: 处理 active + parent（parent 是子主题依赖父主题的必要状态）
      - all:    全部处理
    """
    if scope == "all":
        return True
    return status in ("active", "parent")


def _should_process_plugin_status(status: Optional[str], scope: str) -> bool:
    if scope == "all":
        return True
    return status == "active"


def _find_desired_active_theme(lock: Dict[str, Any]) -> Optional[str]:
    meta = lock.get("site_meta") or {}
    active = str(meta.get("active_theme") or "").strip()
    if active:
        return active

    # fallback: 从 themes 列表里找 status=active
    for t in lock.get("themes") or []:
        if not isinstance(t, dict):
            continue
        if _get_item_status(t) == "active":
            tid = _get_item_id(t, "theme")
            if tid:
                return tid
    return None


def _find_desired_parent_theme(lock: Dict[str, Any]) -> Optional[str]:
    meta = lock.get("site_meta") or {}
    parent = str(meta.get("parent_theme") or "").strip()
    return parent if parent else None


def _plan_core(
    steps: List[PlanStep],
    notes: List[str],
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    desired_core: str,
    verbose: bool,
) -> None:
    cur_core_cmd = _build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"])
    cur_core = _run(cur_core_cmd, verbose=verbose)

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
        # DB upgrade after core update
        steps.append(
            PlanStep(
                title="Run core database upgrade (update-db)",
                cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "update-db"]),
            )
        )
    else:
        notes.append(f"Core already matches: {cur_core}")


def _plan_plugins(
    steps: List[PlanStep],
    notes: List[str],
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    scope: str,
    enforce_status: bool,
    prune: bool,
) -> None:
    skip_plugins: Set[str] = {
        str(x).strip() for x in (lock.get("skip_plugins") or []) if str(x).strip()
    }
    desired_plugins: List[Dict[str, Any]] = [
        x for x in (lock.get("plugins") or []) if isinstance(x, dict)
    ]

    installed_plugins = _index_installed(
        _run_json(
            _build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]),
            verbose=verbose,
        ),
        kind="plugin",
    )

    desired_ids: Set[str] = set()
    desired_active: Set[str] = set()
    desired_inactive: Set[str] = set()
    desired_map: Dict[str, Dict[str, Any]] = {}

    for p in desired_plugins:
        pid = _get_item_id(p, "plugin")
        if not pid:
            continue
        desired_ids.add(pid)
        desired_map[pid] = p
        st = _get_item_status(p)
        if st == "active":
            desired_active.add(pid)
        else:
            desired_inactive.add(pid)

    # 1) 确保 active 插件：安装/锁版本（wporg），然后 activate（如需要）
    for pid in sorted(desired_active):
        p = desired_map[pid]
        managed_by = _get_item_managed_by(p)
        want_ver = _get_item_version(p)
        cur = installed_plugins.get(pid)

        if managed_by != "git":
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
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    sub = ["plugin", "install", pid, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin plugin {pid} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    if verbose:
                        notes.append(f"Plugin version ok: {pid} ({cur_ver})")
        else:
            if verbose:
                notes.append(f"Git-managed plugin (skip install/update): {pid}")

        if enforce_status:
            # activate (even for git-managed)
            if cur is None:
                # might be copied by rsync; still try activate (if not exists, wp-cli will fail)
                steps.append(
                    PlanStep(
                        title=f"Activate plugin {pid}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", pid]),
                    )
                )
            else:
                cur_status = _get_item_status(cur)
                if cur_status != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate plugin {pid}",
                            cmd=_build_wp_cmd(
                                wp_bin, site_dir, allow_root, ["plugin", "activate", pid]
                            ),
                        )
                    )

    # 2) 处理 inactive 插件（严格模式要求删除）
    for pid in sorted(desired_inactive):
        p = desired_map[pid]
        st = _get_item_status(p)
        managed_by = _get_item_managed_by(p)
        cur = installed_plugins.get(pid)

        if not _should_process_plugin_status(st, scope) and not prune:
            continue

        if prune:
            if cur is None:
                continue
            if managed_by == "git" or pid in skip_plugins:
                # Git 项：不卸载目录，但可对齐为 inactive
                if enforce_status:
                    cur_status = _get_item_status(cur)
                    if cur_status == "active":
                        steps.append(
                            PlanStep(
                                title=f"Deactivate plugin {pid} (git-managed)",
                                cmd=_build_wp_cmd(
                                    wp_bin, site_dir, allow_root, ["plugin", "deactivate", pid]
                                ),
                            )
                        )
                if verbose:
                    notes.append(
                        f"Prune requested but skip uninstall for git-managed plugin: {pid}"
                    )
            else:
                # wporg 项：卸载并删除
                steps.append(
                    PlanStep(
                        title=f"Uninstall plugin {pid} (status={st})",
                        cmd=_build_wp_cmd(
                            wp_bin,
                            site_dir,
                            allow_root,
                            ["plugin", "uninstall", pid, "--deactivate", "--yes"],
                        ),
                    )
                )
        else:
            # 非 prune，仅在 enforce_status 且 scope=all 时做 deactivate
            if enforce_status and scope == "all" and cur is not None:
                cur_status = _get_item_status(cur)
                if cur_status == "active":
                    steps.append(
                        PlanStep(
                            title=f"Deactivate plugin {pid}",
                            cmd=_build_wp_cmd(
                                wp_bin, site_dir, allow_root, ["plugin", "deactivate", pid]
                            ),
                        )
                    )

    # 3) 删除线上“多余插件”（不在 lock 内）
    if prune:
        for installed_id in sorted(installed_plugins.keys()):
            if installed_id in desired_ids:
                continue
            if installed_id in skip_plugins:
                # Git 白名单项一般应在 lock 内；即便不在，也不由 wp-cli 删除
                if verbose:
                    notes.append(f"Skip prune extra plugin (git list): {installed_id}")
                continue
            steps.append(
                PlanStep(
                    title=f"Uninstall extra plugin {installed_id} (not in lock)",
                    cmd=_build_wp_cmd(
                        wp_bin,
                        site_dir,
                        allow_root,
                        ["plugin", "uninstall", installed_id, "--deactivate", "--yes"],
                    ),
                )
            )


def _plan_themes(
    steps: List[PlanStep],
    notes: List[str],
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    scope: str,
    enforce_status: bool,
    prune: bool,
) -> None:
    skip_themes: Set[str] = {
        str(x).strip() for x in (lock.get("skip_themes") or []) if str(x).strip()
    }
    desired_themes: List[Dict[str, Any]] = [
        x for x in (lock.get("themes") or []) if isinstance(x, dict)
    ]

    installed_themes = _index_installed(
        _run_json(
            _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]),
            verbose=verbose,
        ),
        kind="theme",
    )

    desired_theme_map: Dict[str, Dict[str, Any]] = {}
    for t in desired_themes:
        tid = _get_item_id(t, "theme")
        if not tid:
            continue
        desired_theme_map[tid] = t

    active_theme = _find_desired_active_theme(lock)
    if not active_theme:
        raise RuntimeError("Cannot determine desired active theme from lock (site_meta.active_theme missing).")

    parent_theme = _find_desired_parent_theme(lock)
    keep: Set[str] = {active_theme}
    if parent_theme and parent_theme != active_theme:
        keep.add(parent_theme)

    # 1) 确保 keep 主题安装/锁版本（wporg）
    for tid in sorted(keep):
        t = desired_theme_map.get(tid)
        managed_by = _get_item_managed_by(t) if t else ("git" if tid in skip_themes else "wporg")
        want_ver = _get_item_version(t) if t else None
        cur = installed_themes.get(tid)

        if managed_by != "git":
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
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    # 用 theme install --force --version 做“锁定/降级”更可靠
                    sub = ["theme", "install", tid, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin theme {tid} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    if verbose:
                        notes.append(f"Theme version ok: {tid} ({cur_ver})")
        else:
            if verbose:
                notes.append(f"Git-managed theme (skip install/update): {tid}")

    # 2) 启用 active theme
    if enforce_status:
        steps.append(
            PlanStep(
                title=f"Activate theme {active_theme}",
                cmd=_build_wp_cmd(
                    wp_bin, site_dir, allow_root, ["theme", "activate", active_theme]
                ),
            )
        )

    # 3) prune：删除不在 keep 集合的主题（但不删除 git-managed 主题）
    if prune:
        for installed_id in sorted(installed_themes.keys()):
            if installed_id in keep:
                continue
            if installed_id in skip_themes:
                if verbose:
                    notes.append(f"Skip prune theme (git-managed): {installed_id}")
                continue
            steps.append(
                PlanStep(
                    title=f"Delete theme {installed_id} (not in keep)",
                    cmd=_build_wp_cmd(
                        wp_bin, site_dir, allow_root, ["theme", "delete", installed_id, "--yes"]
                    ),
                )
            )


def build_plan(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    scope: str,
    enforce_status: bool,
    prune: bool,
    strict: bool,
    plugins_only: bool,
    themes_only: bool,
) -> Tuple[List[PlanStep], List[str]]:
    notes: List[str] = []
    steps: List[PlanStep] = []

    # strict overrides
    eff_scope = "all" if strict else scope
    eff_enforce = True if strict else enforce_status
    eff_prune = True if strict else prune

    # core
    if not plugins_only and not themes_only:
        _plan_core(
            steps=steps,
            notes=notes,
            wp_bin=wp_bin,
            allow_root=allow_root,
            site_dir=site_dir,
            desired_core=str(lock["core_version"]).strip(),
            verbose=verbose,
        )
    else:
        notes.append("Core skipped (plugins-only/themes-only mode)")

    # plugins
    if not themes_only:
        _plan_plugins(
            steps=steps,
            notes=notes,
            wp_bin=wp_bin,
            allow_root=allow_root,
            site_dir=site_dir,
            lock=lock,
            verbose=verbose,
            scope=eff_scope,
            enforce_status=eff_enforce,
            prune=eff_prune,
        )
    else:
        notes.append("Plugins skipped (themes-only mode)")

    # themes
    if not plugins_only:
        _plan_themes(
            steps=steps,
            notes=notes,
            wp_bin=wp_bin,
            allow_root=allow_root,
            site_dir=site_dir,
            lock=lock,
            verbose=verbose,
            scope=eff_scope,
            enforce_status=eff_enforce,
            prune=eff_prune,
        )
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
        help="处理范围：active=只处理启用项（主题 parent 也会处理）；all=全量对齐",
    )
    parser.add_argument(
        "--enforce-status",
        action="store_true",
        help="按 lock 的 status 做 activate/deactivate（scope=all 时会执行 deactivate）",
    )
    parser.add_argument(
        "--prune",
        action="store_true",
        help="删除不需要的插件/主题：卸载 lock 中 inactive 的 wporg 项，删除线上不在 lock 内的多余项",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="严格模式（一键）：等价于 --scope all --enforce-status --prune",
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
            prune=args.prune,
            strict=args.strict,
            plugins_only=args.plugins_only,
            themes_only=args.themes_only,
        )
    except subprocess.CalledProcessError as exc:
        msg = exc.output.decode("utf-8", errors="replace") if exc.output else str(exc)
        raise SystemExit(f"wp-cli failed:\n{msg}") from exc
    except FileNotFoundError:
        raise SystemExit(f"Cannot execute wp-cli: {wp_bin}") from None
    except RuntimeError as exc:
        raise SystemExit(str(exc)) from exc

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    print(
        "[apply_lock] mode="
        + ("STRICT" if args.strict else "SAFE")
        + f" scope={('all' if args.strict else args.scope)} "
        + f"enforce_status={('true' if args.strict else str(args.enforce_status).lower())} "
        + f"prune={('true' if args.strict else str(args.prune).lower())} "
        + f"plugins_only={args.plugins_only} themes_only={args.themes_only}"
    )

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
            raise SystemExit(
                f"[apply_lock] Step failed: {step.title}\ncmd={' '.join(step.cmd)}\n{msg}"
            ) from exc

    core_ver = _run(
        _build_wp_cmd(wp_bin, site_dir, args.allow_root, ["core", "version"]),
        verbose=args.verbose,
    )
    active_theme = _run(
        _build_wp_cmd(wp_bin, site_dir, args.allow_root, ["option", "get", "stylesheet"]),
        verbose=args.verbose,
    )
    print(f"[apply_lock] DONE. core_version={core_ver} active_theme={active_theme}")


if __name__ == "__main__":
    main()
