#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: apply_lock.py
Path: ops/wp_lock/apply_lock.py
Purpose:
  将 wp-lock.json（export_lock.py 导出）应用到 WordPress 站点，实现：
  - WP Core 版本对齐
  - 插件/主题版本对齐
  -（可选）启用状态对齐（activate/deactivate）
  -（可选）清理多余项（uninstall/delete）

Important compatibility fix:
  部分 wp-cli 发行版本的 `wp plugin uninstall` / `wp theme delete` 不支持 `--yes` 参数；
  但这些命令又会弹确认提示，hooks 无人值守会卡死。
  本脚本采用“stdin 自动输入 y\\n”的方式实现非交互确认：
    - uninstall/delete 不再携带 --yes
    - 执行时自动喂 y\\n

Modes:
  - 默认（SAFE）：scope=active, enforce_status=false, prune=false
  - STRICT（你冻结的新要求）：scope=all, enforce_status=true, prune=true
    * 版本严格对齐（允许降级）
    * 启停状态严格对齐
    * 删除所有不需要的插件/主题（仅保留 lock 需要项 + Git-managed 例外 + 父主题依赖）

Usage:
  # 预演：只看计划，不执行
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --dry-run --verbose

  # 正式执行（STRICT）
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
    """A single plan step to run."""
    title: str
    cmd: List[str]
    stdin_text: Optional[str] = None  # for commands that require confirmation (y/n)


def _norm_path(p: str) -> str:
    return os.path.abspath(os.path.expanduser(p))


def _build_wp_cmd(wp_bin: str, site_dir: str, allow_root: bool, subargs: List[str]) -> List[str]:
    cmd = [wp_bin]
    if allow_root:
        cmd.append("--allow-root")
    cmd.append(f"--path={site_dir}")
    cmd.extend(subargs)
    return cmd


def _format_cmd(cmd: List[str]) -> str:
    return " ".join(cmd)


def _run(cmd: List[str], verbose: bool, stdin_text: Optional[str] = None) -> str:
    """
    Run a command and return combined stdout/stderr.
    If stdin_text is provided, it will be fed to the process (non-interactive confirm).
    """
    if verbose:
        print(f"[apply_lock] run: {_format_cmd(cmd)}")

    try:
        res = subprocess.run(
            cmd,
            input=stdin_text,
            text=True,
            capture_output=True,
            check=True,
        )
        out = (res.stdout or "") + (res.stderr or "")
        return out.strip()
    except subprocess.CalledProcessError as exc:
        out = (exc.stdout or "") + (exc.stderr or "")
        raise subprocess.CalledProcessError(
            exc.returncode, exc.cmd, output=out
        ) from None


def _run_json(cmd: List[str], verbose: bool) -> List[Dict[str, Any]]:
    raw = _run(cmd, verbose=verbose)
    if not raw:
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            "wp-cli JSON output parse failed.\n"
            f"cmd={_format_cmd(cmd)}\n"
            f"output(head 800):\n{raw[:800]}"
        ) from exc
    if isinstance(data, list):
        return [x for x in data if isinstance(x, dict)]
    raise RuntimeError(f"wp-cli JSON output is not a list.\ncmd={_format_cmd(cmd)}\noutput(head 800):\n{raw[:800]}")


def _safe_get(d: Dict[str, Any], *keys: str) -> Optional[Any]:
    for k in keys:
        if k in d:
            return d.get(k)
    return None


def _get_item_id(item: Dict[str, Any], kind: str) -> Optional[str]:
    """
    kind=plugin: prefer slug/name/plugin
    kind=theme : prefer stylesheet/slug/name/theme
    """
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


def _index_installed(items: List[Dict[str, Any]], kind: str) -> Dict[str, Dict[str, Any]]:
    mapped: Dict[str, Dict[str, Any]] = {}
    for it in items:
        it_id = _get_item_id(it, kind=kind)
        if it_id:
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


def _should_process_by_scope(status: Optional[str], scope: str) -> bool:
    """
    scope:
      - active: only status==active
      - all:    accept all
    """
    if scope == "all":
        return True
    return status == "active"


