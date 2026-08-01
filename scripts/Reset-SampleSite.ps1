param([string] $TargetRoot = '')

$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$reset = Join-Path $RepoRoot 'scripts\reset-sample-site.js'
$arguments = @($reset)
if ($TargetRoot) { $arguments += $TargetRoot }
& node @arguments
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
