$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) {
    $env:PAGECORE_PHP_EXE
} else {
    Join-Path $RepoRoot 'php\php.exe'
}
$Importer = Join-Path $RepoRoot 'scripts\import-wordpress.php'

if (-not (Test-Path -LiteralPath $PhpCandidate -PathType Leaf)) {
    throw "PHP executable not found at $PhpCandidate. Set PAGECORE_PHP_EXE to a valid PHP executable."
}

& (Resolve-Path -LiteralPath $PhpCandidate).Path $Importer --self-test-html
exit $LASTEXITCODE
