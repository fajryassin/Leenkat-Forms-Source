Third-party runtime notices — Leenkat Forms public-release preparation

The first-party plugin is licensed GPL-2.0-or-later. See root COPYING.
This grant does not erase third-party conditions. Apache-2.0 components are
compatible through the GPLv3 option, not a claim of Apache compatibility
with GPLv2-only. Apache and GPLv3 texts are in licenses/.

Choices.js 11.1.0
Copyright (c) 2016 Josh Johnson, MIT.
Source https://github.com/Choices-js/Choices
See assets/vendor/choices/LICENSE. Version-pinned source and build inputs
are recorded outside the runtime package in build/source-lock.json.

flatpickr 4.6.9 distribution
Copyright (c) 2017 Gregory Petrosyan, MIT.
Source https://github.com/flatpickr/flatpickr
The shipped minified JS/CSS match npm 4.6.9 after trailing-newline
normalization. Existing NOTICE and upstream LICENSE are retained.

intl-tel-input 25.12.6
Copyright (c) 2014-2016 Jack O'Connor, MIT.
Source https://github.com/jackocnr/intl-tel-input
See the original MIT LICENSE and licenses/intl-tel-input-ATTRIBUTIONS.txt.
The shipped with-utils build includes compiled utilities. Their source
inputs include Closure Library 20230802.0.0 (Apache-2.0), Protocol Buffer
copyright 2008 Google Inc. and libphonenumber at the pinned git submodule
commit recorded in build/source-lock.json (Apache-2.0).
See licenses/Apache-2.0.txt and licenses/Closure-Apache-2.0.txt.
Original compiled copyright headers remain untouched.

Flag sprites
flag-icons 7.3.2, Copyright (c) 2013 Panayiotis Lipiridis, MIT.
See licenses/flag-icons-MIT.txt. The pinned source build generates sprite
assets from its supported 4x3 flags. Globe assets originate in the pinned
intl-tel-input source tree under its MIT grant.

Country-name translation inputs
umpirsky/country-list at the pinned submodule revision, MIT.
Copyright (c) Sasa Stamenkovic. See licenses/country-list-MIT.txt for the
original exact copyright spelling and grant.
This package generates the phone territory identifiers, calling codes and
national prefixes from the Apache-2.0 libphonenumber metadata at
bd0235b04b40a017dd0ec85e48dc9bc752f431aa. The source companion includes the
generator and MIT phone routing policy. The historical mledoze/countries
attribution is retained verbatim in licenses/intl-tel-input-ATTRIBUTIONS.txt
as project history.

Country allocation snapshot
https://iptoasn.com/, Frank Denis, PDDL 1.0.
See assets/data/LICENSE-country.txt, assets/data/country-source.json and
licenses/PDDL-1.0.txt. Human-readable TSV snapshots and a binary rebuild
tool are maintained outside the runtime directory.

WPForms-derived presets and provider icons
The five provider/custom-CAPTCHA SVGs match the public WPForms Lite
2.0.2.2 tag byte-for-byte. Country/state presets identify their WPForms
Lite derivation in src/Forms/address-data.php.
WPForms contributors; GPLv2-or-later as declared in the exact tag readme.
https://plugins.svn.wordpress.org/wpforms-lite/tags/2.0.2.2/
Root COPYING provides the GPLv2 text. Source copies/provenance are
maintained outside the runtime directory. Provider marks identify
integrations and do not imply endorsement or a trademark license.

Official Leenkat logo, none.svg and choice-placeholder.svg are retained
as approved first-party assets. This local preparation does not independently
establish the owner's copyright or trademark title.
