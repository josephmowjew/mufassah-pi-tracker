#!/usr/bin/env python3
"""
Mufassah Device Tracking Service
Robust GPS tracker with offline buffering, auto-recovery, and comprehensive monitoring
"""

import os
import sys
import time
import signal
import logging
import sqlite3
import subprocess
import socket
import requests
from datetime import datetime
from pathlib import Path
from threading import Thread, Event
from urllib.parse import urlparse

# ==============================================================================
# CONFIGURATION
# ==============================================================================

class Config:
    """Load configuration from environment file"""

    def __init__(self, env_file='config.env'):
        self.load_env(env_file)
        self.validate()

    def load_env(self, env_file):
        """Parse simple KEY=value format"""
        if not os.path.exists(env_file):
            raise FileNotFoundError(f"Config file not found: {env_file}")

        with open(env_file, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    setattr(self, key.strip(), value.strip())

    def validate(self):
        """Ensure required config exists"""
        required = ['API_BASE_URL', 'MODULE_ID', 'API_TOKEN', 'TRACKING_INTERVAL']
        for key in required:
            if not hasattr(self, key):
                raise ValueError(f"Missing required config: {key}")

        # Convert numeric values
        self.TRACKING_INTERVAL = int(self.TRACKING_INTERVAL)
        self.HEARTBEAT_INTERVAL = int(getattr(self, 'HEARTBEAT_INTERVAL', 60))
        self.BUFFER_MAX_SIZE = int(getattr(self, 'BUFFER_MAX_SIZE', 1000))
        self.MAX_GPS_ACCURACY = int(getattr(self, 'MAX_GPS_ACCURACY', 50))  # 50 meters
        self.API_RETRY_COUNT = int(getattr(self, 'API_RETRY_COUNT', 3))
        self.API_RETRY_DELAY = int(getattr(self, 'API_RETRY_DELAY', 5))

# ==============================================================================
# LOGGING
# ==============================================================================

def setup_logging():
    """Configure comprehensive logging"""
    log_dir = Path('logs')
    log_dir.mkdir(exist_ok=True)

    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s [%(levelname)s] %(message)s',
        handlers=[
            logging.FileHandler(log_dir / 'tracker.log'),
            logging.StreamHandler(sys.stdout)
        ]
    )
    return logging.getLogger('mufassah')

logger = setup_logging()

# ==============================================================================
# SYSTEM MONITORING
# ==============================================================================

