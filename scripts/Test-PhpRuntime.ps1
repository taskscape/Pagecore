[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$phpExe = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
$policyTest = Join-Path $repoRoot 'tests\runtime-policy.php'

if (-not (Test-Path -LiteralPath $phpExe -PathType Leaf)) {
    throw "PHP executable not found: $phpExe"
}

& $phpExe $policyTest
if ($LASTEXITCODE -ne 0) { throw 'PHP runtime policy checks failed.' }
