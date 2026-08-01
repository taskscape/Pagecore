$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$phpstan = Join-Path $repoRoot 'vendor\bin\phpstan.bat'
if (-not (Test-Path -LiteralPath $phpstan -PathType Leaf)) { throw 'PHPStan is not installed. Run composer install.' }
& $phpstan analyse --configuration (Join-Path $repoRoot 'phpstan.neon') --no-progress
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
