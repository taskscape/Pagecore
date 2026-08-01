$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $RepoRoot 'php\php.exe' }
$Importer = Join-Path $RepoRoot 'scripts\import-wordpress.php'
$SqlFixture = Join-Path $RepoRoot 'tests\fixtures\import-status.sql'
$TestRoot = Join-Path ([IO.Path]::GetTempPath()) ('pagecore-import-status-' + [guid]::NewGuid().ToString('N'))
$ContentRoot = Join-Path $TestRoot 'content'

try {
    New-Item -ItemType Directory -Path $TestRoot | Out-Null

    $PreviousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $PhpCandidate $Importer "--sql=$SqlFixture" "--out-content=$ContentRoot" '--status=publish,private,draft' '--copy-uploads=0' 2>$null
    $UnacknowledgedExit = $LASTEXITCODE
    $ErrorActionPreference = $PreviousErrorAction
    if ($UnacknowledgedExit -eq 0) { throw 'Non-public import succeeded without explicit acknowledgement.' }

    & $PhpCandidate $Importer "--sql=$SqlFixture" "--out-content=$ContentRoot" '--status=publish,private,draft' '--include-non-public=1' '--copy-uploads=0'
    if ($LASTEXITCODE -ne 0) { throw 'Acknowledged status import failed.' }

    $PublicPost = Get-Content -LiteralPath (Join-Path $ContentRoot 'posts\public-post.md') -Raw
    $PrivatePost = Get-Content -LiteralPath (Join-Path $ContentRoot 'posts\private-post.md') -Raw
    $DraftPage = Join-Path $ContentRoot '.drafts\imported-pages\draft-page\body.md'
    $PublicPage = Join-Path $ContentRoot 'pages\draft-page\body.md'
    $Fragment = Get-Content -LiteralPath (Join-Path $ContentRoot 'imported-config-fragment.php') -Raw

    if ($PublicPost -match '(?m)^status:') { throw 'Published post received a non-public status marker.' }
    if ($PrivatePost -notmatch '(?m)^status:\s*private\s*$') { throw 'Private post did not preserve its status.' }
    if (-not (Test-Path -LiteralPath $DraftPage -PathType Leaf)) { throw 'Draft page was not staged for review.' }
    if (Test-Path -LiteralPath $PublicPage) { throw 'Draft page was written into the public page tree.' }
    if ($Fragment -match '/draft-page/') { throw 'Draft page leaked into generated search configuration.' }

    Write-Host 'PASS: importer requires acknowledgement and isolates non-public output'
} finally {
    if (Test-Path -LiteralPath $TestRoot) {
        $Resolved = (Resolve-Path -LiteralPath $TestRoot).Path
        $TempBase = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
        if (-not $Resolved.StartsWith($TempBase, [StringComparison]::OrdinalIgnoreCase) -or
            (Split-Path -Leaf $Resolved) -notlike 'pagecore-import-status-*') {
            throw "Refusing to remove unexpected path: $Resolved"
        }
        Remove-Item -LiteralPath $Resolved -Recurse -Force
    }
}
