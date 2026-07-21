param(
    [int] $Port = 8765
)

$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
# An override lets local contributors use an installed PHP runtime when the bundled runtime is absent.
$PhpExe = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $RepoRoot 'php\php.exe' }
$Router = Join-Path $RepoRoot 'sample-site\router.php'
$Config = Join-Path $RepoRoot 'sample-site\config.php'

if (-not (Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found at $PhpExe"
}

& (Join-Path $PSScriptRoot 'Reset-SampleSite.ps1')

$env:PAGECORE_CONFIG = $Config
$env:PAGECORE_SITE_URL = "http://127.0.0.1:$Port"

& $PhpExe -S "127.0.0.1:$Port" -t $RepoRoot $Router
