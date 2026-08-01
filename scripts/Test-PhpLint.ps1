$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { 'php' }
$files = & git -C $repoRoot ls-files '*.php'
if ($LASTEXITCODE -ne 0) { throw 'Could not enumerate tracked PHP.' }
foreach ($relative in $files) {
    & $php -l (Join-Path $repoRoot $relative) | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $relative" }
}
Write-Host "PHP lint passed: $($files.Count) files"
