=== UnionRoll ===
Contributors: mmmdelicious
Tags: union, membership, member access, check-in, qr code
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.26.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A powerful union membership management plugin: member cards and QR event check-in, member-group content access, and Union Rep assignments with routed contact forms.

== Description ==

UnionRoll is a membership management toolkit for unions:

* Member cards with QR codes, events, guest lists and check-in by QR, barcode or phone lookup, with a live dashboard.
* Member Groups: restrict posts, pages, media and protected contracts by bargaining unit, unit number, employer, job title or island; For You page; view-as-member preview.
* Union Reps: each member sees their Union Representative, and the contact form routes to that rep with the Division Director on BCC. Assignments are updated by uploading the Cognito export.

== Changelog ==

= 3.26.0 =
* Rep contact emails are branded HTML: navy UPW header, member details (name, unit and division, best way to reach, email and phone, account details when different), the subject and message in a highlighted box, and a clear reply note.
* The member's copy is a matching email naming their rep and phone, with Reply-To set to the rep.
* Subjects: "Member message from <name>: <subject>" and "Copy of your message to your Union Representative: <subject>".

= 3.25.9 =
* Rep contact form: visible, editable contact fields (contact_name, contact_email, contact_phone) pre-filled from the member's account; phone required when they ask for a call or text, email when they ask for email.
* The rep's email shows the member's chosen contact details and best way to reach them (plus the account details when they differ); Reply-To uses the email they entered.
* Member copy (Elementor Email 2 to [field id="contact_email"]): Reply-To is their rep; goes to the test address in test mode or View-as preview.

= 3.25.8 =
* Union Reps: the rep card's "Send a message" button links to the published page that holds the routed Elementor form, and is hidden until such a page exists (it no longer sends members to the old Cognito page).

= 3.25.7 =
* For You: greets the member by first name ("Welcome, Name") under a small gold "For You" label.

= 3.25.6 =
* Union Reps: staff and admin accounts no longer see a rep card (in For You or [ur_my_rep]); they get a note to use View as member instead.

= 3.25.5 =
* Speed: member-group rules load in one query instead of one per group (27 queries / ~36 ms per logged-in page down to 2).
* Speed: one Elementor element-cache check instead of two.
* Removed the unused "My division" calendar toggle ([ur_my_division]) and its query filter.
* Revisions capped at 10 per post/page (option ur_revisions_to_keep).

= 3.25.4 =
* The 3.25.3 code changes (View-as form testing in forced test mode; staff unit 529 not flagged) actually included; 3.25.3 shipped only the version number.

= 3.25.3 =
* Union Reps: an admin using "View as member" can test the contact form as that member; such messages always go to the test address only, never to reps.
* Preview no longer lists the UPW staff unit (529) as missing a rep.

= 3.25.2 =
* Union Reps: department-based routing uses each member's own department (user meta ur_department, from the roster) before the unit's department; departments with no rep row are extrapolated from units of that department that have a rep; otherwise the Division Director.
* Elementor elements with CSS class ur-logged-in-only / ur-logged-out-only show only to that audience (rep card + routed form for members, Cognito form for visitors).

= 3.25.1 =
* Union Reps: upload the Cognito "Union Representative Assignments" export (.xlsx) as the complete list; reps keyed by email, Division Directors from the DivisionDirectorEmail column, rows with mismatched emails flagged.
* Department-based divisions (Hawaii): department assignments plus an editable unit → department map (starting guess for 30 Hawaii units, to be confirmed).
* New plugin description: a union membership management plugin, not just QR codes.

= 3.25.0 =
* New: Union Reps (UnionRoll → Union Reps). Members see their Union Representative (photo, name, title, phone) from their unit number via [ur_my_rep] and in For You.
* Upload a division's "Unit Assignments" .docx as it arrives; preview every change before Apply; one-click Undo. Import rep names, titles, phones, emails and photos from the Staff page (with preview).
* Routes the Elementor Pro form named "Contact my Union Rep" to the member's rep with the Division Director on BCC, Reply-To the member; routing is decided on the server from the member's own unit. Audit fields (member_name, member_email, member_phone, member_unit, routed_to) are filled from the account when the form has them.
* Test mode (on by default) sends every message only to the test address, showing the real recipients in the subject.

= 3.24.7 =
* For You: headings match the site's Elementor type. Section title is Poppins 600 at 40px (28px on mobile), centred; card titles and the membership card title are Poppins 600 at 25px; body text Poppins 300.

