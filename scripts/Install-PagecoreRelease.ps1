[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $Archive,
    [Parameter(Mandatory = $true)][string] $SiteRoot
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ReleaseHelpers.ps1')
$archivePath = (Resolve-Path -LiteralPath $Archive).Path
$sitePath = (Resolve-Path -LiteralPath $SiteRoot).Path
$targetCms = [System.IO.Path]::GetFullPath((Join-Path $sitePath 'cms'))
if (-not $targetCms.StartsWith($sitePath + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'CMS target must be a direct child of the selected site root.'
}
$work = Join-Path ([System.IO.Path]::GetTempPath()) ('pagecore-install-' + [guid]::NewGuid().ToString('N'))
$backupCms = Join-Path $sitePath ('.pagecore-cms-backup-' + [guid]::NewGuid().ToString('N'))
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

try {
    Expand-Archive -LiteralPath $archivePath -DestinationPath $work
    $manifestPath = Join-Path $work 'manifest.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ($manifest.schema -ne 1 -or -not $manifest.version -or -not $manifest.files) { throw 'Invalid Pagecore release manifest.' }
    foreach ($entry in $manifest.files) {
        $candidate = [System.IO.Path]::GetFullPath((Join-Path $work ([string] $entry.path)))
        if (-not $candidate.StartsWith($work + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw 'Release manifest contains an unsafe path.'
        }
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { throw "Release file missing: $($entry.path)" }
        $actual = Get-PagecoreSha256 -Path $candidate
        if ($actual -ne [string] $entry.sha256) { throw "Release checksum mismatch: $($entry.path)" }
    }

    $preservedConfig = $null
    $configPath = Join-Path $targetCms 'config.php'
    if (Test-Path -LiteralPath $configPath -PathType Leaf) { $preservedConfig = [System.IO.File]::ReadAllBytes($configPath) }
    if (Test-Path -LiteralPath $targetCms) { Move-Item -LiteralPath $targetCms -Destination $backupCms }
    try {
        Move-Item -LiteralPath (Join-Path $work 'cms') -Destination $targetCms
        if ($preservedConfig) { [System.IO.File]::WriteAllBytes((Join-Path $targetCms 'config.php'), $preservedConfig) }
        foreach ($directory in @('content', 'uploads')) {
            $source = Join-Path $work $directory
            if (-not (Test-Path -LiteralPath $source)) { continue }
            $target = Join-Path $sitePath $directory
            New-Item -ItemType Directory -Path $target -Force | Out-Null
            Get-ChildItem -LiteralPath $source -File | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $target $_.Name) -Force }
        }
        Copy-Item -LiteralPath (Join-Path $work 'VERSION') -Destination (Join-Path $sitePath 'VERSION') -Force
        $receipt = [ordered]@{
            schema = 1
            version = [string] $manifest.version
            archive_sha256 = Get-PagecoreSha256 -Path $archivePath
            files = $manifest.files
        } | ConvertTo-Json -Depth 6
        [System.IO.File]::WriteAllText((Join-Path $sitePath '.pagecore-release.json'), $receipt, $utf8NoBom)
    } catch {
        if (Test-Path -LiteralPath $targetCms) { Remove-Item -LiteralPath $targetCms -Recurse -Force }
        if (Test-Path -LiteralPath $backupCms) { Move-Item -LiteralPath $backupCms -Destination $targetCms }
        throw
    }
    if (Test-Path -LiteralPath $backupCms) { Remove-Item -LiteralPath $backupCms -Recurse -Force }
    Write-Host "Installed Pagecore $($manifest.version) into $sitePath"
} finally {
    if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force }
}
