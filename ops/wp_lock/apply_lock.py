#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
File: ops/wp_lock/apply_lock.py

Purpose:
- Apply a WordPress lock file (wp-lock.json) to a target site by aligning:
  - Core version (note-only; no core auto upgrade here)
  - Plugins: install/pin versions + activate according to lock
  - Themes: install/pin parent theme + activate desired theme according to lock
- STRICT mode:
  - Keep ONLY:
    - plugins: lock(active) + git-managed skip plugins
    - themes : active theme + its parent + git-managed skip themes
  - Remove everything else

Design notes (important):
- For EXTRA plugins cleanup in STRICT mode, DO NOT use `wp plugin uninstall`
  because it can trigger plugin uninstall scripts (uninstall.php) and fail.
  Instead: try deactivate -> remove plugin directory/file directly.
- Ignore `dropin` entries from `wp plugin list` (e.g., maintenance.php, object-cache.php)
  to avoid false "extra plugin" removals.

Usage examples:
  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/staging.fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --verbose

  python3 ops/wp_lock/apply_lock.py \
    --wp /usr/local/bin/wp --allow-root \
    --site-dir /www/wwwroot/fanbaoer.com \
    --lock-file ops/wp_lock/wp-lock.json \
    --strict --dry-run --verbose
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple


@dataclass
class Step:
    """A planned step (either wp-cli command or filesystem delete)."""
    desc: str
    cmd: Optional[List[str]] = None
    fs_delete_path: Optional[str] = None
    allow_not_found: bool = False


NOT_FOUND_HINTS = (
    "not found",
    "could not be found",
    "is not installed",
    "not installed",
    "doesn't exist",
    "does not exist",
)


def _stderr_is_not_found(stderr: str) -> bool:
    s = (stderr or "").lower()
    return any(h in s for h in NOT_FOUND_HINTS)


def _safe_realpath(base: str, *parts: str) -> str:
    """
    Join and realpath, then ensure result is inside base directory.

    This prevents accidental deletion outside WordPress root.
    """
    base_real = os.path.realpath(base)
    path_real = os.path.realpath(os.path.join(base, *parts))

    # Allow exact base, and base + separator prefix
    if path_real == base_real:
        return path_real
    if not path_real.startswith(base_real + os.sep):
        raise ValueError(f"Unsafe path: {path_real} (base={base_real})")
    return path_real


def _run(cmd: List[str], verbose: bool, check: bool = True) -> Tuple[str, str, int]:
    if verbose:
        print(f"[apply_lock] run: {' '.join(cmd)}")
    proc = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    stdout = proc.stdout or ""
    stderr = proc.stderr or ""
    if check and proc.returncode != 0:
        raise RuntimeError(
            f"Command failed (exit={proc.returncode}): {' '.join(cmd)}\n"
            f"stderr:\n{stderr}\nstdout:\n{stdout}"
        )
    return stdout, stderr, proc.returncode


def _build_wp_cmd(wp_bin: str, site_dir: str, allow_root: bool, args: List[str]) -> List[str]:
    cmd = [wp_bin]
    if allow_root:
        cmd.append("--allow-root")
    cmd.append(f"--path={site_dir}")
    cmd.extend(args)
    return cmd


def _load_json_file(path: str) -> Dict[str, Any]:
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def _get_skip_lists(lock_data: Dict[str, Any]) -> Tuple[List[str], List[str]]:
    """
    Support multiple shapes:
      - {"skip": {"plugins": [...], "themes": [...]} }
      - {"skip_plugins": [...], "skip_themes": [...]}
      - {"skipPlugins": [...], "skipThemes": [...]}
    """
    skip_plugins: List[str] = []
    skip_themes: List[str] = []

    if isinstance(lock_data.get("skip"), dict):
        skip_plugins = list(lock_data["skip"].get("plugins") or [])
        skip_themes = list(lock_data["skip"].get("themes") or [])
    else:
        skip_plugins = list(lock_data.get("skip_plugins") or lock_data.get("skipPlugins") or [])
        skip_themes = list(lock_data.get("skip_themes") or lock_data.get("skipThemes") or [])

    # Normalize
    skip_plugins = [str(x).strip() for x in skip_plugins if str(x).strip()]
    skip_themes = [str(x).strip() for x in skip_themes if str(x).strip()]
    return skip_plugins, skip_themes


