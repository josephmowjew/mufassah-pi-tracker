#!/bin/bash
set -e

echo "=== Mufassah Tracker Installation ==="
echo ""

# Ensure we're in the right directory
cd "$(dirname "$0")"

# Install Python dependencies
echo "Installing Python dependencies..."
pip3 install --user -r requirements.txt

# Make tracker executable
chmod +x tracker.py

# Setup systemd service
echo "Installing systemd service..."
sudo cp mufassah-tracker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable mufassah-tracker.service

echo ""
echo "=== Installation Complete ==="
echo ""
echo "Next steps:"
echo "1. Edit config.env with your API credentials:"
echo "   nano config.env"
echo ""
echo "2. Start service:"
echo "   sudo systemctl start mufassah-tracker"
echo ""
echo "3. Check status:"
echo "   sudo systemctl status mufassah-tracker"
echo ""
echo "4. View logs:"
echo "   sudo journalctl -u mufassah-tracker -f"
echo ""
