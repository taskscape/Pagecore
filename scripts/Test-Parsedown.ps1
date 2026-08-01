param(
    [switch] $AllowOutdated
)

$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) {
    $env:PAGECORE_PHP_EXE
} else {
    Join-Path $RepoRoot 'php\php.exe'
}
$TestFile = Join-Path $RepoRoot 'tests\parsedown-security.php'

if (-not (Test-Path -LiteralPath $PhpCandidate -PathType Leaf)) {
    throw "PHP executable not found at $PhpCandidate. Set PAGECORE_PHP_EXE to a valid PHP executable."
}

$arguments = @($TestFile)
if ($AllowOutdated) {
    $arguments += '--allow-outdated'
}

& (Resolve-Path -LiteralPath $PhpCandidate).Path @arguments
exit $LASTEXITCODE
