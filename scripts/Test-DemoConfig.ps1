[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$phpExe = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
$testFile = Join-Path $repoRoot 'tests\demo-config-policy.php'

foreach ($mode in @('production', 'development')) {
    & $phpExe $testFile $mode
    if ($LASTEXITCODE -ne 0) { throw "Demo configuration $mode policy failed." }
}