= 3.24.6 =
* For You: protected files are split into "Your contracts" (CBAs, contracts, agreements, contract summaries) and "Letters & documents" (letters, notices, grievances, schedules). Filter: ur_mg_is_contract.
* View as group: now also includes the groups at least 90% of that group's members are in (e.g. EMS also gets BU 10 and Oahu), so the preview matches what a real member sees. The banner names them.

= 3.24.5 =
* For You: "Open my card" button now uses white text on gold like the site's other buttons (hover: white with gold text).

= 3.24.4 =
* For You section restyled to match the site: navy cards, white headings and links (gold on hover), gold "Open my card" button with navy text.

= 3.24.3 =
* Changed: Events (The Events Calendar) are no longer restricted by Member Groups; the Member Groups box is removed from events, calendars are cached normally again (month-view cache and Essential Addons calendar caching no longer overridden).

= 3.24.2 =
* New: For You shows a "Your membership card" banner linking to the page with the member's UnionRoll QR card (auto-detected [mmm_user_qr] page), for every logged-in member.

= 3.24.1 =
* Changed: "See what's for you" and View-as previews go to the page that shows For You (auto-detected, or set under Member Groups Settings) instead of always the home page.

= 3.24.0 =
* New: "View as member" preview for administrators: see the front end exactly as a specific member (or a single group) sees it. Start it from the admin bar or from Check a member; a red banner shows who you are previewing as, with Stop preview. Front end only, never affects wp-admin or the Elementor editor, ends after 2 hours.
* Changed: For You preview now uses View as (the ?ur_preview_user parameter is removed).

= 3.23.0 =
* New: [ur_for_you] shortcode: a "For You" block of the member's contracts, news and upcoming meetings (admins can preview with ?ur_preview_user=ID).
* New: links and contract cards for downloads a logged-in member can't open are hidden (any Elementor widget or post); logged-out visitors still see them and are asked to log in.
* New: friendly "Members of this group only" page inside the site header/footer, via a neutral URL so restricted titles never reach the page head.
* New: [ur_my_division] "All events / My division" toggle for event calendars; My division keeps statewide events.
* Fix: Essential Addons post/event widgets and download links are never served from Elementor's element cache.

= 3.22.1 =
* Fix: a Download Protect file marked "Unrestricted" but tagged with a Member Group now requires login (it was downloadable by logged-out visitors with the link).
* Fix: The Events Calendar month-view cache is turned off while any content has groups, so cached calendars can't show group-only events to everyone.
* Fix: The Events Calendar REST route for a single event respects Member Groups.

= 3.22.0 =
* New: Island rule for Member Groups (tick Hawaii, Kauai, Lanai, Maui, Molokai, Oahu), matched against the member's Island. Works with Any/All, e.g. All rules + BU 1 + Maui = BU 1 members on Maui.
* The member checker on the settings page now shows the member's island.

= 3.21.3 =
* New: Member Groups "Match" option per group: Any rule (rules add up, the default and existing behaviour) or All rules (every filled-in rule must match, e.g. BU 10 AND Adult Corrections Officer). Shown as a column on the Member Groups list.

= 3.21.2 =
* New: Member Groups checkboxes on the WP-Members Download Protect screen (Media > Download Protect), on both Add New and Edit File, plus group labels in its file list.

= 3.21.1 =
* Fix: Elementor element caching could serve one visitor's view of group-only sections (ur-group-<slug> class, [ur_group_only], [wpmem_file]) to everyone; those elements are now rendered per request.

= 3.21.0 =
* New: Member Groups. Tick groups (e.g. BU 1, BU 10, DSI Renal, Retirees) on posts, pages, events and media files, including WP-Members Download Protect files, so only members in those groups can see or download them. Content with no group stays visible to everyone.
* New: Group rules by bargaining unit, unit number (ranges allowed), employer/jurisdiction and job title; member counts per group.
* New: Staff see everything (editors/admins, plus admin-verified accounts on the staff email domain); blocked member statuses get no group access.
* New: Restrict part of a page with the Elementor CSS class ur-group-<slug> or the [ur_group_only] shortcode; Elementor Query ID ur_for_me for a "For You" list.
* New: Lists, search, feeds, REST, prev/next links, menus and the Yoast sitemap all respect groups; settings page with a member access checker.

= 3.20.0 =
* Fix: Phone check-in "not found" now shows a full-size popover instead of tiny inline text

