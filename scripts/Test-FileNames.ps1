$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$sourceRoots = @('cms', 'deployment', 'sample-site', 'scripts', 'tests')
$sourceExtensions = @('.php', '.js', '.css')
$exceptions = @('cms/lib/Parsedown.php')
$filenameViolations = foreach ($sourceRoot in $sourceRoots) {
    Get-ChildItem -LiteralPath (Join-Path $repoRoot $sourceRoot) -File -Recurse | ForEach-Object {
        $relative = $_.FullName.Substring($repoRoot.Length + 1).Replace('\', '/')
        if ($_.Extension -in $sourceExtensions -and $relative -notin $exceptions -and $_.Name -cmatch '[A-Z]') {
            $relative
        }
    }
}
$referenceExtensions = @('.php', '.js', '.css', '.md', '.neon', '.json', '.yml', '.yaml', '.ps1')
$referencePattern = '(?<![A-Za-z0-9_%_-])(?<name>[A-Za-z0-9_-]*[A-Z][A-Za-z0-9_-]*\.(?:php|js|css))(?![A-Za-z0-9_-])'
$referenceFiles = @(
    Get-Item -LiteralPath (Join-Path $repoRoot 'README.md'), (Join-Path $repoRoot 'phpstan.neon')
    foreach ($sourceRoot in ($sourceRoots + 'docs')) {
        Get-ChildItem -LiteralPath (Join-Path $repoRoot $sourceRoot) -File -Recurse |
            Where-Object { $_.Extension -in $referenceExtensions }
    }
)
$referenceViolations = foreach ($file in $referenceFiles) {
    $relative = $file.FullName.Substring($repoRoot.Length + 1).Replace('\', '/')
    foreach ($match in [regex]::Matches((Get-Content -LiteralPath $file.FullName -Raw), $referencePattern)) {
        if ($match.Groups['name'].Value -ne 'Parsedown.php') {
            "$relative -> $($match.Groups['name'].Value)"
        }
    }
}
$violations = @($filenameViolations) + @($referenceViolations) | Sort-Object -Unique
if ($violations) {
    throw "Project-authored PHP, JavaScript, and CSS filenames and references must be lowercase (use hyphens between words):`n$($violations -join "`n")"
}
Write-Host 'Source filename casing passed.'
