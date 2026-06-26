#!/usr/bin/env bash
#
# Install SANE scanner utilities on Debian/Ubuntu and verify the scanner is
# visible. Run this on the FO/operator Linux computer (NOT the VPS).
#
set -euo pipefail

echo "==> Installing sane-utils (requires sudo)…"
sudo apt update
sudo apt install -y sane-utils

echo
echo "==> Detecting scanners (scanimage -L):"
scanimage -L || true

echo
echo "==> Quick capture test → ktp-test.jpg"
echo "    (skip with Ctrl-C if no scanner is attached)"
read -r -p "Run a test scan now? [y/N] " ans
if [[ "${ans:-N}" =~ ^[Yy]$ ]]; then
  scanimage --format=jpeg --resolution 200 > ktp-test.jpg
  echo "Saved ktp-test.jpg ($(wc -c < ktp-test.jpg) bytes)"
  echo "Delete it when done — do not keep real document scans in this folder."
fi

echo
echo "Done. Set SCANNER_AGENT_BACKEND=sane and start the agent with: npm start"