def _lock_plugins_active(lock_data: Dict[str, Any]) -> List[Dict[str, Any]]:
    plugins = lock_data.get("plugins") or []
    if not isinstance(plugins, list):
        return []
    # lock is active-only now, but keep compatibility
    out: List[Dict[str, Any]] = []
    for p in plugins:
        if not isinstance(p, dict):
            continue
        status = str(p.get("status") or "").strip().lower()
        if status in ("", "active"):
            out.append(p)
    return out


def _lock_themes(lock_data: Dict[str, Any]) -> List[Dict[str, Any]]:
    themes = lock_data.get("themes") or []
    if not isinstance(themes, list):
        return []
    out: List[Dict[str, Any]] = []
    for t in themes:
        if not isinstance(t, dict):
            continue
        out.append(t)
    return out


def _slug_safe(name: str) -> str:
    name = (name or "").strip()
    if not name:
        return name
    if "/" in name or "\\" in name:
        raise ValueError(f"Unsafe slug (contains path separator): {name}")
    return name


def _remove_plugin_files(site_dir: str, plugin_slug: str, verbose: bool) -> None:
    """
    Remove plugin directory or single file safely.
    """
    plugin_slug = _slug_safe(plugin_slug)

    plugins_root = _safe_realpath(site_dir, "wp-content", "plugins")
    dir_path = _safe_realpath(plugins_root, plugin_slug)
    file_path = _safe_realpath(plugins_root, f"{plugin_slug}.php")

    if os.path.isdir(dir_path):
        if verbose:
            print(f"[apply_lock] fs: remove plugin dir: {dir_path}")
        shutil.rmtree(dir_path, ignore_errors=False)
        return

    if os.path.isfile(file_path):
        if verbose:
            print(f"[apply_lock] fs: remove plugin file: {file_path}")
        os.remove(file_path)
        return

    # Not found -> ok (strict removal tolerates not found)
    if verbose:
        print(f"[apply_lock] fs: plugin not found on disk, skip: {plugin_slug}")


