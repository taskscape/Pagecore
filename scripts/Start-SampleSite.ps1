param(
    [ValidateRange(1, 65535)]
    [int] $Port = 8765
)

$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) {
    $env:PAGECORE_PHP_EXE
} else {
    Join-Path $RepoRoot 'php\php.exe'
}
$Router = Join-Path $RepoRoot 'sample-site\router.php'
$Config = Join-Path $RepoRoot 'sample-site\config.php'

if (-not (Test-Path -LiteralPath $PhpCandidate -PathType Leaf)) {
    throw "PHP executable not found at $PhpCandidate. Set PAGECORE_PHP_EXE to a valid PHP executable."
}
if (-not (Test-Path -LiteralPath $Router -PathType Leaf)) {
    throw "Sample-site router not found at $Router"
}
if (-not (Test-Path -LiteralPath $Config -PathType Leaf)) {
    throw "Sample-site config not found at $Config"
}

$PhpExe = (Resolve-Path -LiteralPath $PhpCandidate).Path

& (Join-Path $PSScriptRoot 'Reset-SampleSite.ps1')

$env:PAGECORE_CONFIG = $Config
$env:PAGECORE_SITE_URL = "http://127.0.0.1:$Port"

# Keep transport limits above the CMS rule so application validation owns the test result.
& $PhpExe -d upload_max_filesize=16M -d post_max_size=16M -S "127.0.0.1:$Port" -t $RepoRoot $Router
exit $LASTEXITCODE
