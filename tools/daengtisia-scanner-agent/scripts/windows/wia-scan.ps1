<#
.SYNOPSIS
  EXPERIMENTAL WIA single-page scan for the DaengtisiaMS scanner agent.

.DESCRIPTION
  Uses the Windows Image Acquisition (WIA) COM API to acquire one page from the
  default scanner and save it as JPEG. WIA automation behaviour varies a lot by
  driver — some drivers ignore the DPI hint or pop their own UI. This script is
  a REFERENCE / prototype only and is NOT the default backend.

  The DaengtisiaMS agent calls this with -File (never -Command), so no request
  content is ever interpreted as PowerShell.

.PARAMETER OutFile
  Destination JPEG path (the agent passes a temp file it later deletes).

.PARAMETER Dpi
  Horizontal/vertical resolution hint (validated numeric value from the agent).

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File wia-scan.ps1 -OutFile out.jpg -Dpi 200
#>
param(
  [Parameter(Mandatory = $true)][string]$OutFile,
  [int]$Dpi = 200
)

$ErrorActionPreference = "Stop"

# WIA format GUID for JPEG.
$wiaFormatJPEG = "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}"

try {
  $deviceManager = New-Object -ComObject WIA.DeviceManager
  $device = $null

  foreach ($info in $deviceManager.DeviceInfos) {
    if ($info.Type -eq 1) { # 1 = Scanner
      $device = $info.Connect()
      break
    }
  }

  if ($null -eq $device) {
    Write-Error "No WIA scanner device found."
    exit 2
  }

  $item = $device.Items.Item(1)

  # Best-effort DPI hint (property IDs 6147 = X res, 6148 = Y res).
  try {
    $item.Properties.Item("6147").Value = $Dpi
    $item.Properties.Item("6148").Value = $Dpi
  } catch {
    Write-Warning "Driver did not accept DPI hint; continuing with defaults."
  }

  $image = $item.Transfer($wiaFormatJPEG)

  if (Test-Path $OutFile) { Remove-Item $OutFile -Force }
  $image.SaveFile($OutFile)

  Write-Output "Saved scan to $OutFile"
  exit 0
}
catch {
  Write-Error "WIA scan failed: $($_.Exception.Message)"
  exit 1
}
