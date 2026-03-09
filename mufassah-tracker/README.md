# Mufassah Device Tracking Service

Robust GPS tracker for Raspberry Pi 4 with offline buffering, auto-restart, and comprehensive error handling.

## Features

### Core Functionality
✅ **GPS Reading** - Connects to gpsd daemon (already working on your Pi)
✅ **Location Updates** - Sends to backend every 5 minutes (configurable)
✅ **Offline Buffering** - SQLite database stores locations when network fails
✅ **Auto-Sync** - Automatically uploads buffered locations when connection restored
✅ **Auto-Restart** - systemd service restarts on crash (within 10 seconds)
✅ **Comprehensive Logging** - Logs to file and systemd journal
✅ **Heartbeat Monitoring** - Periodic health checks to backend
✅ **Graceful Shutdown** - Handles SIGTERM/SIGINT properly
✅ **Resource Limits** - Memory and CPU quotas to prevent system overload
✅ **Error Recovery** - Continues running after GPS or API failures

### Advanced Features (NEW!)
✅ **GPS Accuracy Validation** - Only sends locations with accuracy < 50 meters (configurable)
✅ **API Retry Logic** - Retries failed API calls 3 times before buffering (configurable)
✅ **Connectivity Checking** - Verifies network is up before attempting API calls
✅ **Battery Monitoring** - Reads actual battery level from Raspberry Pi
✅ **Signal Strength Monitoring** - Monitors WiFi/cellular signal strength
✅ **Dynamic Tracking Intervals** - Automatically adjusts based on device status from backend
✅ **Smart GPS Retry** - Attempts to get accurate GPS fix up to 5 times before giving up

## Prerequisites

You already have:
- ✅ Raspberry Pi OS (Linux) installed
- ✅ GPS module working and providing data
- ✅ Network connection working (WiFi/4G/ethernet)
- ✅ Python installed

## Quick Setup (5 minutes)

### 1. Transfer files to your Raspberry Pi

```bash
# From your local machine, copy files to Pi
scp -r mufassah-tracker/ pi@your-pi-ip:/home/pi/

# Or use rsync
rsync -av mufassah-tracker/ pi@your-pi-ip:/home/pi/mufassah-tracker/
```

### 2. SSH into your Raspberry Pi

```bash
ssh pi@your-pi-ip
cd /home/pi/mufassah-tracker
```

### 3. Verify prerequisites

```bash
# Check Python version (need 3.7+)
python3 --version

# Check gpsd is running
sudo systemctl status gpsd

# Test GPS data
cgps -s

# Check network connectivity
ping -c 3 google.com
```

### 4. Configure API credentials

```bash
# Edit config.env with your credentials
nano config.env

# Update these values:
# API_BASE_URL=https://your-domain.com/api/v1/iot
# MODULE_ID=MUF-001
# API_TOKEN=your_64_char_api_token_from_backend
```

### 5. Install and start service

```bash
# Make install script executable
chmod +x install.sh

# Run installation
sudo ./install.sh

# Start service
sudo systemctl start mufassah-tracker

# Check service status
sudo systemctl status mufassah-tracker

# View logs in real-time
sudo journalctl -u mufassah-tracker -f
```

## Testing

### Test 1: GPS Functionality
```bash
# Check gpsd is receiving data
cgps -s

# You should see:
# - Latitude/Longitude updating
# - Satellites locked (3+ for good fix)
# - Altitude, speed, heading data
```

### Test 2: API Connection
```bash
# Test API manually (replace with your credentials)
curl -X POST https://your-domain.com/api/v1/iot/location \
  -H "Authorization: Bearer MODULE_ID:TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"latitude":-13.9626,"longitude":33.7741,"altitude":1200}'

# Should return: 201 Created
```

### Test 3: Backend Verification
1. Login to backend admin panel
2. Go to Devices → Your Device
3. Click "Locations" tab
4. Verify location updates appearing
5. Check timestamp (should be within last 5 minutes)

### Test 4: Network Failure Handling
```bash
# Simulate network failure
sudo ifconfig eth0 down  # or wlan0 for WiFi

# Wait for location update (should buffer)
# Check logs: "✗ Buffered location (buffer size: 1)"

# Restore network
sudo ifconfig eth0 up

# Service should auto-sync buffered locations
# Check logs: "✓ Synced X locations"
```

### Test 5: Auto-Restart on Crash
```bash
# Simulate crash (kill service)
sudo pkill -9 tracker.py

# systemd should auto-restart within 10 seconds
# Verify:
sudo systemctl status mufassah-tracker

# Should show: "Restart: always"
# And multiple restarts in log
```

## Service Management

```bash
# Check service status
sudo systemctl status mufassah-tracker

# Start service
sudo systemctl start mufassah-tracker

# Stop service
sudo systemctl stop mufassah-tracker

# Restart service
sudo systemctl restart mufassah-tracker

# Enable auto-start on boot
sudo systemctl enable mufassah-tracker

# Disable auto-start
sudo systemctl disable mufassah-tracker
```

