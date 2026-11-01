"""Timezone regressions; run without network access or GPS hardware."""
import hashlib
import importlib.util
import logging
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import closing
from datetime import datetime
from types import SimpleNamespace
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]


def load_module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


# Stub only external hardware/HTTP dependencies. Exercise the real GPS reader,
# SQLite buffer and API payload builders without starting the daemon or logging.
with patch.dict(sys.modules, {'requests': Mock()}), \
        patch.object(Path, 'mkdir'), \
        patch('logging.FileHandler', return_value=logging.NullHandler()), \
        patch('logging.basicConfig'):
    tracker = load_module('tracker_timezone_test', 'tracker.py')
tracker.logger = Mock()
audit = load_module('audit_timezone_test', 'audit_buffer.py')


class FrozenDateTime(datetime):
    @classmethod
    def now(cls, tz=None):
        return datetime.fromtimestamp(1768428900, tz)  # Jan 14 2026 22:15 UTC


class TrackerTimezoneTests(unittest.TestCase):
    def test_gps_buffer_and_single_batch_payloads_keep_explicit_utc_on_any_host(self):
        original = os.environ.get('TZ')
        packet = SimpleNamespace(mode=3, lat=-13.9, lon=33.8, alt=1000,
                                 hspeed=0, track=90, position_error=lambda: 5)
        gpsd = SimpleNamespace(connect=Mock(), get_current=Mock(return_value=packet))
        try:
            for host_timezone in ['UTC', 'Africa/Blantyre', 'America/New_York', 'Asia/Tokyo']:
                with self.subTest(host_timezone=host_timezone), \
                        patch.dict(sys.modules, {'gpsd': gpsd}), \
                        patch.object(tracker, 'datetime', FrozenDateTime), \
                        tempfile.TemporaryDirectory() as directory:
                    os.environ['TZ'] = host_timezone
                    time.tzset()
                    location = tracker.GPSReader().get_location()
                    expected = '2026-01-14T22:15:00+00:00'
                    self.assertEqual(location['recorded_at'], expected)
                    self.assertEqual(datetime.fromisoformat(expected).utcoffset().total_seconds(), 0)
                    tracker.config = SimpleNamespace(BUFFER_MAX_SIZE=1000)
                    buffer = tracker.Buffer(str(Path(directory) / 'buffer.db'))
                    buffer.add_location(location)
                    rows = buffer.get_locations()
                    self.assertEqual(rows[0][9], expected)
                    config = SimpleNamespace(API_BASE_URL='https://example.test', MODULE_ID='test', API_TOKEN='test')
                    client = tracker.APIClient(config, Mock(), SimpleNamespace(is_online=lambda: True))
                    client._post_with_retry = Mock(return_value=True)
                    self.assertTrue(client.send_location(location))
                    self.assertEqual(client._post_with_retry.call_args.args[1]['recorded_at'], expected)
                    self.assertTrue(client.send_batch_locations(rows))
                    self.assertEqual(client._post_with_retry.call_args.args[1]['locations'][0]['recorded_at'], expected)
        finally:
            if original is None:
                os.environ.pop('TZ', None)
            else:
                os.environ['TZ'] = original
            time.tzset()


class BufferAuditTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / 'buffer.db'

    def create_buffer(self, timestamps):
        with closing(sqlite3.connect(self.path)) as connection:
            connection.execute('CREATE TABLE locations (recorded_at TEXT)')
            connection.executemany('INSERT INTO locations VALUES (?)', [(value,) for value in timestamps])
            connection.commit()

    def cli(self):
        return subprocess.run([sys.executable, str(ROOT / 'audit_buffer.py'), str(self.path)],
                              capture_output=True, text=True, check=False)

    def test_audit_identifies_ambiguous_and_invalid_rows_without_modifying_data(self):
        self.create_buffer(['2026-01-14T22:15:00+00:00', '2026-01-14T22:15:00Z',
                            '2026-01-15T00:15:00+02:00', '2026-01-15T00:15:00', 'invalid', None])
        before = hashlib.sha256(self.path.read_bytes()).hexdigest()
        self.assertEqual(audit.audit_buffer(self.path), {
            'total': 6, 'with_offset': 3, 'without_offset': 1, 'invalid': 2,
        })
        result = self.cli()
        self.assertEqual(result.returncode, 1)
        self.assertIn('no data was changed', result.stderr)
        self.assertEqual(hashlib.sha256(self.path.read_bytes()).hexdigest(), before)

    def test_cli_succeeds_for_qualified_timestamps(self):
        self.create_buffer(['2026-01-14T22:15:00+00:00', '2026-01-15T00:15:00+02:00'])
        self.assertEqual(self.cli().returncode, 0)

    def test_missing_buffer_is_not_created(self):
        self.assertEqual(self.cli().returncode, 2)
        self.assertFalse(self.path.exists())

    def test_wrong_database_is_not_modified(self):
        with closing(sqlite3.connect(self.path)) as connection:
            connection.execute('CREATE TABLE other (value TEXT)')
            connection.commit()
        before = self.path.read_bytes()
        self.assertEqual(self.cli().returncode, 2)
        self.assertEqual(self.path.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
