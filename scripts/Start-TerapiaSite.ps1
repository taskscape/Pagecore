param(
    [ValidateRange(1, 65535)]
    [int] $Port = 18877,
    [string] $HostAddress = '127.0.0.1'
)

$ErrorActionPreference = 'Stop'

if ($HostAddress -notin @('127.0.0.1', '::1')) {
    throw 'The private migration fixture may bind only to a loopback address.'
}

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$SiteRoot = Join-Path $RepoRoot 'terapiawypalenia'
$Router = Join-Path $SiteRoot 'router.php'
$PhpCandidate = if ($env:PAGECORE_TERAPIA_PHP_EXE) { $env:PAGECORE_TERAPIA_PHP_EXE } else { Join-Path $RepoRoot 'php\php.exe' }

if (-not (Test-Path -LiteralPath $SiteRoot -PathType Container)) {
    throw "Site not found at $SiteRoot. Restore the private deployment fixture before running this lane."
}
if (-not (Test-Path -LiteralPath $Router -PathType Leaf)) { throw "Router not found at $Router" }
if (-not (Test-Path -LiteralPath $PhpCandidate -PathType Leaf)) {
    throw "PHP executable not found at $PhpCandidate. Set PAGECORE_TERAPIA_PHP_EXE to a valid PHP executable."
}

# Install the engine from a freshly built, checksummed release so the site can
# never drift into being a second copy of the CMS.
$releaseDir = Join-Path ([System.IO.Path]::GetTempPath()) ('pagecore-terapia-release-' + [guid]::NewGuid().ToString('N'))
try {
    $release = & (Join-Path $PSScriptRoot 'Build-PagecoreRelease.ps1') -OutputDirectory $releaseDir
    & (Join-Path $PSScriptRoot 'Install-PagecoreRelease.ps1') -Archive $release.Archive -SiteRoot $SiteRoot
    & (Join-Path $PSScriptRoot 'Test-PagecoreDeployment.ps1') -SiteRoot $SiteRoot
} finally {
    if (Test-Path -LiteralPath $releaseDir) { Remove-Item -LiteralPath $releaseDir -Recurse -Force }
}

$PhpExe = (Resolve-Path -LiteralPath $PhpCandidate).Path
$env:PAGECORE_CONFIG = Join-Path $SiteRoot 'cms\config.php'
$env:PAGECORE_DEVELOPMENT = '1'

& $PhpExe -S "${HostAddress}:$Port" -t $SiteRoot $Router
exit $LASTEXITCODE
