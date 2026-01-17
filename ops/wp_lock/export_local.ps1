# ops\wp_lock\export_local.ps1
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# === 本地真源锚点（以后只改这两行）===
$SiteDir = "E:\laragon\www\agri-ecommerce"
$OutFile = "ops\wp_lock\wp-lock.json"

# === 执行导出 ===
Push-Location (Split-Path $PSScriptRoot -Parent)  # 进入 ops 上一级（仓库根）
python .\ops\wp_lock\export_lock.py --site-dir $SiteDir --out $OutFile --verbose
Pop-Location

Write-Host "OK: exported $OutFile from $SiteDir"