## Log Viewing

```bash
# Follow logs in real-time
sudo journalctl -u mufassah-tracker -f

# View last 100 lines
sudo journalctl -u mufassah-tracker -n 100

# View logs since today
sudo journalctl -u mufassah-tracker --since today

# View error logs only
sudo journalctl -u mufassah-tracker -p err

# Also check file logs
tail -f /home/pi/mufassah-tracker/logs/tracker.log
```

## Health Checks

```bash
# Check if process is running
ps aux | grep tracker.py

# Check buffer size (should be 0 normally)
sqlite3 /home/pi/mufassah-tracker/buffer.db \
  "SELECT COUNT(*) FROM locations;"

# Check last location timestamp
sqlite3 /home/pi/mufassah-tracker/buffer.db \
  "SELECT datetime(max(recorded_at)) FROM locations;"

# Check GPS fix
cgps -s

# Check network
ping -c 3 your-api-domain.com
```

## Troubleshooting

### Problem: Service won't start
```bash
# Check service status for errors
sudo systemctl status mufassah-tracker

# View logs
sudo journalctl -u mufassah-tracker -n 50

# Common fixes:
# - Missing Python dependencies: pip3 install --user -r requirements.txt
# - Wrong config permissions: chmod 600 config.env
# - GPS not running: sudo systemctl start gpsd
```

### Problem: GPS not getting fix
```bash
# Check gpsd status
sudo systemctl status gpsd

# Test GPS data
cgps -s

# Restart gpsd
sudo systemctl restart gpsd

# Check GPS module connection
ls /dev/ttyUSB* /dev/ttyACM*
```

### Problem: Can't reach API
```bash
# Test network
ping -c 3 your-api-domain.com

# Test DNS
nslookup your-api-domain.com

# Manual API test
curl -v https://your-api-domain.com/api/v1/iot/heartbeat

# Check credentials in config.env
cat config.env
```

### Problem: Buffer growing large
```bash
# Check buffer size
sqlite3 /home/pi/mufassah-tracker/buffer.db \
  "SELECT COUNT(*) FROM locations;"

# This indicates network/API issues
# Check logs for upload errors
sudo journalctl -u mufassah-tracker -n 100 | grep "✗"

# Manual sync test
curl -X POST https://your-domain.com/api/v1/iot/locations/batch \
  -H "Authorization: Bearer MODULE_ID:TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"locations":[{"latitude":-13.9626,"longitude":33.7741,"recorded_at":"2026-03-08T10:00:00Z"}]}'
```

## Configuration

Edit `config.env` to customize:

```bash
# API Configuration
API_BASE_URL=https://your-domain.com/api/v1/iot
MODULE_ID=MUF-001
API_TOKEN=your_64_char_api_token_from_backend

# Tracking Configuration
TRACKING_INTERVAL=300      # 5 minutes (normal), 30 seconds (stolen mode)
HEARTBEAT_INTERVAL=60      # 1 minute
BUFFER_MAX_SIZE=1000       # Maximum offline locations to buffer

# GPS Configuration
MAX_GPS_ACCURACY=50        # Maximum acceptable GPS accuracy (meters)
GPSD_HOST=localhost        # gpsd daemon host
GPSD_PORT=2947             # gpsd daemon port

# API Retry Configuration
API_RETRY_COUNT=3          # Number of retries before buffering
API_RETRY_DELAY=5          # Seconds between retries
```

### Configuration Details

| Setting | Description | Default | Range |
|---------|-------------|---------|-------|
| `TRACKING_INTERVAL` | How often to send location updates (seconds) | 300 | 30-3600 |
| `HEARTBEAT_INTERVAL` | How often to send health checks (seconds) | 60 | 30-300 |
| `BUFFER_MAX_SIZE` | Maximum offline locations to store | 1000 | 100-10000 |
| `MAX_GPS_ACCURACY` | Maximum acceptable GPS accuracy (meters) | 50 | 10-500 |
| `API_RETRY_COUNT` | Number of API retry attempts | 3 | 1-10 |
| `API_RETRY_DELAY` | Delay between retries (seconds) | 5 | 1-30 |

**Note:** Tracking interval can be automatically updated by the backend (e.g., 30s for stolen devices)

## File Structure

```
mufassah-tracker/
├── tracker.py              # Main application (single file)
├── config.env              # Environment configuration
├── buffer.db               # SQLite database (auto-created)
├── logs/                   # Log files directory
│   └── tracker.log         # Application logs
├── mufassah-tracker.service  # systemd service file
├── requirements.txt        # Python dependencies
├── install.sh              # Quick installation script
└── README.md              # This file
```

## Performance

- **CPU Usage**: < 5% when idle
- **Memory Usage**: < 50MB
- **Disk Usage**: ~1MB per 1000 buffered locations
- **Network**: ~200 bytes per location update

## License

Proprietary - Mufassah Device Tracking System

## Support

For issues or questions, contact your system administrator.
