[CmdletBinding()]
param(
    [string] $OutputDirectory
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ReleaseHelpers.ps1')
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $repoRoot 'artifacts' }
$engine = Get-Content -LiteralPath (Join-Path $repoRoot 'cms\engine.php') -Raw
$match = [regex]::Match($engine, "PAGECORE_VERSION',\s*'([^']+)'")
if (-not $match.Success) { throw 'Could not read PAGECORE_VERSION.' }
$version = $match.Groups[1].Value
$outputRoot = [System.IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
$archive = Join-Path $outputRoot "pagecore-$version.zip"
$staging = Join-Path ([System.IO.Path]::GetTempPath()) ('pagecore-release-' + [guid]::NewGuid().ToString('N'))
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

try {
    New-Item -ItemType Directory -Path $staging | Out-Null
    $files = @(& git -C $repoRoot ls-files -- cms content uploads)
    if ($LASTEXITCODE -ne 0 -or -not $files) { throw 'Could not enumerate tracked release files.' }
    foreach ($relative in $files) {
        $source = Join-Path $repoRoot $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { continue }
        $destination = Join-Path $staging $relative
        New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination -Force
    }
    [System.IO.File]::WriteAllText((Join-Path $staging 'VERSION'), $version + "`n", $utf8NoBom)
    $manifestFiles = Get-ChildItem -LiteralPath $staging -File -Recurse | ForEach-Object {
        $relative = $_.FullName.Substring($staging.Length + 1).Replace('\', '/')
        [ordered]@{ path = $relative; sha256 = Get-PagecoreSha256 -Path $_.FullName }
    } | Sort-Object path
    $manifest = [ordered]@{ schema = 1; version = $version; files = @($manifestFiles) }
    [System.IO.File]::WriteAllText((Join-Path $staging 'manifest.json'), ($manifest | ConvertTo-Json -Depth 5), $utf8NoBom)
    if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
    Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archive -CompressionLevel Optimal
    [pscustomobject]@{
        Version = $version
        Archive = $archive
        Sha256 = Get-PagecoreSha256 -Path $archive
    }
} finally {
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force }
}