= 3.19.1 =
* Fix: Phone search and phone check-in AJAX handlers were registered to the wrong function names, causing "connection error" on both actions

= 3.19.0 =
* New: All fetch calls now use a 5s timeout — QR/barcode scans queue locally on timeout or dropped connection instead of freezing the scanner ("Queued — will sync when back online"), fixing iPad freezes when internet drops mid-meeting
* New: Phone and DL check-in paths fail fast with a clear error instead of hanging indefinitely

= 3.18.0 =
* Rebrand: Plugin renamed from "Event Check-In" to "UnionRoll" — plugin header, admin menus, page slugs, AJAX actions, nonce keys, and localStorage keys all updated; main file renamed event-checkin.php → union-roll.php

= 3.17.0 =
* Change: Opt-out members are now checked in and recorded (rather than blocked), with a prominent red staff alert on both the QR and phone/DL check-in overlays prompting a conversation about rejoining

= 3.16.11 =
* Fix: Block check-in for members with "Opt Out" status across all entry methods (QR, barcode, phone, DL) — shows red "Please ask for assistance" overlay and prevents write to check-in file

= 3.16.10 =
* New: Confirm check-in overlay turns amber with "New Member — needs enrollment" banner for non-active members (phone and DL flows)
* New: Guest list shows amber ⚠️ indicator next to non-active Member Status values
* Fix: Phone and DL check-in lookup responses now include member_status

= 3.16.9 =
* New: Non-active members (status not "Active" or "Y") show amber warning "New Member — please see a staff member" after check-in across all check-in methods (QR, phone, DL)

= 3.16.8 =
* New: Email field added to the Edit Guest modal on the Guest List page

= 3.16.7 =
* Fix: Check-In Monitor now shows ▼ sort indicator on Check-In Time column on initial page load — default sort (latest first) was already correct but the arrow was missing until the user clicked a column

= 3.16.6 =
* Fix: Missing contact prompt on iPad scanner now appears for events imported before v3.16.2 — phone index auto-rebuilds if cached without has_email field
* New: Export now includes Email and Contact Updated columns — shows timestamp when a guest's phone/email was collected during the event

= 3.16.5 =
* Fix: Check-In Time sort now works correctly — stores Unix timestamp at check-in time instead of parsing the display string (which PHP strtotime cannot parse)

= 3.16.4 =
* Fix: Check-In Monitor "Check-In Time" column now sorts chronologically (9:00 AM before 12:30 PM) instead of lexicographically

= 3.16.3 =
* Compatibility: Tested up to WordPress 6.9

= 3.16.2 =
* New: Missing contact prompt on iPad scanner confirm overlay — when a checked-in guest has no email or phone, an inline panel appears before the Check-In button to capture it
* New: Public AJAX endpoint (mmm_update_guest_contact) with HMAC token auth so scanner volunteers can save contact info without admin access
* Fix: Phone and DL search responses now include a `missing` array so callers know which fields need collecting

= 3.16.1 =
* New: Admin guest list — amber "Add phone/email" link appears on rows with missing contact info, opens a modal to fill it in
* Fix: Search bar now waits for 3+ characters before firing AJAX — prevents a request on every keystroke

= 3.16.0 =
* Fix: CSV column auto-mapper now recognises "First Name" / "Last Name" (with spaces), "Islands", and "UPW Member" headers
* Improvement: Guest list now shows 250 rows per page (was 100)

= 3.15.6 =
* Fix: Scanner throttled to 200ms between decode attempts — prevents CPU saturation
  from continuous 1080p PDF417 decode on every animation frame

= 3.15.5 =
* Fix: Manually added or edited guests now scannable immediately — QR and DL
  index caches are cleared on add/edit, not just the phone index

= 3.15.4 =
* Improvement: Camera resolution bumped to 1080p for better PDF417 decode on dense DL barcodes
* Improvement: Continuous autofocus applied after stream starts (camera keeps retrying focus)
* Improvement: Torch/flashlight button appears on supported Android devices (Chrome)
* Fix: Camera selector switch now carries resolution constraints instead of bare deviceId

= 3.15.3 =
* Fix: DOB importer now accepts M/D/YY format (e.g. 9/5/75 → 1975-09-05)
* 2-digit years 70–99 map to 1970–1999; 00–69 map to 2000–2069

= 3.15.2 =
* Fix: Guests with name + DOB but no QR ID or phone are now imported (previously skipped)
* Rows are now only skipped if they have no QR ID, no phone, AND no name at all

