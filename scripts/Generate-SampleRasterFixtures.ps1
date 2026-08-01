$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$FixtureRoot = Join-Path $RepoRoot 'sample-site\fixtures\uploads\2026\07'
$Png = [Convert]::FromBase64String(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
)

[IO.File]::WriteAllBytes((Join-Path $FixtureRoot 'featured-pagecore.png'), $Png)
[IO.File]::WriteAllBytes((Join-Path $FixtureRoot 'sample-logo.png'), $Png)

Write-Host 'Generated sample raster upload fixtures.'