def _remove_theme_files(site_dir: str, theme_slug: str, verbose: bool) -> None:
    theme_slug = _slug_safe(theme_slug)
    themes_root = _safe_realpath(site_dir, "wp-content", "themes")
    dir_path = _safe_realpath(themes_root, theme_slug)
    if os.path.isdir(dir_path):
        if verbose:
            print(f"[apply_lock] fs: remove theme dir: {dir_path}")
        shutil.rmtree(dir_path, ignore_errors=False)
    else:
        if verbose:
            print(f"[apply_lock] fs: theme not found on disk, skip: {theme_slug}")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--wp", required=True, help="Path to wp-cli binary (e.g., /usr/local/bin/wp)")
    parser.add_argument("--allow-root", action="store_true", help="Pass --allow-root to wp-cli")
    parser.add_argument("--site-dir", required=True, help="WordPress site root directory")
    parser.add_argument("--lock-file", required=True, help="Path to wp-lock.json")
    parser.add_argument("--strict", action="store_true", help="Strict mode: keep only lock active + skip lists")
    parser.add_argument("--dry-run", action="store_true", help="Plan only; do not change anything")
    parser.add_argument("--verbose", action="store_true", help="Verbose logs")
    parser.add_argument("--plugins-only", action="store_true", help="Only apply plugins")
    parser.add_argument("--themes-only", action="store_true", help="Only apply themes")
    args = parser.parse_args()

    wp_bin = args.wp
    site_dir = os.path.abspath(args.site_dir)
    lock_file = os.path.abspath(args.lock_file)
    allow_root = bool(args.allow_root)
    strict = bool(args.strict)
    dry_run = bool(args.dry_run)
    verbose = bool(args.verbose)
    plugins_only = bool(args.plugins_only)
    themes_only = bool(args.themes_only)

    print(f"[apply_lock] site={site_dir}")
    print(f"[apply_lock] lock={lock_file}")
    mode = "STRICT" if strict else "NORMAL"
    print(f"[apply_lock] mode={mode} plugins_only={plugins_only} themes_only={themes_only}")

    if not os.path.isdir(site_dir):
        raise RuntimeError(f"site-dir not found: {site_dir}")
    if not os.path.isfile(lock_file):
        raise RuntimeError(f"lock-file not found: {lock_file}")

    lock_data = _load_json_file(lock_file)
    schema_version = int(lock_data.get("schema_version") or 1)
    if schema_version not in (1, 2):
        # Be tolerant: treat unknown as 2-like
        if verbose:
            print(f"[apply_lock] warn: unexpected schema_version={schema_version}, continue as compatible.")

    skip_plugins, skip_themes = _get_skip_lists(lock_data)

    # Read current state
    core_version = _run(
        _build_wp_cmd(wp_bin, site_dir, allow_root, ["core", "version"]),
        verbose=verbose,
        check=True,
    )[0].strip()

    cur_plugins_json = _run(
        _build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "list", "--format=json"]),
        verbose=verbose,
        check=True,
    )[0].strip()

    cur_themes_json = _run(
        _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "list", "--format=json"]),
        verbose=verbose,
        check=True,
    )[0].strip()

    try:
        cur_plugins = json.loads(cur_plugins_json) if cur_plugins_json else []
    except json.JSONDecodeError:
        cur_plugins = []
    try:
        cur_themes = json.loads(cur_themes_json) if cur_themes_json else []
    except json.JSONDecodeError:
        cur_themes = []

    # Desired core
    desired_core = str(lock_data.get("core_version") or "").strip()
    if desired_core:
        if desired_core == core_version:
            print(f"[apply_lock] note: Core already matches: {core_version}")
        else:
            print(f"[apply_lock] note: Core differs (current={core_version}, desired={desired_core}) - core auto upgrade is skipped here.")
    else:
        if verbose:
            print("[apply_lock] note: core_version missing in lock - skip core check")

    steps: List[Step] = []

    # ---------------- Plugins ----------------
    if not themes_only:
        desired_plugins = _lock_plugins_active(lock_data)
        desired_active_names = [_slug_safe(str(p.get("name") or "")) for p in desired_plugins]
        desired_active_names = [n for n in desired_active_names if n]

        desired_versions: Dict[str, str] = {}
        for p in desired_plugins:
            name = _slug_safe(str(p.get("name") or ""))
            if not name:
                continue
            ver = str(p.get("version") or "").strip()
            if ver:
                desired_versions[name] = ver

        # Current standard plugins (ignore dropins)
        current_by_name: Dict[str, Dict[str, Any]] = {}
        for p in cur_plugins if isinstance(cur_plugins, list) else []:
            if not isinstance(p, dict):
                continue
            status = str(p.get("status") or "").strip().lower()
            if status == "dropin":
                # dropins are not standard plugins; ignore for strict plugin alignment
                continue
            name = _slug_safe(str(p.get("name") or ""))
            if not name:
                continue
            current_by_name[name] = p

        # Ensure git-managed plugins are active (do not install/pin)
        for git_p in skip_plugins:
            git_p = _slug_safe(git_p)
            cur = current_by_name.get(git_p)
            if not cur:
                print(f"[apply_lock] warn: Git-managed plugin missing (should be rsynced by hook): {git_p}")
                # In strict mode, treat missing as failure because site might break
                if strict:
                    raise RuntimeError(f"Git-managed plugin missing in strict mode: {git_p}")
                continue

            if str(cur.get("status") or "").strip().lower() != "active":
                steps.append(
                    Step(
                        desc=f"Activate git-managed plugin {git_p}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", git_p]),
                    )
                )
            else:
                print(f"[apply_lock] note: Git-managed plugin already active: {git_p}")

        # Install/pin + activate lock plugins
        for name in desired_active_names:
            desired_ver = desired_versions.get(name, "")
            cur = current_by_name.get(name)
            if not cur:
                if desired_ver:
                    steps.append(
                        Step(
                            desc=f"Install plugin {name} ({desired_ver})",
                            cmd=_build_wp_cmd(
                                wp_bin, site_dir, allow_root,
                                ["plugin", "install", name, "--force", f"--version={desired_ver}"],
                            ),
                        )
                    )
                else:
                    steps.append(
                        Step(
                            desc=f"Install plugin {name} (latest)",
                            cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "install", name, "--force"]),
                        )
                    )
                steps.append(
                    Step(
                        desc=f"Activate plugin {name}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", name]),
                    )
                )
                continue

            cur_ver = str(cur.get("version") or "").strip()
            cur_status = str(cur.get("status") or "").strip().lower()

            if desired_ver and cur_ver != desired_ver:
                steps.append(
                    Step(
                        desc=f"Pin plugin {name} {cur_ver} -> {desired_ver}",
                        cmd=_build_wp_cmd(
                            wp_bin, site_dir, allow_root,
                            ["plugin", "install", name, "--force", f"--version={desired_ver}"],
                        ),
                    )
                )
            else:
                if verbose:
                    print(f"[apply_lock] note: Plugin version ok: {name} ({cur_ver})")

            if cur_status != "active":
                steps.append(
                    Step(
                        desc=f"Activate plugin {name}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "activate", name]),
                    )
                )

        # STRICT: remove extra plugins (WITHOUT wp plugin uninstall)
        if strict:
            keep_plugins = set(desired_active_names) | set(skip_plugins)
            for name, cur in current_by_name.items():
                if name in keep_plugins:
                    continue
                # Extra plugin: deactivate (best effort) + delete files directly
                steps.append(
                    Step(
                        desc=f"Deactivate extra plugin {name} (not in keep)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["plugin", "deactivate", name]),
                        allow_not_found=True,
                    )
                )
                steps.append(
                    Step(
                        desc=f"Delete extra plugin files {name} (not in keep)",
                        fs_delete_path=_safe_realpath(site_dir, "wp-content", "plugins", name),
                        allow_not_found=True,
                    )
                )

    # ---------------- Themes ----------------
    if not plugins_only:
        lock_themes = _lock_themes(lock_data)

        # Find desired active theme from lock
        desired_active_theme = ""
        desired_parent_theme = ""

        for t in lock_themes:
            status = str(t.get("status") or "").strip().lower()
            if status == "active":
                desired_active_theme = _slug_safe(str(t.get("name") or ""))
                break

        # If lock doesn't explicitly say active theme, fallback to first skip_theme
        if not desired_active_theme and skip_themes:
            desired_active_theme = _slug_safe(skip_themes[0])

        if not desired_active_theme:
            # As a last resort, keep current active theme
            for t in cur_themes if isinstance(cur_themes, list) else []:
                if not isinstance(t, dict):
                    continue
                if str(t.get("status") or "").strip().lower() == "active":
                    desired_active_theme = _slug_safe(str(t.get("name") or ""))
                    break

        if desired_active_theme:
            # Query theme info to get parent (template)
            theme_get_out = _run(
                _build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "get", desired_active_theme, "--format=json"]),
                verbose=verbose,
                check=True,
            )[0].strip()
            try:
                theme_info = json.loads(theme_get_out) if theme_get_out else {}
            except json.JSONDecodeError:
                theme_info = {}
            desired_parent_theme = _slug_safe(str(theme_info.get("template") or "").strip()) if theme_info else ""

        # Desired parent version from lock (if present)
        parent_desired_ver = ""
        for t in lock_themes:
            name = _slug_safe(str(t.get("name") or ""))
            status = str(t.get("status") or "").strip().lower()
            if desired_parent_theme and name == desired_parent_theme:
                parent_desired_ver = str(t.get("version") or "").strip()
                break
            if status == "parent" and not desired_parent_theme:
                desired_parent_theme = name
                parent_desired_ver = str(t.get("version") or "").strip()
                break

        # Current themes map
        current_themes_by_name: Dict[str, Dict[str, Any]] = {}
        for t in cur_themes if isinstance(cur_themes, list) else []:
            if not isinstance(t, dict):
                continue
            name = _slug_safe(str(t.get("name") or ""))
            if not name:
                continue
            current_themes_by_name[name] = t

        # Pin parent theme version if needed (skip if parent is git-managed)
        if desired_parent_theme and desired_parent_theme not in skip_themes:
            cur_parent = current_themes_by_name.get(desired_parent_theme)
            cur_ver = str(cur_parent.get("version") or "").strip() if cur_parent else ""
            if parent_desired_ver and cur_ver != parent_desired_ver:
                steps.append(
                    Step(
                        desc=f"Pin theme {desired_parent_theme} {cur_ver or '<none>'} -> {parent_desired_ver}",
                        cmd=_build_wp_cmd(
                            wp_bin, site_dir, allow_root,
                            ["theme", "install", desired_parent_theme, "--force", f"--version={parent_desired_ver}"],
                        ),
                    )
                )
            else:
                if desired_parent_theme and cur_ver:
                    if verbose:
                        print(f"[apply_lock] note: Theme version ok: {desired_parent_theme} ({cur_ver})")

        # Ensure active theme is active
        if desired_active_theme:
            cur_active = current_themes_by_name.get(desired_active_theme)
            cur_status = str(cur_active.get("status") or "").strip().lower() if cur_active else ""
            if cur_status != "active":
                steps.append(
                    Step(
                        desc=f"Activate theme {desired_active_theme}",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "activate", desired_active_theme]),
                    )
                )

        # STRICT: remove extra themes not in keep
        if strict:
            keep_themes = set(filter(None, [desired_active_theme, desired_parent_theme])) | set(skip_themes)
            for name, cur in current_themes_by_name.items():
                if name in keep_themes:
                    continue
                # do wp theme delete first; if fails, fs remove as fallback
                steps.append(
                    Step(
                        desc=f"Delete extra theme {name} (not in keep)",
                        cmd=_build_wp_cmd(wp_bin, site_dir, allow_root, ["theme", "delete", name]),
                        allow_not_found=True,
                    )
                )
                steps.append(
                    Step(
                        desc=f"Delete extra theme files {name} (fallback)",
                        fs_delete_path=_safe_realpath(site_dir, "wp-content", "themes", name),
                        allow_not_found=True,
                    )
                )

    # ---------------- Execute / dry-run ----------------
    print(f"[apply_lock] Planned steps: {len(steps)}")
    for i, st in enumerate(steps, start=1):
        print(f"  {i:02d}. {st.desc}")
        if st.cmd:
            print(f"      cmd: {' '.join(st.cmd)}")
        if st.fs_delete_path:
            print(f"      fs : delete {st.fs_delete_path}")

    if dry_run:
        print("[apply_lock] dry-run: no changes applied.")
        return

    for st in steps:
        # wp command
        if st.cmd:
            try:
                _run(st.cmd, verbose=verbose, check=True)
            except RuntimeError as e:
                msg = str(e)
                if st.allow_not_found and _stderr_is_not_found(msg):
                    print(f"[apply_lock] warn: {st.desc} -> not found, skip.")
                else:
                    raise

        # filesystem delete
        if st.fs_delete_path:
            # Decide whether it's plugin or theme path by prefix, then remove safely
            try:
                # Use targeted remover based on known roots
                plugins_root = _safe_realpath(site_dir, "wp-content", "plugins")
                themes_root = _safe_realpath(site_dir, "wp-content", "themes")

                real = os.path.realpath(st.fs_delete_path)
                if real.startswith(plugins_root + os.sep):
                    slug = os.path.basename(real)
                    _remove_plugin_files(site_dir, slug, verbose=verbose)
                elif real.startswith(themes_root + os.sep):
                    slug = os.path.basename(real)
                    _remove_theme_files(site_dir, slug, verbose=verbose)
                else:
                    # If it's outside known roots, refuse
                    raise ValueError(f"Refuse to delete unknown path: {real}")
            except FileNotFoundError:
                if st.allow_not_found:
                    print(f"[apply_lock] warn: {st.desc} -> not found on disk, skip.")
                else:
                    raise

    print("[apply_lock] done")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"[apply_lock] ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
