# UA Project Manager — Roadmap and acceptance criteria

This document is the source of truth for the next integration stages. Do not create a second plugin or repository for these modules. Continue in `portallcomua/ua-project-manager`.

## 1. Main product rule

UA Project Manager is the single WordPress plugin and the shared data hub. Projects are the canonical records. Pay Box, Web Finder, Advertising, Click Counter, Referrals and Tasks must use the same project/customer/contact references wherever applicable.

Do not delete or replace existing module tables/options while integrating. Use additive schema migrations and preserve records. Do not tell the site owner to uninstall legacy plugins until the embedded replacement has been verified on the live site.

## 2. Required admin navigation

One top-level WordPress menu: **UA Project Manager**.

Planned sections and pages:

- Головна
- Проєкти
- Реклама
  - Рекламодавці та блоки
  - Звіти
  - Платежі / продовження
- Оплати
  - Послуги Pay Box
  - PRO ліцензії Freemius
  - Платежі
  - Підписки
  - Налаштування Pay Box
- Збір і розсилка
  - Пошук сайтів
  - Імпорт доменів
  - Списки сайтів
  - Збір email та контакти
  - Шаблони листів
  - Кампанії / розсилки
  - Налаштування Web Finder
- Кнопки завантаження (UA Click Counter)
  - Кнопки
  - Створити кнопку
  - Розсилки
- Реферальна система
- Задачі
- Статистика
- Налаштування

No embedded module should register an independent top-level menu. Keep direct page slugs stable so existing links and bookmarks continue to work.

## 3. Localization

Ukrainian is the default admin language. Translate page titles, submenu labels, buttons, form labels, notices, validation errors, empty states, table headings, confirmations, email templates and help text. Preserve English only for product names, technical identifiers, URLs and unavoidable protocol terms. Avoid mixed-language labels such as "Finder" in ordinary navigation.

## 4. Project and payment integration — highest priority

- Main project records are the source of truth.
- Each project that can be sold through Pay Box maps to one appropriate Pay Box service.
- Avoid duplicate services for duplicate project records; never delete duplicate records or their historical licenses without explicit migration rules.
- Keep PRO/license service and advertising placement service as distinct service types.
- Preserve Freemius product, plan, secret, price and license data.
- Old manual license block/unblock functionality is not to be restored.
- Payment status and access are based on verified Freemius events/webhooks.
- Ensure project create/update/archive changes synchronize to Pay Box without generating duplicate services.
- Remove obsolete license fields from the new unified project UI where they are no longer used.

## 5. Advertising module

Advertiser record:
- Name and automatic creation date.
- Website, email, phone, Viber and Telegram contacts; all clickable, email opens a mail client.
- One or more projects selected from the main project list.
- Banner either HTML or uploaded image with destination URL.
- Monthly price and currency (UAH/USD).
- Placement start and end dates.
- Payment amount and currency (UAH/USD), payment method (Freemius / Privat Envelope / cash) and payment date.
- Multiple advertising blocks per advertiser.

Operations:
- Impressions and clicks tracking.
- Monthly email report to advertiser.
- Reminder 7 days before placement expiry and reminder on expiry with renewal offer.
- Renewal link uses the relevant Freemius checkout.
- Only after a verified payment event should the next advertising period/block be created.
- Project listing displays advertiser, impressions, clicks and placement dates.
- Project setting **Доступний в рекламі** controls eligibility. A project already occupied by an active placement must not be offered for a second exclusive placement.
- Project fields for **Всі проєкти URL** and **HTML banner code** each have independent display checkboxes.
- Extend the existing code generator for integration snippets; do not replace it.

## 6. UA Click Counter

Embed the existing UA Click Counter implementation, not a rewrite. Preserve:
- Existing CPT `uacc_button` and its post meta.
- Existing tables `uacc_emails` and `uacc_counters`.
- Existing shortcodes `[ua_click_counter]`.
- Button creation/editing, target URL, colors, click count, email collection and consent, per-button mailing.

Do not initialize the embedded handler while the legacy standalone UA Click Counter is active, to avoid duplicate shortcode/AJAX processing. Show an admin notice explaining the safe switch. After testing, deactivating the old plugin must not delete its data.

