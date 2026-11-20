# Leenkat Forms — Corresponding Source

This public repository provides the corresponding source code, pinned third-party inputs, licenses, and build tools for **Leenkat Forms 1.0.1**, a WordPress form builder developed by Leenkat.

## Repository contents

- [`leenkat-forms/`](leenkat-forms/) — deployable plugin source, shipped assets and license notices.
- [`vendor-source/`](vendor-source/) — preferred third-party source code and pinned dependencies.
- [`BUILD.md`](BUILD.md) and [`rebuild-source.py`](rebuild-source.py) — build instructions and verification.
- [`asset-sha256.json`](asset-sha256.json) — SHA-256 checksums for 18 rebuilt assets.
- [`COUNTRY-PROVENANCE.md`](COUNTRY-PROVENANCE.md), [`generate_phone_countries.py`](generate_phone_countries.py) and [`country-routing-policy.json`](country-routing-policy.json) — documented country metadata derivation.
- [`provenance/`](provenance/) and [`tools/`](tools/) — upstream pins and supporting tools.

## Build

Follow [`BUILD.md`](BUILD.md) to reproduce vendor assets using pinned dependencies and toolchain versions. The source companion was built and verified locally before publication. Build tools are not required by installed WordPress sites.

## Licensing

Leenkat Forms is licensed GPLv2 or later. Consult [`leenkat-forms/COPYING`](leenkat-forms/COPYING), [`leenkat-forms/licenses/`](leenkat-forms/licenses/), and [`THIRD-PARTY-NOTICES.md`](leenkat-forms/assets/vendor/THIRD-PARTY-NOTICES.md) for third-party terms.

## WordPress.org status

Leenkat Forms **1.0.1 is undergoing WordPress.org plugin review**. The source being public does not mean the plugin has been approved or published in the WordPress Plugin Directory.

Project: https://leenkat.com/projects/leenkat-forms/


## Verified T5 source snapshot — version 1.0.1

This source tree includes the complete T5 runtime and corresponding build inputs.
All 180 files under `leenkat-forms/` match the approved T5 QA package byte for byte.
The plugin version remains 1.0.1. The pinned vendor dependencies, generated assets,
licenses and build procedure are unchanged.

- QA runtime SHA-256: `6c7a7cdbb6e118f8a10618b65ad3f8089386d0d43585f656e56836a2b499449b`
- Source companion SHA-256: `233d8ecb9e66c6b06fc360a36aec6a8c8c1bc6f1446d65117c00f1b9c0f08e6c`
- `T5-RUNTIME-MANIFEST.json` records the exact runtime hashes.
- `BUILD.md`, `COUNTRY-PROVENANCE.md` and `provenance/upstream-pins.json`
  document the preferred vendor source, build procedure, licenses and pinned inputs.
- `leenkat-forms/PRIVATE-STORAGE.txt` documents storage, recovery and operational boundaries.

Publication notes inside the companion build records describe the state when those
archives were created. This repository now contains the T5 source snapshot.
Internal QA evidence, databases, credentials and ZIP archives are not included.
