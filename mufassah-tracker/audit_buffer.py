#!/usr/bin/env python3
"""Read-only audit of timestamp offsets in an existing tracker buffer."""

import argparse
import json
import sqlite3
from contextlib import closing
from datetime import datetime
from pathlib import Path


def audit_buffer(path):
    counts = {'total': 0, 'with_offset': 0, 'without_offset': 0, 'invalid': 0}
    # URI mode=ro prevents creating a missing file or modifying existing data.
    uri = Path(path).resolve().as_uri() + '?mode=ro'
    with closing(sqlite3.connect(uri, uri=True)) as connection:
        for (value,) in connection.execute('SELECT recorded_at FROM locations'):
            counts['total'] += 1
            try:
                parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
            except (AttributeError, TypeError, ValueError):
                counts['invalid'] += 1
                continue
            key = 'with_offset' if parsed.utcoffset() is not None else 'without_offset'
            counts[key] += 1
    return counts


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('buffer', help='Path to an existing buffer.db')
    args = parser.parse_args()
    try:
        result = audit_buffer(args.buffer)
    except (OSError, sqlite3.Error) as error:
        parser.exit(2, 'Unable to audit buffer: {}\n'.format(error))
    print(json.dumps(result, sort_keys=True))
    if result['without_offset'] or result['invalid']:
        parser.exit(1, 'Review ambiguous/invalid timestamps before syncing; no data was changed.\n')


if __name__ == '__main__':
    main()
