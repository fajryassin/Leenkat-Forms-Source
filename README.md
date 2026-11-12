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
