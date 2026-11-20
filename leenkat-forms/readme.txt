=== Leenkat Forms ===
Contributors: fajryassin
Tags: forms, form builder
Requires at least: 7.1
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build WordPress forms with conditional logic, multi-step pages, entry management, email notifications, and private file uploads.

== Description ==

= Source code and build instructions =

The first-party source is included in this plugin. The pinned third-party build inputs, license notices and build tools are available at https://github.com/fajryassin/Leenkat-Forms-Source.

Build guide: https://github.com/fajryassin/Leenkat-Forms-Source/blob/ee48bfe1dd1d9cd39d3eddbb450eb0c16994a984/BUILD.md
Country-data provenance: https://github.com/fajryassin/Leenkat-Forms-Source/blob/ee48bfe1dd1d9cd39d3eddbb450eb0c16994a984/COUNTRY-PROVENANCE.md


Leenkat Forms is a free WordPress form builder for creating and managing forms with a visual drag-and-drop editor. Build contact and other data-collection forms, configure field validation and conditional logic, and publish forms using supported WordPress editors or shortcodes.

Features include:

* Visual form builder with draft editing and publishing.
* Conditional field logic and multi-step forms with Page Breaks.
* Field validation, choice fields, and form styling controls.
* Entry management, entry filtering, and CSV export.
* Email notifications, conditional notification rules, and confirmation messages or redirects.
* Private file uploads with controlled downloads and expiring email download links.
* Form import and export using the plugin's native JSON format.
* Optional spam protection, including reCAPTCHA, hCaptcha, Cloudflare Turnstile, and Akismet integration.
* Gutenberg, Classic Editor, Elementor, and WPBakery integration.
* Right-to-left (RTL) support and compatibility with WordPress.org language packs.

Leenkat Forms does not currently import forms directly from other form-builder plugins. External anti-spam services are optional and may have separate account, usage, or pricing requirements.

= Hosting and operational requirements =

Requires PHP 8.3 or newer and WordPress 7.1 or newer. Verified environments include WordPress 7.1 and 7.1.4 with MySQL 8.4, and WordPress 7.1 with SQLite Database Integration 3.1.3. Local Apache and Nginx checks cover private-object direct access. Other server, database, cache, and multisite configurations have not been fully verified; multisite is not supported by this release.

Activation requires PHP Sodium, Fileinfo and Mbstring. Private file uploads require a writable local WordPress uploads directory. Files attached to stored entries appear as private Media Library records. New files are authenticated and encrypted as they are written to per-form uploads directories. A deny-all .htaccess is installed before private writes. Apache denies direct access when AuthConfig overrides are enabled; Nginx and servers ignoring .htaccess can return only ciphertext. Authorized WordPress download routes return the original file after verification. Older public Media Library items keep their original visibility.

Back up the WordPress database (which contains the private storage key) and uploads together; protect database backups separately from the public web tree. The encryption key must be restored unchanged when moving the site. Historical registered private files are copied in verified, resumable batches to encrypted uploads objects while their originals and existing download identities are retained. Migration journals use WordPress options. See PRIVATE-STORAGE.txt before relocation or rollback. There is no plaintext fallback. MySQL tables must support transactions (InnoDB); SQLite uses its supported transactional integration.

Configure a reliable WordPress cron mechanism and outgoing email delivery. A successful wp_mail() handoff does not guarantee delivery to the recipient's inbox. Notification processing can retry; external mail transport is not guaranteed to be exactly once.

= Page caching and forms without JavaScript =

Exclude pages containing Leenkat Forms from shared full-page and CDN caches, including caches that serve content before WordPress executes. Purge existing cached copies. Forms without JavaScript require a fresh per-page submission identity; caching a rendered form can cause submissions to fail. Do not cache submission or receipt responses. Modern anti-spam protection requires JavaScript.

= External services and privacy =

External protection services are optional and are configured by a site administrator. Enabling a provider can load its browser script and send visitor network/device information to that provider. On submission, verification tokens and server-side credentials are sent to the selected provider over HTTPS. Secret keys are not intentionally exposed to visitors.