## 7. Referrals — planned implementation

Frontend registration and affiliate portal:
- A registered site user selects one, several or all eligible projects.
- The user creates/updates a public referral page on the frontend, never in WordPress admin.
- Each selected project has a distinct referral link and ready-to-post promotional text.
- Provide HTML banner snippets using the user's referral link.
- Show clicks/visits, downloads, PRO activations, confirmed purchases, total commission, paid amount and pending amount.

Attribution:
- A referral URL must identify both affiliate and project. The short base form `/r{user_id}` is insufficient by itself when one affiliate promotes several projects; preserve the simple form with a project parameter or a project-specific path, for example `/r123?project=project-slug`.
- Store first/last touch attribution according to an explicit setting, with an attribution cookie and server-side event record.
- Count a commission only after a confirmed, non-refunded payment event. Do not pay twice for the same Freemius transaction.
- Map the confirmed Freemius event to the affiliate and project using stable transaction/license identifiers.
- Keep a separate ledger for visits, downloads, activations, pending commissions, approved commissions and payouts.

Admin settings:
- Commission percentage is configurable per project.
- Minimum payout threshold, default example 1000 UAH.
- Monthly payout date, default example after the 25th.
- Admin sees all affiliates and their statistics.
- Red status when approved balance reaches payout threshold; green status after payout is recorded.
- Affiliate can see payout status and paid amount in their frontend cabinet.

Freemius limitation:
- Freemius affiliate attribution and commission data can be used, but the seller must currently pay affiliate commissions manually and mark them as paid in Freemius. Do not promise automatic direct-to-card affiliate payouts. Seller's own Freemius payout methods are not the same as affiliate payout methods.
- Keep internal payout ledger and allow admin to record actual transfer method/reference/date.

## 8. Tasks module

Task fields:
- Title.
- Rich/formatted description.
- Executor selector presets: Google AI, ChatGPT, AI Studio, DeepSeek, GitHub Copilot, plus custom executor.
- Separate plain-text personal notes.
- Optional project and due date.
- Status and completion timestamp.
- Button **Виконано** records completion date.
- Completed tasks appear at the bottom and are collapsed by default; tapping expands them.

Synchronization:
- Task UUID is stable across devices.
- Keep revision, updated_at, deleted_at tombstones, device_id and idempotent mutation identifiers.
- Offline app changes go to a local outbox; retry automatically when network returns.
- Plugin changes sync back to the app.
- Use conflict detection; never silently overwrite one device's edits with another device's newer data. Preserve both copies or provide a clear conflict resolution UI.
- Use WordPress Application Passwords over HTTPS for initial app authentication; never ask the user for their normal WordPress password.
- Apply user permissions so users only see tasks they are allowed to manage.

## 9. Android app

First APK scope is only **Задачі**:
- Create, view, edit and complete tasks.
- Work offline with a local database.
- Sync in both directions with the WordPress plugin when connectivity returns.
- Display sync status and unresolved conflicts.
- Keep credentials encrypted on device.
- Build APK through GitHub Actions and publish it as a separate artifact/release asset. Do not put APK inside the WordPress plugin ZIP.

Later stage: extend Android app to manage projects, payments, advertising, referrals and other plugin modules after the task sync is stable.

## 10. Acceptance checklist before declaring a release ready

- PHP syntax and JavaScript syntax checks pass.
- WordPress plugin ZIP installs and updates through the existing updater.
- No duplicate top-level menus from embedded modules.
- Main project list and Pay Box services remain synchronized without duplicates.
- Existing Pay Box/Finder/Click Counter tables and records remain intact.
- Every advertised admin page opens the correct renderer.
- Ukrainian localization is consistent across the complete user flow.
- Advertising creation, payment recording, expiry reminder and statistics are tested.
- Task creation/edit/completion works in WP admin and Android.
- Android offline create/edit/complete and two-way sync are tested on a real site.
- Referral attribution is tested against a real verified Freemius payment before commissions are enabled.
- Do not mark untested live-site behavior as verified.
