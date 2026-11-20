# Leenkat Forms 1.0.1 T5 source companion

The runtime in leenkat-forms/ is the exact T5 QA runtime. T5-RUNTIME-MANIFEST.json
records every member SHA-256. All 18 generated vendor assets retain their
approved hashes. The pinned preferred upstream sources, dependency lockfiles,
patches, country-data inputs and license notices remain unchanged.

Follow BUILD.md for the already verified pinned vendor build procedure and
tools/country-data.py for the data snapshot check. This remediation changed
readable first-party PHP and JavaScript and operational documentation, with no
dependency upgrade or vendor rebuild.

Source of truth before remediation:
- Submitted QA SHA-256: d4b2cab092ed915429443be30607e054ce42993f2ab25eeb8ace858b1283ec61
- Matching source SHA-256: abfbda342abb8dcf82ffca5028160cb6f0615ee34dc17bd77f8a142b12d7f754
- Matched source commit: b1b6338615f71a647eff70ed50cc8380fb4cf417

The existing public repository and pinned build-input revision remain referenced
in SOURCE-BUILD.txt. This T5 companion is prepared locally for an approved sync.
No claim is made that the changed T5 runtime has already been published.
Historical T3 records retain their historical archive hashes.

The plugin version remains 1.0.1. See PRIVATE-STORAGE.txt and readme.txt for
current requirements, migration/rollback and operational boundaries.
