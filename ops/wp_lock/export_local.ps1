# ops\wp_lock\export_local.ps1
# 本地（Laragon 真源）导出 wp-lock.json 的唯一入口脚本（固定绝对路径，避免混乱）

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ======= 只维护这三项（以后环境变了只改这里） =======
$RepoRoot = "E:\laragon\www\agri-ecommerce"
$SiteDir  = "E:\laragon\www\agri-ecommerce"
$WpCli    = "E:\wp-cli\wp.bat"
# =======================================================

$PyExe    = Join-Path $RepoRoot ".venv\Scripts\python.exe"
$ExportPy = Join-Path $RepoRoot "ops\wp_lock\export_lock.py"
$OutRel   = "ops\wp_lock\wp-lock.json"
$OutAbs   = Join-Path $RepoRoot $OutRel

if (-not (Test-Path $PyExe))    { throw "Python not found: $PyExe" }
if (-not (Test-Path $ExportPy)) { throw "export_lock.py not found: $ExportPy" }
if (-not (Test-Path $WpCli))    { throw "WP-CLI not found: $WpCli" }
if (-not (Test-Path $SiteDir))  { throw "Site dir not found: $SiteDir" }

Write-Host "[export_local] repo_root: $RepoRoot"
Write-Host "[export_local] python:    $PyExe"
Write-Host "[export_local] wp-cli:    $WpCli"
Write-Host "[export_local] site_dir:   $SiteDir"
Write-Host "[export_local] out:        $OutRel"

Set-Location $RepoRoot

& $PyExe $ExportPy `
  --wp $WpCli `
  --site-dir $SiteDir `
  --out $OutRel `
  --skip-plugin myshop-core `
  --skip-theme astra-child `
  --verbose

if ($LASTEXITCODE -ne 0) {
  throw "export_lock.py failed with exit code $LASTEXITCODE"
}

if (-not (Test-Path $OutAbs)) {
  throw "wp-lock.json not generated: $OutAbs"
}

Write-Host "OK"
