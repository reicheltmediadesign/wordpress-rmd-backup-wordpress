# Links this checkout into a local WordPress install for testing.
# Usage: .\bin\link-local.ps1 -WordPressPath "C:\xampp\htdocs\site" [-Copy]
#   default: junction wp-content\plugins\rmd-backup-wordpress -> this repo
#   -Copy:   robocopy mirror instead of a junction (if WordPress misbehaves with links)
# Only link into a dedicated test site: customer sites get the release zip instead.
param(
	[Parameter(Mandatory = $true)]
	[string]$WordPressPath,
	[switch]$Copy
)

$ErrorActionPreference = "Stop"
$RepoPath = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$Target = Join-Path $WordPressPath "wp-content\plugins\rmd-backup-wordpress"

if (-not (Test-Path (Join-Path $WordPressPath "wp-config.php"))) {
	throw "No WordPress install found at $($WordPressPath)"
}

if ($Copy) {
	$Excluded = @("node_modules", ".git", "dist", "tests", ".github")
	robocopy "$($RepoPath)" "$($Target)" /MIR /XD $Excluded /NFL /NDL /NJH /NJS /NP | Out-Null
	if ($LASTEXITCODE -ge 8) { throw "robocopy failed with code $($LASTEXITCODE)" }
	Write-Host "Copied $($RepoPath) -> $($Target)"
	exit 0
}

if (Test-Path $Target) {
	$Item = Get-Item $Target -Force
	if ($Item.LinkType -eq "Junction") {
		Write-Host "Junction already exists: $($Target) -> $($Item.Target)"
		exit 0
	}
	throw "$($Target) exists and is not a junction. Remove it first or use -Copy."
}

New-Item -ItemType Junction -Path $Target -Target $RepoPath | Out-Null
Write-Host "Created junction $($Target) -> $($RepoPath)"
