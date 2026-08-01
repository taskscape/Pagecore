$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $RepoRoot 'php\php.exe' }

& $PhpCandidate (Join-Path $RepoRoot 'tests\login-throttle.php')
exit $LASTEXITCODE
