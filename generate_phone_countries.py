"""Generate the country records from the pinned licensed inputs."""
import argparse
import hashlib
import json
import re
import unicodedata
import xml.etree.ElementTree as ET
from pathlib import Path

METADATA_SHA256 = "8f32a919e7f699457bfe91279e1eec081bdf3cbd156f97011e2ec66c07dc6c83"
ASCENSION_FLAG = {"ac": "sh-ac"}


def labels(path):
    return {iso: json.loads(value) for iso, value in re.findall(
        r'^\s{2}([a-z]{2}): ("(?:[^"\\]|\\.)*"),$', path.read_text(encoding="utf-8"), re.M
    )}


def territories(phone):
    metadata = phone / "third_party/libphonenumber/resources/PhoneNumberMetadata.xml"
    if hashlib.sha256(metadata.read_bytes()).hexdigest() != METADATA_SHA256:
        raise ValueError("Unexpected libphonenumber metadata revision")
    return {node.attrib["id"].lower(): node for node in ET.parse(metadata).findall(".//territory")
            if re.fullmatch(r"[A-Z]{2}", node.attrib["id"])}


def canadian_areas(territory):
    # The pinned metadata expresses Canadian fixed/mobile areas in the first
    # three digits, followed by a shared exchange-and-subscriber-number pattern.
    patterns = set()
    for kind in ("fixedLine", "mobile"):
        raw = "".join(territory.findtext(f"{kind}/nationalNumberPattern").split())
        suffix = r"[2-9]\d{6}"
        if not raw.endswith(suffix):
            raise ValueError("Review the Canadian metadata format")
        patterns.add(re.compile(raw.removesuffix(suffix)))
    return [f"{number:03d}" for number in range(1000)
            if any(pattern.fullmatch(f"{number:03d}") for pattern in patterns)]


def leading_areas(territory, width):
    expression = re.compile(territory.attrib["leadingDigits"])
    return [f"{number:0{width}d}" for number in range(10**width)
            if expression.fullmatch(f"{number:0{width}d}")]


def make_rows(phone, policy):
    by_region = territories(phone)
    locale = phone / "src/js/intl-tel-input/i18n/en"
    names = labels(locale / "countries.ts") | labels(locale / "interface.ts")
    flags = phone / "third_party/flag-icons/flags/4x3"
    supported = {iso for iso in by_region.keys() & names.keys()
                 if (flags / (ASCENSION_FLAG.get(iso, iso) + ".svg")).is_file()}
    if len(supported) != 244 or set(policy) - supported:
        raise ValueError("Unexpected supported territory set")
    rows = []
    for iso in supported:
        territory = by_region[iso]
        policy_entry = policy.get(iso, {})
        priority = policy_entry.get("priority", 0)
        areas = policy_entry.get("areas")
        derivation = policy_entry.get("derive")
        if derivation == "canada":
            areas = canadian_areas(territory)
        elif derivation == "nanp-leading":
            areas = leading_areas(territory, 3)
        elif derivation == "bonaire-leading":
            areas = leading_areas(territory, 1)
        elif derivation == "vatican":
            areas = leading_areas(territory, 5) + ["3"]  # Shared Italian mobile selection.
        elif derivation == "svalbard":
            areas = ["4"] + leading_areas(territory, 2) + ["9"]  # Shared Norwegian mobile selection.
        elif derivation is not None:
            raise ValueError(f"Unknown routing derivation for {iso}")
        if derivation and not areas:
            raise ValueError(f"No area codes derived for {iso}")
        row = [iso, territory.attrib["countryCode"], priority, areas, territory.get("nationalPrefix")]
        while len(row) > 2 and row[-1] in (None, 0):
            row.pop()
        rows.append(row)
    # Generate ordering from the licensed English labels. The widget also sorts
    # each visible dropdown using its active locale and countryOrder option.
    return sorted(rows, key=lambda row: (
        unicodedata.normalize("NFKD", names[row[0]]).encode("ascii", "ignore").decode().casefold(),
        row[0],
    ))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("phone", type=Path)
    parser.add_argument("policy", type=Path)
    args = parser.parse_args()
    source = args.phone / "src/js/intl-tel-input/data.ts"
    template = source.read_text(encoding="utf-8")
    start = template.index("export const rawCountryData = ")
    end = template.index("] as const;", start) + len("] as const;")
    rows = make_rows(args.phone, json.loads(args.policy.read_text(encoding="utf-8")))
    declaration = "export const rawCountryData = [\n" + "".join(
        "  " + json.dumps(row, ensure_ascii=False) + ",\n" for row in rows
    ) + "] as const;"
    source.write_text(
        "// Territory identifiers, calling codes and national prefixes are generated from\n"
        "// pinned Google libphonenumber metadata (Apache-2.0). See the source build guide.\n"
        + declaration + template[end:], encoding="utf-8", newline="\n"
    )


if __name__ == "__main__":
    main()
