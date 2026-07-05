param()

$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$SampleRoot = Join-Path $RepoRoot 'sample-site'
$FixturesContent = Join-Path $SampleRoot 'fixtures\content'
$FixturesUploads = Join-Path $SampleRoot 'fixtures\uploads'
$WorkingContent = Join-Path $SampleRoot 'working-content'
$WorkingUploads = Join-Path $SampleRoot 'working-uploads'
$GeneratedFiles = @(
    (Join-Path $SampleRoot 'search-index.json'),
    (Join-Path $SampleRoot 'sitemap.xml')
)

function Assert-UnderSampleRoot {
    param([string] $Path)
    $sampleFull = [System.IO.Path]::GetFullPath($SampleRoot)
    $targetFull = [System.IO.Path]::GetFullPath($Path)
    if (-not $targetFull.StartsWith($sampleFull, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to modify path outside sample-site: $targetFull"
    }
}

foreach ($path in @($WorkingContent, $WorkingUploads) + $GeneratedFiles) {
    Assert-UnderSampleRoot $path
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Recurse -Force
    }
}

New-Item -ItemType Directory -Path $WorkingContent | Out-Null
New-Item -ItemType Directory -Path $WorkingUploads | Out-Null

Get-ChildItem -LiteralPath $FixturesContent -Force | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $WorkingContent -Recurse -Force
}

Get-ChildItem -LiteralPath $FixturesUploads -Force | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $WorkingUploads -Recurse -Force
}

Write-Host "Sample site reset: $SampleRoot"
