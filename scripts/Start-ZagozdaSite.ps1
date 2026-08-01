param(
    [ValidateRange(1, 65535)]
    [int] $Port = 18899
)

$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$SiteRoot = Join-Path $RepoRoot 'zagozda'
$Router = Join-Path $SiteRoot 'router.php'
$BundledSitePhp = Join-Path $SiteRoot 'php\php.exe'
$BundledRepoPhp = Join-Path $RepoRoot 'php\php.exe'
$PhpCandidate = if ($env:PAGECORE_ZAGOZDA_PHP_EXE) {
    $env:PAGECORE_ZAGOZDA_PHP_EXE
} elseif (Test-Path -LiteralPath $BundledSitePhp -PathType Leaf) {
    $BundledSitePhp
} else {
    $BundledRepoPhp
}

if (-not (Test-Path -LiteralPath $SiteRoot -PathType Container)) {
    throw "Zagozda site not found at $SiteRoot. Restore the private deployment fixture before running this lane."
}
if (-not (Test-Path -LiteralPath $Router -PathType Leaf)) {
    throw "Zagozda router not found at $Router"
}
if (-not (Test-Path -LiteralPath $PhpCandidate -PathType Leaf)) {
    throw "PHP executable not found at $PhpCandidate. Set PAGECORE_ZAGOZDA_PHP_EXE to a valid PHP executable."
}

$PhpExe = (Resolve-Path -LiteralPath $PhpCandidate).Path

& $PhpExe -S "127.0.0.1:$Port" -t $SiteRoot $Router
exit $LASTEXITCODE
