(function () {
    'use strict';
    const __ = window.wp.i18n.__;
    const arabic = () => /^ar(?:[_-]|$)/i.test(window.leenfoLocale?.locale || document.documentElement.lang || '');
    const countryNames = () => {
        if (!arabic()) return {};
        const names = Object.fromEntries(Object.entries(window.leenfoLocale?.countries || {}).map(([code, label]) => [code.toLowerCase(), label]));
        if (typeof Intl.DisplayNames !== 'function') return names;
        const display = new Intl.DisplayNames(['ar'], { type: 'region' });
        for (const country of window.intlTelInput?.getCountryData?.() || []) names[country.iso2] = display.of(country.iso2.toUpperCase());
        return names;
    };
    window.leenfoWidgetI18n = {
        phone() {
            if (!arabic()) return {};
            return { i18n: {
                ...countryNames(),
                selectedCountryAriaLabel: __('Change country, selected ${countryName} (${dialCode})', 'leenkat-forms'),
                noCountrySelected: __('Select country', 'leenkat-forms'),
                countryListAriaLabel: __('List of countries', 'leenkat-forms'),
                searchPlaceholder: __('Search', 'leenkat-forms'),
                clearSearchAriaLabel: __('Clear search', 'leenkat-forms'),
                zeroSearchResults: __('No results found', 'leenkat-forms'),
                oneSearchResult: __('1 result found', 'leenkat-forms'),
                multipleSearchResults: __('${count} results found', 'leenkat-forms'),
            } };
        },
        choices() {
            return {
                loadingText: __('Loading…', 'leenkat-forms'),
                noResultsText: __('No results found', 'leenkat-forms'),
                noChoicesText: __('No choices available', 'leenkat-forms'),
                removeItemLabelText: __('Remove selected item', 'leenkat-forms'),
                searchPlaceholderValue: __('Search choices…', 'leenkat-forms'),
            };
        },
        date() {
            if (!arabic()) return undefined;
            return {
                weekdays: {
                    shorthand: [__('Sun', 'leenkat-forms'), __('Mon', 'leenkat-forms'), __('Tue', 'leenkat-forms'), __('Wed', 'leenkat-forms'), __('Thu', 'leenkat-forms'), __('Fri', 'leenkat-forms'), __('Sat', 'leenkat-forms')],
                    longhand: [__('Sunday', 'leenkat-forms'), __('Monday', 'leenkat-forms'), __('Tuesday', 'leenkat-forms'), __('Wednesday', 'leenkat-forms'), __('Thursday', 'leenkat-forms'), __('Friday', 'leenkat-forms'), __('Saturday', 'leenkat-forms')],
                },
                months: {
                    shorthand: [__('Jan', 'leenkat-forms'), __('Feb', 'leenkat-forms'), __('Mar', 'leenkat-forms'), __('Apr', 'leenkat-forms'), __('May', 'leenkat-forms'), __('Jun', 'leenkat-forms'), __('Jul', 'leenkat-forms'), __('Aug', 'leenkat-forms'), __('Sep', 'leenkat-forms'), __('Oct', 'leenkat-forms'), __('Nov', 'leenkat-forms'), __('Dec', 'leenkat-forms')],
                    longhand: [__('January', 'leenkat-forms'), __('February', 'leenkat-forms'), __('March', 'leenkat-forms'), __('April', 'leenkat-forms'), __('May', 'leenkat-forms'), __('June', 'leenkat-forms'), __('July', 'leenkat-forms'), __('August', 'leenkat-forms'), __('September', 'leenkat-forms'), __('October', 'leenkat-forms'), __('November', 'leenkat-forms'), __('December', 'leenkat-forms')],
                },
                rangeSeparator: __(' to ', 'leenkat-forms'),
                weekAbbreviation: __('Wk', 'leenkat-forms'),
                scrollTitle: __('Scroll to increment', 'leenkat-forms'),
                toggleTitle: __('Click to toggle', 'leenkat-forms'),
                amPM: [__('AM', 'leenkat-forms'), __('PM', 'leenkat-forms')],
                yearAriaLabel: __('Year', 'leenkat-forms'),
                monthAriaLabel: __('Month', 'leenkat-forms'),
                hourAriaLabel: __('Hour', 'leenkat-forms'),
                minuteAriaLabel: __('Minute', 'leenkat-forms'),
                time_24hr: false,
            };
        },
    };
})();
