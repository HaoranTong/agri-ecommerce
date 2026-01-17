#!/usr/bin/env python3
"""ops/wp_lock/apply_lock.py

WordPress 版本锁定应用工具（wp-lock.json -> 站点）

功能概述
- 读取 wp-lock.json（支持 schema_version 1/2）
- 使用 wp-cli 对齐：WordPress Core / 插件 / 主题
- 支持严格模式（--strict）：
  - 只保留 lock 里标记为 active 的插件；其余插件全部卸载（删除文件）
  - 主题只保留 active 主题 + 其父主题（child theme 的 template）+ skip 白名单
  - git/自研白名单项（skip_*）不做安装/删除，但可对齐启停与激活主题

使用示例（staging）
  # 演练（不落盘）
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --dry-run --verbose

  # 正式执行
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
import re
import subprocess
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Set, Tuple


@dataclass
class PlanStep:
    title: str
    cmd: List[str]


def _norm_path(p: str) -> str:
    return os.path.abspath(os.path.expanduser(p.strip()))


def _run(cmd: List[str], verbose: bool = False) -> str:
    if verbose:
        print(f"[apply_lock] run: {' '.join(cmd)}")
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "").strip()
        raise RuntimeError(err or f"Command failed: {' '.join(cmd)}")
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


def _load_lock(lock_file: str) -> Dict[str, Any]:
    with open(lock_file, "r", encoding="utf-8") as f:
        lock = json.load(f)
    if not isinstance(lock, dict):
        raise ValueError("Lock file must be a JSON object")

    schema = lock.get("schema_version", 1)
    if schema not in (1, 2):
        # 兼容：不强卡死，尽量继续
        pass
    return lock


def _normalize_lock(lock: Dict[str, Any]) -> Tuple[str, List[Dict[str, Any]], List[Dict[str, Any]], Set[str], Set[str]]:
    core_version = str(lock.get("core_version") or "").strip()

    plugins = lock.get("plugins")
    themes = lock.get("themes")
    if not isinstance(plugins, list):
        plugins = []
    if not isinstance(themes, list):
        themes = []

    skip_plugins = lock.get("skip_plugins") or []
    skip_themes = lock.get("skip_themes") or []
    if not isinstance(skip_plugins, list):
        skip_plugins = []
    if not isinstance(skip_themes, list):
        skip_themes = []

    sp = {_strip_php_suffix(str(x).strip()) for x in skip_plugins if str(x).strip()}
    st = {str(x).strip() for x in skip_themes if str(x).strip()}

    return core_version, plugins, themes, sp, st


def _index_installed(items: List[Dict[str, Any]], pick_id_fn) -> Dict[str, Dict[str, Any]]:
    idx: Dict[str, Dict[str, Any]] = {}
    for it in items:
        _id = pick_id_fn(it)
        if not _id:
            continue
        idx[_id] = it
    return idx


def _status(it: Optional[Dict[str, Any]]) -> str:
    if not it:
        return ""
    return str(it.get("status") or "").strip().lower()


def _version(it: Optional[Dict[str, Any]]) -> str:
    if not it:
        return ""
    v = it.get("version")
    return str(v).strip() if v is not None else ""


def _lock_plugin_id(p: Dict[str, Any]) -> str:
    for k in ("name", "slug"):
        v = p.get(k)
        if isinstance(v, str) and v.strip():
            return _strip_php_suffix(v.strip())
    return ""


def _lock_theme_id(t: Dict[str, Any]) -> str:
    for k in ("stylesheet", "name"):
        v = t.get(k)
        if isinstance(v, str) and v.strip():
            return v.strip()
    return ""


def _lock_item_status(x: Dict[str, Any]) -> str:
    return str(x.get("status") or "").strip().lower()


def _exec_plan(steps: List[PlanStep], dry_run: bool, verbose: bool) -> None:
    if not steps:
        print("[apply_lock] Planned steps: 0")
        return

    print(f"[apply_lock] Planned steps: {len(steps)}")
    for i, s in enumerate(steps, 1):
        print(f"  {i:02d}. {s.title}")
        print(f"      cmd: {' '.join(s.cmd)}")

    if dry_run:
        print("[apply_lock] DRY-RUN done. No changes were applied.")
        return

    for s in steps:
        try:
            _run(s.cmd, verbose=verbose)
        except Exception as e:
            raise RuntimeError(f"Step failed: {s.title}\ncmd={' '.join(s.cmd)}\n{e}") from e


def _maybe_get_parent_theme(wp_bin: str, site_dir: str, allow_root: bool, theme_id: str, verbose: bool) -> str:
    try:
        raw = _run(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "get", theme_id, "--format=json"]), verbose=verbose)
        info = json.loads(raw) if raw else {}
        if isinstance(info, dict):
            parent = str(info.get("template") or "").strip()
            return parent
    except Exception:
        return ""
    return ""


def build_plan(
    wp_bin: str,
    site_dir: str,
    lock_file: str,
    allow_root: bool,
    strict: bool,
    plugins_only: bool,
    themes_only: bool,
    dry_run: bool,
    verbose: bool,
) -> Tuple[List[PlanStep], List[str]]:
    lock_raw = _load_lock(lock_file)
    core_version, lock_plugins, lock_themes, skip_plugins, skip_themes = _normalize_lock(lock_raw)

    notes: List[str] = []
    steps: List[PlanStep] = []

    # --- core ---
    if not plugins_only and not themes_only:
        want_core = core_version or ""
        if want_core:
            cur_core = _run(_build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"]), verbose=verbose)
            if cur_core.strip() != want_core:
                steps.append(
                    PlanStep(
                        title=f"Pin core {cur_core.strip()} -> {want_core}",
                        cmd=_build_wp_cmd(
                            wp_bin,
                            site_dir,
                            allow_root,
                            ["core", "update", f"--version={want_core}", "--force"],
                        ),
                    )
                )
            else:
                notes.append(f"Core already matches: {cur_core.strip()}")

    # --- collect current state ---
    installed_plugins = _index_installed(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]), verbose=verbose),
        _pick_plugin_id,
    )
    installed_themes = _index_installed(
        _run_json(_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]), verbose=verbose),
        _pick_theme_id,
    )

    # --- desired sets (active-only in strict) ---
    desired_active_plugins: Dict[str, Dict[str, Any]] = {}
    for p in lock_plugins:
        if not isinstance(p, dict):
            continue
        pid = _lock_plugin_id(p)
        if not pid:
            continue
        st = _lock_item_status(p)
        if strict:
            if st == "active":
                desired_active_plugins[pid] = p
        else:
            if st == "active":
                desired_active_plugins[pid] = p

    # keep set includes skip_plugins (never delete)
    keep_plugins: Set[str] = set(desired_active_plugins.keys()) | set(skip_plugins)

    # --- plugin install/pin + activate (active-only) ---
    if not themes_only:
        for pid, p in sorted(desired_active_plugins.items(), key=lambda x: x[0]):
            want_ver = str(p.get("version") or "").strip()
            if pid in skip_plugins:
                # git/自研：不 install，不 delete；只保证激活
                if _status(installed_plugins.get(pid)) != "active":
                    steps.append(
                        PlanStep(
                            title=f"Activate plugin {pid} (git-managed)",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", pid]),
                        )
                    )
                else:
                    notes.append(f"Git-managed plugin already active: {pid}")
                continue

            cur = installed_plugins.get(pid)
            cur_ver = _version(cur)
            if (not cur) or (want_ver and cur_ver != want_ver):
                title = f"Install plugin {pid} ({want_ver or 'latest'})" if not cur else f"Pin plugin {pid} {cur_ver} -> {want_ver}"
                cmd = ["plugin", "install", pid, "--force"]
                if want_ver:
                    cmd.append(f"--version={want_ver}")
                steps.append(
                    PlanStep(
                        title=title,
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, cmd),
                    )
                )

            # ensure active
            if _status(cur) != "active":
                steps.append(
                    PlanStep(
                        title=f"Activate plugin {pid}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", pid]),
                    )
                )
            else:
                notes.append(f"Plugin version ok: {pid} ({cur_ver})")

        # --- prune extra plugins (strict only) ---
        if strict:
            not_found_pat = re.compile(r"(could not be found|not found)", re.IGNORECASE)
            for pid, cur in sorted(installed_plugins.items(), key=lambda x: x[0]):
                if pid in keep_plugins:
                    continue
                # 卸载（删除文件）；遇到幽灵条目/已不存在则忽略
                steps.append(
                    PlanStep(
                        title=f"Uninstall extra plugin {pid} (not in lock)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "uninstall", pid, "--deactivate"]),
                    )
                )
            # 执行阶段再做“幽灵”容错（见 main 的执行包装）

    # --- themes: active + required parents ---
    if not plugins_only:
        desired_active_theme = ""
        desired_required_themes: Set[str] = set()
        desired_theme_versions: Dict[str, str] = {}

        for t in lock_themes:
            if not isinstance(t, dict):
                continue
            tid = _lock_theme_id(t)
            if not tid:
                continue
            st = _lock_item_status(t)
            ver = str(t.get("version") or "").strip()
            if ver:
                desired_theme_versions[tid] = ver
            if st == "active":
                desired_active_theme = tid
            elif st == "required":
                desired_required_themes.add(tid)

        keep_themes: Set[str] = set(skip_themes)
        if desired_active_theme:
            keep_themes.add(desired_active_theme)

        # parent theme of active theme must be kept
        if desired_active_theme:
            parent = _maybe_get_parent_theme(wp_bin, site_dir, allow_root, desired_active_theme, verbose=verbose)
            if parent and parent != desired_active_theme:
                keep_themes.add(parent)
                desired_required_themes.add(parent)

        keep_themes |= desired_required_themes

        # install/pin required + active theme (skip themes are git-managed)
        for tid in sorted(keep_themes):
            if tid in skip_themes:
                continue
            want_ver = desired_theme_versions.get(tid, "")
            cur = installed_themes.get(tid)
            cur_ver = _version(cur)
            if (not cur) or (want_ver and cur_ver != want_ver):
                title = f"Install theme {tid} ({want_ver or 'latest'})" if not cur else f"Pin theme {tid} {cur_ver} -> {want_ver}"
                cmd = ["theme", "install", tid, "--force"]
                if want_ver:
                    cmd.append(f"--version={want_ver}")
                steps.append(PlanStep(title=title, cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, cmd)))

        # activate active theme
        if desired_active_theme:
            if desired_active_theme in skip_themes:
                # git-managed child theme：只做激活
                steps.append(
                    PlanStep(
                        title=f"Activate theme {desired_active_theme}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", desired_active_theme]),
                    )
                )
            else:
                steps.append(
                    PlanStep(
                        title=f"Activate theme {desired_active_theme}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", desired_active_theme]),
                    )
                )

        # prune themes (strict only): delete any theme not in keep_themes
        if strict:
            for tid, cur in sorted(installed_themes.items(), key=lambda x: x[0]):
                if tid in keep_themes:
                    continue
                if _status(cur) == "active":
                    continue
                steps.append(
                    PlanStep(
                        title=f"Delete extra theme {tid} (not in keep)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "delete", tid]),
                    )
                )

    return steps, notes


def main() -> None:
    parser = argparse.ArgumentParser(description="Apply wp-lock.json to a WordPress site.")
    parser.add_argument("--wp", required=True, help="Path to wp-cli binary")
    parser.add_argument("--allow-root", action="store_true", help="Pass --allow-root to wp-cli")
    parser.add_argument("--site-dir", required=True, help="WordPress site path (the WP root)")
    parser.add_argument("--lock-file", required=True, help="Lock file path (wp-lock.json)")
    parser.add_argument("--strict", action="store_true", help="Strict mode: keep active-only plugins + keep themes and prune extras")
    parser.add_argument("--plugins-only", action="store_true", help="Only manage plugins")
    parser.add_argument("--themes-only", action="store_true", help="Only manage themes")
    parser.add_argument("--dry-run", action="store_true", help="Print plan only, do not execute")
    parser.add_argument("--verbose", action="store_true", help="Verbose logs")
    args = parser.parse_args()

    wp_bin = _norm_path(args.wp)
    site_dir = _norm_path(args.site_dir)
    lock_file = _norm_path(args.lock_file)

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    print(f"[apply_lock] mode={'STRICT' if args.strict else 'NORMAL'} plugins_only={args.plugins_only} themes_only={args.themes_only}")

    steps, notes = build_plan(
        wp_bin=wp_bin,
        site_dir=site_dir,
        lock_file=lock_file,
        allow_root=bool(args.allow_root),
        strict=bool(args.strict),
        plugins_only=bool(args.plugins_only),
        themes_only=bool(args.themes_only),
        dry_run=bool(args.dry_run),
        verbose=bool(args.verbose),
    )

    for n in notes:
        print(f"[apply_lock] note: {n}")

    if args.dry_run:
        _exec_plan(steps, dry_run=True, verbose=bool(args.verbose))
        return

    # 执行：对“幽灵插件/已不存在”做容错（不再卡死）
    not_found_pat = re.compile(r"(could not be found|not found)", re.IGNORECASE)

    print(f"[apply_lock] Planned steps: {len(steps)}")
    for i, s in enumerate(steps, 1):
        print(f"  {i:02d}. {s.title}")
        print(f"      cmd: {' '.join(s.cmd)}")

    for s in steps:
        try:
            _run(s.cmd, verbose=bool(args.verbose))
        except Exception as e:
            msg = str(e)
            # 如果是卸载/删除阶段遇到“找不到”，视为已达成目标：继续
            if ("Uninstall extra plugin" in s.title or "Delete extra theme" in s.title) and not_found_pat.search(msg):
                print(f"[apply_lock] warn: {s.title} -> not found, skip.")
                continue

            # 兜底：Hello Dolly 单文件插件（hello）映射（避免 wp.org slug 差异）
            if "plugin install hello" in " ".join(s.cmd) and not_found_pat.search(msg):
                retry = [x if x != "hello" else "hello-dolly" for x in s.cmd]
                print("[apply_lock] warn: plugin 'hello' not found on wp.org, retry with 'hello-dolly'")
                _run(retry, verbose=bool(args.verbose))
                continue

            raise RuntimeError(f"Step failed: {s.title}\ncmd={' '.join(s.cmd)}\n{msg}") from e


if __name__ == "__main__":
    main()