class SystemMonitor:
    """Monitor system health metrics (battery, signal, etc.)"""

    def __init__(self):
        self.battery_cache = None
        self.battery_cache_time = 0
        self.signal_cache = None
        self.signal_cache_time = 0
        self.cache_duration = 30  # Cache for 30 seconds

    def get_battery_level(self):
        """Get battery level from Raspberry Pi"""
        # Check cache first
        current_time = time.time()
        if self.battery_cache and (current_time - self.battery_cache_time) < self.cache_duration:
            return self.battery_cache

        try:
            # Try to read from various possible locations
            battery_paths = [
                '/sys/class/power_supply/battery/capacity',
                '/sys/class/power_supply/BAT0/capacity',
                '/sys/class/power_supply/BAT1/capacity',
            ]

            for path in battery_paths:
                if os.path.exists(path):
                    with open(path, 'r') as f:
                        level = int(f.read().strip())
                        self.battery_cache = level
                        self.battery_cache_time = current_time
                        logger.debug(f"Battery level: {level}%")
                        return level

            # If no battery file found, try using vcgencmd (Raspberry Pi specific)
            result = subprocess.run(
                ['vcgencmd', 'measure_volts', 'core'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if result.returncode == 0:
                # Parse voltage to estimate battery (very rough approximation)
                # This is a fallback - actual battery reading would require hardware-specific code
                self.battery_cache = 100  # Assume 100% if running on mains
                self.battery_cache_time = current_time
                return 100

        except Exception as e:
            logger.debug(f"Could not read battery level: {e}")

        # Default to 100% if we can't read it
        self.battery_cache = 100
        self.battery_cache_time = current_time
        return 100

    def get_signal_strength(self):
        """Get network signal strength"""
        # Check cache first
        current_time = time.time()
        if self.signal_cache and (current_time - self.signal_cache_time) < self.cache_duration:
            return self.signal_cache

        try:
            # Try WiFi signal strength
            result = subprocess.run(
                ['nmcli', '-t', '-f', 'SIGNAL', 'dev', 'wifi'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if result.returncode == 0:
                lines = result.stdout.strip().split('\n')
                if lines and lines[0]:
                    strength = int(lines[0])
                    self.signal_cache = strength
                    self.signal_cache_time = current_time
                    logger.debug(f"WiFi signal: {strength}%")
                    return strength

            # Try using iwconfig if nmcli fails
            result = subprocess.run(
                ['iwconfig'],
                capture_output=True,
                text=True,
                timeout=5,
                stderr=subprocess.DEVNULL
            )
            if result.returncode == 0:
                # Parse signal level from iwconfig output
                for line in result.stdout.split('\n'):
                    if 'Signal level' in line:
                        # Extract dBm value and convert to percentage
                        import re
                        match = re.search(r'Signal level=(-?\d+) dBm', line)
                        if match:
                            dbm = int(match.group(1))
                            # Convert dBm to percentage (rough approximation)
                            # -30 dBm = 100%, -90 dBm = 0%
                            percentage = max(0, min(100, int((dbm + 90) * 100 / 60)))
                            self.signal_cache = percentage
                            self.signal_cache_time = current_time
                            logger.debug(f"Signal strength: {percentage}%")
                            return percentage

        except Exception as e:
            logger.debug(f"Could not read signal strength: {e}")

        # Default to 80% if we can't read it
        self.signal_cache = 80
        self.signal_cache_time = current_time
        return 80

# ==============================================================================
# NETWORK CONNECTIVITY
# ==============================================================================

class ConnectivityChecker:
    """Check network connectivity before API calls"""

    def __init__(self, api_base_url):
        self.api_base_url = api_base_url
        self.hostname = urlparse(api_base_url).hostname
        self.last_check = False
        self.last_check_time = 0
        self.cache_duration = 10  # Cache for 10 seconds

    def is_online(self):
        """Check if we can reach the API server"""
        current_time = time.time()

        # Return cached result if recent
        if (current_time - self.last_check_time) < self.cache_duration:
            return self.last_check

        try:
            # Try to resolve hostname
            socket.gethostbyname(self.hostname)

            # Try to connect to HTTP port
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(3)
            parsed = urlparse(self.api_base_url)
            
            if parsed.port:
                port = parsed.port
            else:
                port = 443 if parsed.scheme == 'https' else 80

            result = sock.connect_ex((self.hostname, port))
            sock.close()

            self.last_check = (result == 0)
            self.last_check_time = current_time

            if self.last_check:
                logger.debug(f"✓ Online: {self.hostname}")
            else:
                logger.warning(f"✗ Offline: Cannot reach {self.hostname}")

            return self.last_check

        except Exception as e:
            logger.debug(f"Connectivity check failed: {e}")
            self.last_check = False
            self.last_check_time = current_time
            return False

# ==============================================================================
# OFFLINE BUFFER (SQLite)
# ==============================================================================

class Buffer:
    """SQLite buffer for offline location storage"""

    def __init__(self, db_path='buffer.db'):
        self.db_path = db_path
        self.init_db()

    def init_db(self):
        """Create buffer database"""
        conn = sqlite3.connect(self.db_path)
        conn.execute('''
            CREATE TABLE IF NOT EXISTS locations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                altitude REAL,
                speed REAL,
                heading REAL,
                accuracy REAL,
                battery_level INTEGER,
                signal_strength INTEGER,
                recorded_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        conn.commit()
        conn.close()

    def add_location(self, location):
        """Add location to buffer"""
        conn = sqlite3.connect(self.db_path)
        conn.execute('''
            INSERT INTO locations (latitude, longitude, altitude, speed, heading, accuracy,
                                  battery_level, signal_strength, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            location['latitude'],
            location['longitude'],
            location.get('altitude'),
            location.get('speed'),
            location.get('heading'),
            location.get('accuracy'),
            location.get('battery_level'),
            location.get('signal_strength'),
            location['recorded_at']
        ))
        conn.commit()

        # Enforce size limit
        count = conn.execute('SELECT COUNT(*) FROM locations').fetchone()[0]
        if count > config.BUFFER_MAX_SIZE:
            conn.execute('DELETE FROM locations WHERE id IN (SELECT id FROM locations ORDER BY created_at LIMIT ?)',
                        (count - config.BUFFER_MAX_SIZE,))
            conn.commit()

        conn.close()
        logger.debug(f"Buffered location (total: {count})")

    def get_locations(self, limit=100):
        """Get buffered locations"""
        conn = sqlite3.connect(self.db_path)
        locations = conn.execute('''
            SELECT id, latitude, longitude, altitude, speed, heading, accuracy,
                   battery_level, signal_strength, recorded_at
            FROM locations
            ORDER BY created_at
            LIMIT ?
        ''', (limit,)).fetchall()
        conn.close()
        return locations

    def delete_locations(self, ids):
        """Delete synced locations"""
        conn = sqlite3.connect(self.db_path)
        conn.execute(f'DELETE FROM locations WHERE id IN ({",".join(["?"]*len(ids))})', ids)
        conn.commit()
        conn.close()

    def count(self):
        """Get buffer count"""
        conn = sqlite3.connect(self.db_path)
        count = conn.execute('SELECT COUNT(*) FROM locations').fetchone()[0]
        conn.close()
        return count

# ==============================================================================
# API CLIENT
# ==============================================================================

class APIClient:
    """HTTP client for backend API with retry logic"""

    def __init__(self, config, system_monitor, connectivity_checker):
        self.config = config
        self.base_url = config.API_BASE_URL
        self.system_monitor = system_monitor
        self.connectivity = connectivity_checker
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {config.MODULE_ID}:{config.API_TOKEN}',
            'Content-Type': 'application/json',
            'User-Agent': 'MufassahTracker/1.0'
        })

    def send_location(self, location):
        """Send single location update with retry logic"""
        # Check connectivity first
        if not self.connectivity.is_online():
            logger.warning("✗ Offline - skipping API call")
            return False

        # Retry logic
        for attempt in range(self.config.API_RETRY_COUNT):
            try:
                payload = {
                    'latitude': location['latitude'],
                    'longitude': location['longitude'],
                    'altitude': location.get('altitude'),
                    'speed': location.get('speed', 0),
                    'heading': location.get('heading', 0),
                    'accuracy': location.get('accuracy'),
                    'battery_level': location.get('battery_level', self.system_monitor.get_battery_level()),
                    'signal_strength': location.get('signal_strength', self.system_monitor.get_signal_strength()),
                    'recorded_at': location['recorded_at']
                }

                response = self.session.post(
                    f'{self.base_url}/location',
                    json=payload,
                    timeout=10
                )
                response.raise_for_status()
                logger.info(f"✓ Location sent: {location['latitude']:.6f}, {location['longitude']:.6f}")
                return True

            except requests.RequestException as e:
                if attempt < self.config.API_RETRY_COUNT - 1:
                    logger.warning(f"Retry {attempt + 1}/{self.config.API_RETRY_COUNT}: {e}")
                    time.sleep(self.config.API_RETRY_DELAY)
                else:
                    logger.error(f"✗ API error after {self.config.API_RETRY_COUNT} attempts: {e}")

        return False

    def send_batch_locations(self, locations):
        """Send batch location updates with retry logic"""
        # Check connectivity first
        if not self.connectivity.is_online():
            logger.warning("✗ Offline - skipping batch upload")
            return False

        # Retry logic
        for attempt in range(self.config.API_RETRY_COUNT):
            try:
                payload = {
                    'locations': [
                        {
                            'latitude': loc[1],
                            'longitude': loc[2],
                            'altitude': loc[3],
                            'speed': loc[4],
                            'heading': loc[5],
                            'accuracy': loc[6],
                            'recorded_at': loc[9]
                        }
                        for loc in locations
                    ]
                }

                response = self.session.post(
                    f'{self.base_url}/locations/batch',
                    json=payload,
                    timeout=30
                )
                response.raise_for_status()
                return True

            except requests.RequestException as e:
                if attempt < self.config.API_RETRY_COUNT - 1:
                    logger.warning(f"Batch retry {attempt + 1}/{self.config.API_RETRY_COUNT}: {e}")
                    time.sleep(self.config.API_RETRY_DELAY)
                else:
                    logger.error(f"✗ Batch upload error after {self.config.API_RETRY_COUNT} attempts: {e}")

        return False

    def send_heartbeat(self):
        """Send heartbeat and get config"""
        if not self.connectivity.is_online():
            return None

        try:
            response = self.session.post(
                f'{self.base_url}/heartbeat',
                json={
                    'battery_level': self.system_monitor.get_battery_level(),
                    'signal_strength': self.system_monitor.get_signal_strength(),
                    'firmware_version': '1.0.0'
                },
                timeout=10
            )
            response.raise_for_status()
            return response.json()
        except requests.RequestException as e:
            logger.error(f"Heartbeat error: {e}")
            return None

# ==============================================================================
# GPS READER
# ==============================================================================

class GPSReader:
    """Read GPS data from gpsd daemon with accuracy validation"""

    def __init__(self, host='localhost', port=2947, max_accuracy=50):
        self.host = host
        self.port = port
        self.max_accuracy = max_accuracy
        self.connect()

    def connect(self):
        """Connect to gpsd"""
        try:
            import gpsd
            gpsd.connect(host=self.host, port=self.port)
            logger.info(f"✓ Connected to gpsd at {self.host}:{self.port}")
        except ImportError:
            logger.error("gpsd-py3 not installed. Run: pip install gpsd-py3")
            raise
        except Exception as e:
            logger.error(f"✗ Cannot connect to gpsd: {e}")
            logger.error("Make sure gpsd is running: sudo systemctl start gpsd")
            raise

    def validate_accuracy(self, location):
        """Validate GPS accuracy is within acceptable range"""
        accuracy = location.get('accuracy')
        if accuracy is None:
            # No accuracy data - accept it
            return True

        if accuracy > self.max_accuracy:
            logger.warning(f"✗ GPS accuracy too poor: {accuracy:.1f}m (max: {self.max_accuracy}m)")
            return False

        logger.debug(f"✓ GPS accuracy acceptable: {accuracy:.1f}m")
        return True

    def get_location(self, timeout=120):
        """Get current GPS location with timeout and accuracy validation"""
        import gpsd

        start = time.time()
        attempt_count = 0
        max_attempts = 5  # Try up to 5 times to get accurate fix

        while time.time() - start < timeout and attempt_count < max_attempts:
            try:
                packet = gpsd.get_current()

                if packet.mode >= 2:  # 2D or 3D fix
                    location = {
                        'latitude': packet.lat,
                        'longitude': packet.lon,
                        'altitude': packet.alt if hasattr(packet, 'alt') else None,
                        'speed': packet.hspeed if hasattr(packet, 'hspeed') else 0,
                        'heading': packet.track if hasattr(packet, 'track') else 0,
                        'accuracy': packet.position_error() if hasattr(packet, 'position_error') else None,
                        'recorded_at': datetime.now().isoformat()
                    }

                    # Validate accuracy
                    if self.validate_accuracy(location):
                        return location
                    else:
                        # Accuracy too poor, wait and try again
                        attempt_count += 1
                        logger.warning(f"Poor GPS fix (attempt {attempt_count}/{max_attempts}), waiting for better fix...")
                        time.sleep(5)
                        continue

            except Exception as e:
                logger.warning(f"GPS read error: {e}")

            time.sleep(1)

        if attempt_count >= max_attempts:
            logger.warning(f"Could not get accurate GPS fix after {max_attempts} attempts")

        logger.error("GPS fix timeout")
        return None

# ==============================================================================
# MAIN TRACKER SERVICE
# ==============================================================================

class TrackerService:
    """Main tracking service with robust error handling"""

    def __init__(self, config):
        self.config = config
        self.system_monitor = SystemMonitor()
        self.connectivity = ConnectivityChecker(config.API_BASE_URL)
        self.gps = GPSReader(max_accuracy=config.MAX_GPS_ACCURACY)
        self.api = APIClient(config, self.system_monitor, self.connectivity)
        self.buffer = Buffer()
        self.running = False
        self.stop_event = Event()
        self.current_tracking_interval = config.TRACKING_INTERVAL

        # Setup signal handlers for graceful shutdown
        signal.signal(signal.SIGTERM, self.shutdown)
        signal.signal(signal.SIGINT, self.shutdown)

    def shutdown(self, signum, frame):
        """Handle shutdown signals"""
        logger.info("Shutting down gracefully...")
        self.running = False
        self.stop_event.set()

    def update_tracking_interval(self, server_interval):
        """Update tracking interval based on server configuration"""
        if server_interval and server_interval != self.current_tracking_interval:
            old_interval = self.current_tracking_interval
            self.current_tracking_interval = server_interval
            logger.info(f"Tracking interval updated: {old_interval}s → {server_interval}s")

    def sync_buffer(self):
        """Sync buffered locations to server"""
        count = self.buffer.count()
        if count == 0:
            return

        logger.info(f"Syncing {count} buffered locations...")

        while self.buffer.count() > 0 and self.running:
            locations = self.buffer.get_locations(limit=100)
            if not locations:
                break

            if self.api.send_batch_locations(locations):
                ids = [loc[0] for loc in locations]
                self.buffer.delete_locations(ids)
                logger.info(f"✓ Synced {len(ids)} locations")
            else:
                logger.error("✗ Sync failed, will retry later")
                break

    def heartbeat_loop(self):
        """Send periodic heartbeats and update configuration"""
        while self.running:
            try:
                result = self.api.send_heartbeat()
                if result:
                    logger.debug("Heartbeat sent")

                    # Update tracking interval from server
                    server_data = result.get('data', {})
                    server_interval = server_data.get('tracking_interval')
                    if server_interval:
                        self.update_tracking_interval(server_interval)

            except Exception as e:
                logger.error(f"Heartbeat error: {e}")

            self.stop_event.wait(self.config.HEARTBEAT_INTERVAL)

    def run(self):
        """Main tracking loop"""
        logger.info("=== Mufassah Tracker Starting ===")
        logger.info(f"Tracking interval: {self.current_tracking_interval}s")
        logger.info(f"Max GPS accuracy: {self.config.MAX_GPS_ACCURACY}m")
        logger.info(f"API retry count: {self.config.API_RETRY_COUNT}")

        self.running = True

        # Start heartbeat thread
        heartbeat_thread = Thread(target=self.heartbeat_loop, daemon=True)
        heartbeat_thread.start()

        # Sync any existing buffer
        self.sync_buffer()

        # Main tracking loop
        while self.running:
            try:
                # Get GPS location (with accuracy validation)
                location = self.gps.get_location(timeout=120)

                if not location:
                    logger.warning("No GPS fix, retrying...")
                    self.stop_event.wait(30)
                    continue

                # Add battery and signal data
                location['battery_level'] = self.system_monitor.get_battery_level()
                location['signal_strength'] = self.system_monitor.get_signal_strength()

                # Try to send to API (with retry logic)
                success = self.api.send_location(location)

                if success:
                    # Location sent, sync buffer
                    self.sync_buffer()
                else:
                    # API error after retries, buffer the location
                    self.buffer.add_location(location)
                    logger.warning(f"✗ Buffered location (buffer size: {self.buffer.count()})")

                # Wait for next interval (may be updated dynamically)
                self.stop_event.wait(self.current_tracking_interval)

            except Exception as e:
                logger.error(f"Tracking loop error: {e}")
                self.stop_event.wait(60)  # Wait 1 minute before retry

        logger.info("=== Mufassah Tracker Stopped ===")

# ==============================================================================
# ENTRY POINT
# ==============================================================================

if __name__ == '__main__':
    try:
        # Load configuration
        global config
        config = Config('config.env')

        # Start tracker
        tracker = TrackerService(config)
        tracker.run()

    except Exception as e:
        logger.error(f"Fatal error: {e}")
        sys.exit(1)