= 3.15.1 =
* Fix: CSV column mapper now shows Date of Birth field for mapping
* Fix: Bargaining Unit field relabeled from "Unit Name" to "Bargaining Unit" to avoid confusion
* Fix: Unit Number field relabeled to "Unit Name" to match expected CSV column name
* Fix: "unit name" / "unit_name" added as auto-guess aliases for Unit Name field

= 3.15.0 =
* New: AAMVA PDF417 driver's licence check-in — scan a US/Canada DL to check in a guest
* Tiered matching: Tier 1 uses DOB + last name (for members with DOB in CSV); Tier 2 falls
  back to first + last name with staff confirmation overlay
* DOB is never transmitted or stored in plaintext — SHA-256 hash only
* pdf417 added to BarcodeDetector WANT_FORMATS (ZXing-WASM polyfill already supports it)
* AAMVA JS parser handles DCS/DCT/DAC/DBB fields; client-side validation before any AJAX
* Confirmation overlay shows match tier ("Matched by DOB + Last Name" or "Matched by name only")
* Rate limiting: 10 DL search attempts per IP per 10 minutes via transients
* HMAC tokens include method prefix (dl|) and 5-minute time window
* CSV import: optional dob column accepted; raw DOB hashed at import, never stored raw
* Admin import feedback: reports count of guests with invalid DOB (name-only fallback)
* HSTS header added to public scanner page response

= 3.14.0 =
* Scanner: replaced jsQR with ZXing-C++ WASM polyfill (BarcodeDetector API)
* Full 1D barcode + QR support on Safari/iOS via self-hosted .wasm bundle (no CDN)
* WASM file served locally — eliminates CDN dependency for polyfill

= 3.7.0 =
* Performance & Scalability: three-file split architecture ({slug}-meta.json,
  {slug}-guests.json, {slug}-checkins.json) — runtime ops touch only the small
  checkins file; guests file is write-once at import
* Transparent migration of legacy single-file events on first access
* guest_idx stored in checkin entries — poll endpoint never loads guests file
* Dashboard AJAX returns stats + checkins only (no guests array) — payload reduced
  from 100-150MB to ~2MB at large event scale
* Server-side pagination on guest list (100 rows/page with sort/search URL params)
  eliminates DOM catastrophe at 10k+ guests
* Nonces generated once per page render (not per-row) — eliminates 20,000
  redundant wp_create_nonce calls at 10k guests
* Streaming CSV import — rows processed inline, no $rows[] accumulation
* visibilitychange guard on all polling intervals — pauses on tab hide, resumes on show
* Atomic check-in locking: QR and phone check-in use flock LOCK_EX read-modify-write
  cycle — prevents duplicate entries under simultaneous check-ins
* Slug format validation as defense-in-depth against path traversal
* CSV export formula injection fix: mmm_csv_escape() neutralises dangerous leading chars
* Undo check-in fallback matches by ID OR name — correctly removes phone check-ins
* XSS hardening: Add Guest and poll handlers use createElement/textContent (not innerHTML)
* BOM stripping fixed: proper 3-byte sequence check (was stripping individual bytes)

= 3.6.0 =
* Live Check-In Dashboard: stats bar, Checked In/Not Checked In doughnut chart,
  dynamic field-breakdown bar chart (Unit Name, Member Status, etc.),
  sortable checked-in guest table with 10-second auto-refresh
* Guest List: real-time search, sortable columns, Edit Guest modal (inline AJAX),
  Add Guest modal (inline AJAX), Unit Name and Member Status columns added
* CSV Import: all fields now fully mappable via UI dropdowns (First Name, Last Name,
  Email, Member Status, Unit Name, Unit Number, Employer, Jurisdiction, Job Title,
  Baseyard, Island) — no more auto-detection guessing
* Scanner: barcode scanning support (CODE_128, CODE_39, EAN-13, EAN-8, UPC-A, UPC-E)
  added alongside existing QR code scanning

= 3.5.1 =
* Phone check-in: 7-digit local number auto-prefixes default area code
* Poll endpoint returns idx→time map for guest list auto-refresh

= 3.5.0 =
* Guest list auto-refresh polling (8s interval)
* Manual check-in and undo from admin guest list
* Phone index cache (transient) for fast phone lookups

= 3.0.0 =
* Rebuilt on JSON file storage — no database tables
* CSV guest import with two-step column mapping
* Phone number check-in flow

= 1.0.5 =
* Initial release with QR code generation and basic event check-in
