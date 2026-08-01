[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$testRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('pagecore-artifact-test-' + [guid]::NewGuid().ToString('N'))
$output = Join-Path $testRoot 'artifacts'
$site = Join-Path $testRoot 'site'

try {
    New-Item -ItemType Directory -Path (Join-Path $site 'cms') -Force | Out-Null
    [System.IO.File]::WriteAllText((Join-Path $site 'cms\config.php'), '<?php return array();')
    $release = & (Join-Path $PSScriptRoot 'Build-PagecoreRelease.ps1') -OutputDirectory $output
    $checksum = (Get-Content -LiteralPath $release.ChecksumFile -Raw).Trim()
    if ($checksum -ne "$($release.Sha256)  $([System.IO.Path]::GetFileName($release.Archive))") {
        throw 'Release checksum sidecar does not match the built archive.'
    }
    & (Join-Path $PSScriptRoot 'Install-PagecoreRelease.ps1') -Archive $release.Archive -SiteRoot $site
    & (Join-Path $PSScriptRoot 'Test-PagecoreDeployment.ps1') -SiteRoot $site
    if ((Get-Content -LiteralPath (Join-Path $site 'cms\config.php') -Raw) -ne '<?php return array();') {
        throw 'Site-specific configuration was not preserved.'
    }
    Add-Content -LiteralPath (Join-Path $site 'cms\engine.php') -Value '// drift'
    $driftDetected = $false
    try { & (Join-Path $PSScriptRoot 'Test-PagecoreDeployment.ps1') -SiteRoot $site } catch {
        $driftDetected = $_.Exception.Message -like '*Deployment drift detected*'
    }
    if (-not $driftDetected) { throw 'Deployment drift was not detected.' }
    Write-Host "Release artifact $($release.Version) build/install/drift checks passed."
} finally {
    if (Test-Path -LiteralPath $testRoot) { Remove-Item -LiteralPath $testRoot -Recurse -Force }
}
