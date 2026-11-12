"""Rebuild the exact bundled allocation snapshot from its readable TSV form."""
from pathlib import Path
import hashlib
import ipaddress
import struct

root = Path(__file__).resolve().parents[1]
for version, width in [(4, 4), (6, 16)]:
    rows = []
    source = root / f"vendor-source/country-data/country-v{version}.tsv"
    with source.open(encoding="ascii") as stream:
        assert next(stream).strip() == "range_start\trange_end\tcountry"
        for line in stream:
            first, last, country = line.strip().split("\t")
            start, end = ipaddress.ip_address(first), ipaddress.ip_address(last)
            assert start.version == end.version == version and start <= end
            assert len(country) == 2
            rows.append(start.packed + end.packed + country.encode("ascii"))
    rebuilt = b"LKFC" + bytes([1, width]) + struct.pack(">I", len(rows)) + b"".join(rows)
    expected = root / f"leenkat-forms/assets/data/country-v{version}.dat"
    assert rebuilt == expected.read_bytes(), f"Country snapshot differs for IPv{version}"
    print(f"IPv{version}: {len(rows)} rows, SHA-256 {hashlib.sha256(rebuilt).hexdigest()}")
