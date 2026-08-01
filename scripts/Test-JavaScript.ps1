$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$files = & git -C $repoRoot ls-files '*.js'
if ($LASTEXITCODE -ne 0) { throw 'Could not enumerate tracked JavaScript.' }
foreach ($relative in $files) {
    & node --check (Join-Path $repoRoot $relative)
    if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax failed: $relative" }
}
Write-Host "JavaScript syntax passed: $($files.Count) files"