def _need_confirm_for_cmd(cmd: List[str]) -> bool:
    """
    Commands that may prompt confirmation and must be non-interactive.
    """
    # Example:
    # wp plugin uninstall <slug> --deactivate
    # wp theme delete <theme>
    if len(cmd) < 4:
        return False
    # find subcommand tokens: plugin uninstall / theme delete
    # cmd structure: [wp, --allow-root?, --path=..., "plugin", "uninstall", ...]
    try:
        idx = cmd.index("plugin")
        if idx + 1 < len(cmd) and cmd[idx + 1] == "uninstall":
            return True
    except ValueError:
        pass
    try:
        idx = cmd.index("theme")
        if idx + 1 < len(cmd) and cmd[idx + 1] == "delete":
            return True
    except ValueError:
        pass
    return False


def _plan_uninstall_plugin(wp_bin: str, site_dir: str, allow_root: bool, slug: str) -> PlanStep:
    cmd = _build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "uninstall", slug, "--deactivate"])
    return PlanStep(
        title=f"Uninstall plugin {slug}",
        cmd=cmd,
        stdin_text="y\n",
    )


def _plan_delete_theme(wp_bin: str, site_dir: str, allow_root: bool, theme: str) -> PlanStep:
    cmd = _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "delete", theme])
    return PlanStep(
        title=f"Delete theme {theme}",
        cmd=cmd,
        stdin_text="y\n",
    )


