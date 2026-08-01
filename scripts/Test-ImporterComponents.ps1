$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
& $php (Join-Path $repoRoot 'tests\wordpress-import-components.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
