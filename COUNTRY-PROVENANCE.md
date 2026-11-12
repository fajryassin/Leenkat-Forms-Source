# Phone country data

The country table used at runtime is generated from the following inputs.

| Input | Revision | License |
|---|---|---|
| Territory metadata and calling and national codes | Google libphonenumber `bd0235b04b40a017dd0ec85e48dc9bc752f431aa` | Apache-2.0 |
| Localized names, including 242 English names | country-list `4d8f87526c891a7b110b530eabf910c3f7468ad6` | MIT |
| Ascension Island and Kosovo name overrides, and phone routing behavior | intl-tel-input `e67d9ceff3e169c8b38e653e77b8b18b77d16f48` | MIT |
| Flag selection and images | flag-icons `7.3.2` | MIT |

The generator intersects the licensed metadata, names and flag coverage to
select the 244 supported territories. It takes territory identifiers, calling
codes and national prefixes from libphonenumber. The country names in the
phone bundle match country-list except for the two upstream interface overrides.

The initial phone plugin's 2013 import used three fields from the historic
mledoze/countries snapshot. Those fields were names, country identifiers and
calling codes. The old snapshot is outside the generator inputs and the source
companion. The plugin retains the upstream historical attribution.

The modern MIT phone widget's shared-code precedence and routing behavior are
recorded in `country-routing-policy.json`. Canadian areas are derived from
libphonenumber's fixed-line and mobile patterns. Other North American
Numbering Plan territory areas, Bonaire, Vatican fixed-line and Svalbard
fixed-line prefixes come from its leading-digit patterns. The audit compared
the historical suffixes to the candidate and verified independent derivation
for every matching suffix. Later widget routing rules preserve partial-number
and shared-mobile behavior. The generator sorts the internal raw table using
licensed English labels. The visible widget still sorts by its active locale.

The pinned license texts and libphonenumber metadata were fetched at the named
revisions and matched byte-for-byte against the build inputs.

- [libphonenumber metadata and license](https://github.com/google/libphonenumber/tree/bd0235b04b40a017dd0ec85e48dc9bc752f431aa)
- [country-list data and license](https://github.com/umpirsky/country-list/tree/4d8f87526c891a7b110b530eabf910c3f7468ad6)
- [intl-tel-input source and license](https://github.com/jackocnr/intl-tel-input/tree/e67d9ceff3e169c8b38e653e77b8b18b77d16f48)