def build_plan(
    wp_bin: str,
    allow_root: bool,
    site_dir: str,
    lock: Dict[str, Any],
    verbose: bool,
    strict: bool,
    scope: str,
    enforce_status: bool,
    prune: bool,
    plugins_only: bool,
    themes_only: bool,
) -> Tuple[List[PlanStep], List[str], Dict[str, Any]]:
    notes: List[str] = []
    steps: List[PlanStep] = []

    # strict overrides
    mode = "STRICT" if strict else "SAFE"
    eff_scope = "all" if strict else scope
    eff_enforce = True if strict else enforce_status
    eff_prune = True if strict else prune

    meta: Dict[str, Any] = {
        "mode": mode,
        "scope": eff_scope,
        "enforce_status": eff_enforce,
        "prune": eff_prune,
    }

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
            # After core update, run DB update (safe even if no change)
            steps.append(
                PlanStep(
                    title="Run core update-db",
                    cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "update-db"]),
                )
            )
        else:
            notes.append(f"Core already matches: {cur_core}")
    else:
        notes.append("Core skipped (plugins-only/themes-only mode)")

    # --- installed lists ---
    installed_plugins = _index_installed(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]), verbose=verbose),
        kind="plugin",
    )
    installed_themes = _index_installed(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]), verbose=verbose),
        kind="theme",
    )

    skip_plugins: Set[str] = set(str(x).strip() for x in (lock.get("skip_plugins") or []) if str(x).strip())
    skip_themes: Set[str] = set(str(x).strip() for x in (lock.get("skip_themes") or []) if str(x).strip())

    # -------------------------
    # plugins plan
    # -------------------------
    if not themes_only:
        desired_plugins = lock.get("plugins") or []
        desired_plugin_ids: Set[str] = set()
        desired_active_plugin_ids: Set[str] = set()
        desired_inactive_plugin_ids: Set[str] = set()

        for p in desired_plugins:
            p_id = _get_item_id(p, kind="plugin")
            if not p_id:
                continue
            desired_plugin_ids.add(p_id)
            st = _get_item_status(p) or ""
            if st == "active":
                desired_active_plugin_ids.add(p_id)
            else:
                desired_inactive_plugin_ids.add(p_id)

        # Install / pin for desired plugins
        for p in desired_plugins:
            p_id = _get_item_id(p, kind="plugin")
            if not p_id:
                continue

            want_status = _get_item_status(p)
            want_ver = _get_item_version(p)

            if p_id in skip_plugins:
                notes.append(f"Git-managed plugin (skip install/update): {p_id}")
                # If lock actually includes it (some future change), we could enforce status.
                continue

            if not _should_process_by_scope(want_status, eff_scope):
                notes.append(f"Skip plugin by scope={eff_scope}: {p_id} (status={want_status})")
                continue

            cur = installed_plugins.get(p_id)
            if cur is None:
                sub = ["plugin", "install", p_id, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(
                    PlanStep(
                        title=f"Install plugin {p_id} ({want_ver or 'latest'})",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                    )
                )
            else:
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    sub = ["plugin", "install", p_id, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin plugin {p_id} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    notes.append(f"Plugin version ok: {p_id} ({cur_ver})")

            # enforce activate for active plugins
            if eff_enforce and want_status == "active":
                cur_status = _get_item_status(cur) if cur else None
                if cur_status != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate plugin {p_id}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", p_id]),
                        )
                    )

        # prune inactive plugins in lock (strict expects uninstall)
        if eff_prune:
            for p_id in sorted(desired_inactive_plugin_ids):
                if p_id in skip_plugins:
                    continue
                cur = installed_plugins.get(p_id)
                if cur is None:
                    continue
                cur_status = _get_item_status(cur)
                steps.append(
                    PlanStep(
                        title=f"Uninstall plugin {p_id} (status={cur_status})",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "uninstall", p_id, "--deactivate"]),
                        stdin_text="y\n",
                    )
                )

            # prune extra plugins not in lock (and not git-managed)
            for inst_id, inst_row in installed_plugins.items():
                if inst_id in skip_plugins:
                    continue
                if inst_id in desired_plugin_ids:
                    continue
                inst_status = _get_item_status(inst_row)
                steps.append(
                    PlanStep(
                        title=f"Uninstall extra plugin {inst_id} (not in lock)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "uninstall", inst_id, "--deactivate"]),
                        stdin_text="y\n",
                    )
                )
                _ = inst_status  # keep for title parity if needed later

    else:
        notes.append("Plugins skipped (themes-only mode)")

    # -------------------------
    # themes plan
    # -------------------------
    if not plugins_only:
        desired_themes = lock.get("themes") or []
        desired_theme_ids: Set[str] = set()
        desired_active_theme_ids: Set[str] = set()
        desired_parent_theme_ids: Set[str] = set()

        for t in desired_themes:
            t_id = _get_item_id(t, kind="theme")
            if not t_id:
                continue
            desired_theme_ids.add(t_id)
            st = _get_item_status(t) or ""
            if st == "active":
                desired_active_theme_ids.add(t_id)
            elif st == "parent":
                desired_parent_theme_ids.add(t_id)

        # Identify git-managed active theme in a compatible way:
        # If lock contains a "parent" theme and there is exactly one skip theme, assume that skip theme is the active child.
        git_active_theme: Optional[str] = None
        if not desired_active_theme_ids and desired_parent_theme_ids and skip_themes:
            # choose a stable one: if only one, use it; if multiple, pick the first sorted
            git_active_theme = sorted(skip_themes)[0]
            notes.append(f"Git-managed theme (skip install/update): {git_active_theme}")

        # install/pin desired themes (excluding git-managed)
        for t in desired_themes:
            t_id = _get_item_id(t, kind="theme")
            if not t_id:
                continue
            if t_id in skip_themes:
                notes.append(f"Git-managed theme (skip install/update): {t_id}")
                continue

            want_status = _get_item_status(t)
            want_ver = _get_item_version(t)

            if not _should_process_by_scope(want_status, eff_scope):
                notes.append(f"Skip theme by scope={eff_scope}: {t_id} (status={want_status})")
                continue

            cur = installed_themes.get(t_id)
            if cur is None:
                sub = ["theme", "install", t_id, "--force"]
                if want_ver:
                    sub.append(f"--version={want_ver}")
                steps.append(
                    PlanStep(
                        title=f"Install theme {t_id} ({want_ver or 'latest'})",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                    )
                )
            else:
                cur_ver = _get_item_version(cur)
                if want_ver and cur_ver != want_ver:
                    # Use theme install --force --version to allow downgrade (more compatible)
                    sub = ["theme", "install", t_id, "--force", f"--version={want_ver}"]
                    steps.append(
                        PlanStep(
                            title=f"Pin theme {t_id} {cur_ver} -> {want_ver}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, sub),
                        )
                    )
                else:
                    notes.append(f"Theme version ok: {t_id} ({cur_ver})")

        # enforce active theme
        if eff_enforce:
            # Activate lock-declared active themes
            for t_id in sorted(desired_active_theme_ids):
                if t_id in skip_themes:
                    notes.append(f"Git-managed theme (skip install/update): {t_id}")
                cur = installed_themes.get(t_id)
                cur_status = _get_item_status(cur) if cur else None
                if cur_status != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate theme {t_id}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", t_id]),
                        )
                    )

            # Activate inferred git-managed active theme (e.g., astra-child)
            if git_active_theme:
                cur = installed_themes.get(git_active_theme)
                cur_status = _get_item_status(cur) if cur else None
                # Only activate if it exists and is not already active
                if cur is not None and cur_status != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate theme {git_active_theme}",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", git_active_theme]),
                        )
                    )

        # prune themes not in keep
        if eff_prune:
            # keep set:
            # - all active themes in lock
            # - all parent themes in lock (dependency)
            # - all git-managed themes (never delete)
            keep: Set[str] = set()
            keep |= desired_active_theme_ids
            keep |= desired_parent_theme_ids
            keep |= skip_themes

            # also keep any theme explicitly listed in lock with status "active"/"parent"
            # (already covered by the two sets)
            # delete installed themes not in keep
            for inst_id, inst_row in installed_themes.items():
                if inst_id in keep:
                    continue
                # never try delete a theme that wp-cli reports active
                inst_status = _get_item_status(inst_row)
                if inst_status == "active":
                    # Should not happen if enforce_status is running correctly; keep safe.
                    notes.append(f"Skip deleting active theme (safety): {inst_id}")
                    continue
                steps.append(
                    PlanStep(
                        title=f"Delete theme {inst_id} (not in keep)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "delete", inst_id]),
                        stdin_text="y\n",
                    )
                )

    else:
        notes.append("Themes skipped (plugins-only mode)")

    # Ensure confirmation for specific commands (extra safety):
    for s in steps:
        if s.stdin_text is None and _need_confirm_for_cmd(s.cmd):
            s.stdin_text = "y\n"

    return steps, notes, meta


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

    parser.add_argument("--strict", action="store_true", help="严格模式：等价于 --scope all + --enforce-status + --prune")

    parser.add_argument(
        "--scope",
        choices=["active", "all"],
        default="active",
        help="处理范围：active=只处理启用项（默认，最安全）；all=全量对齐（含inactive）",
    )
    parser.add_argument(
        "--enforce-status",
        action="store_true",
        help="按 lock 的 status 做 activate/deactivate（strict 模式会自动开启）",
    )
    parser.add_argument(
        "--prune",
        action="store_true",
        help="删除多余插件/主题（strict 模式会自动开启）",
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
        plan, notes, meta = build_plan(
            wp_bin=wp_bin,
            allow_root=args.allow_root,
            site_dir=site_dir,
            lock=lock,
            verbose=args.verbose,
            strict=args.strict,
            scope=args.scope,
            enforce_status=args.enforce_status,
            prune=args.prune,
            plugins_only=args.plugins_only,
            themes_only=args.themes_only,
        )
    except subprocess.CalledProcessError as exc:
        msg = exc.output if isinstance(exc.output, str) else str(exc.output)
        raise SystemExit(f"wp-cli failed:\n{msg}") from None
    except FileNotFoundError:
        raise SystemExit(f"Cannot execute wp-cli: {wp_bin}") from None

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    print(
        f"[apply_lock] mode={meta['mode']} scope={meta['scope']} "
        f"enforce_status={str(meta['enforce_status']).lower()} prune={str(meta['prune']).lower()} "
        f"plugins_only={args.plugins_only} themes_only={args.themes_only}"
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
            print(f"      cmd: {_format_cmd(step.cmd)}")

    if args.dry_run:
        print("[apply_lock] DRY-RUN done. No changes were applied.")
        return

    for step in plan:
        try:
            _run(step.cmd, verbose=args.verbose, stdin_text=step.stdin_text)
        except subprocess.CalledProcessError as exc:
            msg = exc.output if isinstance(exc.output, str) else str(exc.output)
            raise SystemExit(
                f"[apply_lock] Step failed: {step.title}\n"
                f"cmd={_format_cmd(step.cmd)}\n"
                f"{msg}"
            ) from None

    core_ver = _run(_build_wp_cmd(wp_bin, site_dir, args.allow_root, ["core", "version"]), verbose=args.verbose)
    print(f"[apply_lock] DONE. core_version={core_ver}")


if __name__ == "__main__":
    main()
