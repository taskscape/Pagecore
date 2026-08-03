[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $SiteRoot,
    # Must match the -PrivateRoot the release was installed with: the content
    # and uploads seeds are verified wherever they were put.
    [string] $PrivateRoot
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ReleaseHelpers.ps1')
$sitePath = (Resolve-Path -LiteralPath $SiteRoot).Path
$privatePath = if ($PrivateRoot) { (Resolve-Path -LiteralPath $PrivateRoot).Path } else { $sitePath }
$receiptPath = Join-Path $sitePath '.pagecore-release.json'
if (-not (Test-Path -LiteralPath $receiptPath -PathType Leaf)) { throw 'Deployment receipt is missing.' }
$receipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
foreach ($entry in $receipt.files) {
    $relative = [string] $entry.path
    $root = if ($relative -match '^(content|uploads)/') { $privatePath } else { $sitePath }
    $path = [System.IO.Path]::GetFullPath((Join-Path $root $relative))
    if (-not $path.StartsWith($root + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'Deployment receipt contains an unsafe path.'
    }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Managed deployment file missing: $relative" }
    $actual = Get-PagecoreSha256 -Path $path
    if ($actual -ne [string] $entry.sha256) { throw "Deployment drift detected: $relative" }
}
$engine = Get-Content -LiteralPath (Join-Path $sitePath 'cms\engine.php') -Raw
if ($engine -notmatch "PAGECORE_VERSION',\s*'$([regex]::Escape([string] $receipt.version))'") {
    throw 'Deployed engine version does not match the release receipt.'
}
Write-Host "Pagecore $($receipt.version) deployment matches its release manifest."
