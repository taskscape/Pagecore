$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
& node (Join-Path $repoRoot 'scripts\test-sample-test-site.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
