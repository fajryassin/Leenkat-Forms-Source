# Rebuild Leenkat Forms 1.0.1 vendor assets

The plugin code is in `leenkat-forms/`. The pinned source inputs and npm
lockfiles are in `vendor-source/`. The original source commits and package
integrities are in `provenance/upstream-pins.json`. License notices are included
with the plugin and the corresponding source components.

The verified build used Windows x64, Node 22.23.2, the npm lockfile installations,
Python 3 and Eclipse Temurin Java 17.0.21+9. Run the following from this directory
with those tools available on PATH.

```text
python rebuild-source.py ./clean-build
python tools/country-data.py
```

Use a new destination. The script copies the source, installs exact locked
dependencies, compiles Choices, flatpickr and intl-tel-input, and checks all
18 resulting runtime assets against `asset-sha256.json`. The second command
rebuilds the readable IPv4 and IPv6 snapshots in memory and verifies the packaged
binary bytes. None of the build tools is required by the installed plugin.

The Choices bundle is built with the locked Rollup, Sass and CSS toolchain.
Its unrelated `iltorb` installer is skipped. The final copyright banner year
is pinned to 2025, matching Choices.js 11.1.0. Flatpickr uses version 4.6.9
in the temporary build metadata to match its npm banner. Its installed JS
and CSS retain the documented final LF, and the CSS retains Leenkat's
calendar box-sizing rule.

The phone build compiles the checked-in libphonenumber source and metadata
with the lock-pinned Java Closure Compiler. The patch in
`vendor-source/phone/grunt/closure-compiler.js` expands the upstream exclusions
before invoking the compiler so Windows does not pick up competing test metadata.
The builder copies the compiled utilities into the importer path and normalizes
the esbuild source-location comment. It generates the country records with
`generate_phone_countries.py`, uses the original sprite generator with the
pinned flag SVGs, and builds the CSS with Sass. On Windows x64 the script obtains
the exact sharp 0.33.5 native artifact with a pinned SHA-512 integrity check
because the upstream npm lockfile only records the original Darwin native package.

The tested staging workflow ran these build steps individually. The wrapper
contains the same commands and checks for a new build directory.
See `COUNTRY-PROVENANCE.md` for the input and compatibility policy.