* Google reCAPTCHA: Browser scripts from google.com/recaptcha or recaptcha.net; verification through Google's siteverify service. [Documentation](https://developers.google.com/recaptcha/docs/faq), [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy).
* hCaptcha: Browser script from hcaptcha.com; verification through api.hcaptcha.com. [Terms](https://www.hcaptcha.com/terms), [Privacy](https://www.hcaptcha.com/privacy).
* Cloudflare Turnstile: Browser script from challenges.cloudflare.com; verification through Cloudflare Siteverify. [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/turnstile-privacy-policy/).
* Akismet: Requires the separately installed and configured Akismet plugin and per-form activation. Mapped contact/message values, visitor IP and browser details, same-site referrer information, and site metadata can be sent to Akismet for spam classification. [Terms](https://akismet.com/tos/), [Privacy](https://akismet.com/privacy/).

Country filtering uses bundled local data and does not require a remote country lookup. Administrator-configured remote choice images may cause visitors' browsers to contact the image host.

Site administrators are responsible for informing visitors about enabled providers and handling applicable privacy and consent requirements. Leenkat Forms can retain entries and private files in WordPress. Data is retained on uninstall by default unless the administrator explicitly enables the destructive deletion option. Copies in sent email, backups, and third-party services are outside the plugin's local deletion scope. The plugin includes personal-data erasure support and manual entry export; it does not currently register a WordPress core personal-data exporter.

== Installation ==

1. Confirm the host meets the WordPress/PHP requirements, has Sodium, Fileinfo and Mbstring, and supports writable WordPress uploads for encrypted attachments.
2. Install and activate Leenkat Forms through the WordPress Plugins screen.
3. Open Leenkat Forms, create a form, and configure its fields, notifications, confirmations, and optional spam protection.
4. Publish the form and embed it using the provided shortcode or a supported editor integration.
5. Exclude the page containing the form from shared page/CDN caching. Configure cron and outgoing mail delivery.
6. Test a submission, entry storage, notification delivery, and any enabled upload or CAPTCHA workflow before using the form publicly.

== Frequently Asked Questions ==

= Is Leenkat Forms free? =

Yes. The plugin's included functionality is free. Optional third-party services, such as CAPTCHA providers or Akismet, have their own accounts, terms, quotas, and possible fees.

= Can I build multi-step forms? =

Yes. Use Page Break fields to create multiple steps and configure the available navigation and progress options.

= Are uploaded files publicly accessible? =

New private attachments are stored as authenticated ciphertext under WordPress uploads and have no public plaintext file or thumbnail. WordPress can show the private Media Library record to authorized users. Downloads verify integrity in memory after checking authorization. Verified encrypted compatibility copies allow historical private downloads to follow the current uploads path; originals remain available for rollback. A valid unexpired signed email link authorizes the associated file, so handle those links carefully.

= Can I use forms without JavaScript? =

Native submissions are supported, but pages containing forms must not be served from shared full-page/CDN caches. Some modern anti-spam features require JavaScript.

= Does Leenkat Forms guarantee email delivery? =

No. Delivery depends on WordPress mail configuration, your mail provider, DNS and network conditions. Configure a reliable sender and test delivery.

= Does the plugin support WordPress multisite? =

Multisite is not supported by this release.

= What happens to entries when I uninstall the plugin? =

Data is retained by default. An explicit administrator setting controls destructive deletion on uninstall. Opt-in deletion covers plugin-owned private Media Library records and verified private files. Older public Media Library items, external email copies, and independent backups have separate retention behavior.

= Can I import forms from WPForms or other form plugins? =

Not in version 1.0.1. The included JSON import/export feature is for the plugin's native form format.

== Screenshots ==

1. Visual Form Builder with drag-and-drop fields.
2. Leenkat Forms dashboard and quick navigation.
3. Forms overview with search and management tools.
4. Entries management and submission overview.
5. General settings and form appearance options.
6. Attachment Security and private file management.
7. Form import and export tools.

== Changelog ==

= 1.0.1 =
* Refined administration typography for Arabic and English.
* Improved the All Forms column-options icon alignment.
* Adjusted Settings and Tools spacing and Preview action alignment.
* Showed the Shortcode column by default while respecting saved Screen Options.
* Added Copy Shortcode to the form builder menu.

= 1.0.0 =
* Initial stable release with a visual form builder, conditional logic, multi-step forms, notifications, confirmations, entry management, protected uploads, native JSON import/export, optional spam protection, and bundled Arabic RTL localization.
