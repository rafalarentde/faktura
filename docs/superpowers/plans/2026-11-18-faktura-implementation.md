# Faktura – plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wielofirmowa, trójjęzyczna aplikacja webowa do wystawiania faktur, korekt, ofert i wezwań do zapłaty (PDF + wysyłka mailem), z logowaniem hasło + 2FA i dostępem firm włączanym ręcznie po płatności.

**Architecture:** Lekki PHP 8.3 bez frameworka: front controller, FastRoute, Twig, PDO. Czysta logika domenowa (`Money`, `Totals`, `VatRule`, `Numbering`, `Dunning`) oddzielona od kontrolerów i szablonów, żeby dało się ją testować bez serwera i przenieść na VPS. Każde zapytanie biznesowe przechodzi przez repozytoria wymuszające `company_id`.

**Tech Stack:** PHP 8.3, Composer, PHPUnit 11 (testy na SQLite w pamięci, produkcja MySQL/MariaDB), nikic/fast-route, twig/twig, dompdf/dompdf, phpmailer/phpmailer, spomky-labs/otphp, endroid/qr-code, libsodium.

**Spec:** `docs/superpowers/specs/2026-11-18-faktura-design.md`. Wzór wyglądu: `docs/mockup/faktura-makieta.html` (wyrzucany prototyp, nie kopiować kodu, tylko układ, kolory i teksty tłumaczeń).

## Global Constraints

- Hosting: Hostinger, PHP + MySQL; bez założenia dostępu SSH; `vendor/` dostarczany zbudowany; kod i konfiguracja poza katalogiem publicznym (`public/` jest jedynym rootem).
- Kwoty zawsze jako `int` (grosze/centy) + kod waluty. Nigdy `float`. Ilość pozycji jako `int` w tysięcznych (`qtyMilli`).
- Języki: `de`, `pl`, `en`. Waluty: `EUR`, `PLN`, `USD`. Stawki VAT: 19% (`1900` bp) i 7% (`700` bp); Reverse-Charge 0%.
- Hasła: Argon2id. 2FA: TOTP (RFC 6238) + kody awaryjne, obowiązkowe dla każdego konta. Sesje `HttpOnly`/`Secure`/`SameSite`, CSRF na każdym POST.
- Każde zapytanie do tabel biznesowych filtrowane po `company_id` (testowane pod kątem wycieku między firmami).
- Wystawionego dokumentu nie wolno edytować ani usuwać (tylko korekta). Szkic można usuwać. Numer nadawany dopiero przy wystawieniu, w transakcji.
- Nic nie wysyła się samo (faktury, oferty, wezwania): zawsze świadome kliknięcie.
- Format numeracji: dowolny tekst + `{YYYY}`, `{YY}`, `{MM}`, `{DD}`, licznik `{N}`/`{NN}`/`{NNN}` (liczba N = liczba cyfr z zerami). Format bez licznika jest błędem.
- Wezwania: odsetki i ryczałt 40 € (tylko B2B) domyślnie wyłączone, włączane przy każdym wezwaniu. Progi domyślne: 7/21/35/49 dni, edytowalne per firma. Stopa bazowa wpisywana przez użytkownika, pole domyślnie puste, nigdzie nie wpisana na stałe. Narzut domyślny: 9 pkt B2B, 5 pkt konsument.
- Wygląd: `#F5C400` (`#ffd633`), `#0f0f0f`, `#1b1b1b`, `#2a2a2a`, tło `#f4f2ea`, tekst `#2b2b2b`, pomocniczy `#636363`; statusy `#1f8a43`, `#d68a00`, `#c4351f`; ciemny motyw `#15171a`/`#191b1e`. Czcionki Barlow Condensed (nagłówki) i Source Sans 3 (tekst) z pełnym Latin Extended w UI i w PDF.
- Elementy oznaczone w specyfikacji „do weryfikacji" (numeracja §14 UStG, kurs i VAT w EUR, odsetki §288 BGB, okres przechowywania 8 lat) implementować jako konfigurowalne i opisać w README jako wymagające potwierdzenia Steuerberatera.

## Review Focus

Wejścia, które specyfikacja przewiduje, a które najłatwiej zepsuć. Każdy ma test w zadaniu-właścicielu (wskazanym w nawiasie):

1. Klient z UE z numerem VAT, ale VIES niedostępny lub numer nieważny: nie wolno po cichu zastosować Reverse-Charge. (Task 14)
2. Zaokrąglanie VAT przy wielu pozycjach (3 × 0,33 € po 19%): suma VAT liczona od sumy netto per stawka, nie per pozycja. (Task 14)
3. Faktura częściowo opłacona lub opłacona w międzyczasie: wezwanie liczy odsetki od salda do zapłaty, a opłacona faktura znika z listy zaległych. (Task 21)
4. Dostęp do cudzych danych przez podmianę ID w adresie (IDOR) w każdym kontrolerze. (Task 7, test przekrojowy w każdym zadaniu z kontrolerem)
5. Znaki specjalne i HTML w nazwach klientów i pozycji (`<script>`, `&`, `ł ś ż ą ę ó ć ń ź`, `ß ü`): poprawnie w HTML, PDF i mailu. (Task 17)

---

## Etap 1: Fundament

### Task 1: Szkielet, baza, migracje

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `config/config.example.php`, `public/index.php`, `public/.htaccess`, `src/Core/Db.php`, `src/Core/Migrator.php`, `migrations/001_base.sql`, `tests/bootstrap.php`, `tests/Core/DbTest.php`, `tests/Core/MigratorTest.php`

**Interfaces:**
- Produces: `App\Core\Db` – `__construct(\PDO $pdo, string $driver)`, `driver(): string` (`'mysql'|'sqlite'`), `forUpdate(): string` (`' FOR UPDATE'` lub `''`), `transaction(callable $fn): mixed`, `all(string $sql, array $p = []): array`, `one(string $sql, array $p = []): ?array`, `exec(string $sql, array $p = []): int`, `lastId(): int`. `App\Core\Migrator` – `__construct(Db $db, string $dir)`, `run(): int` (liczba zastosowanych). `tests/bootstrap.php` udostępnia `TestDb::fresh(): Db` (SQLite w pamięci z zastosowanymi migracjami).
- Konwencja migracji: pliki `NNN_nazwa.sql`; placeholder `{AUTOID}` zamieniany na `INTEGER PRIMARY KEY AUTOINCREMENT` (sqlite) lub `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (mysql); tabela `schema_migrations(name)`.

- [ ] **Step 1: Napisz testy.** `DbTest`: `test_transaction_rolls_back_on_exception` (wyjątek w callbacku → wiersz niezapisany), `test_for_update_suffix_per_driver`. `MigratorTest`: `test_run_applies_each_file_once` (drugi `run()` zwraca `0`), `test_base_tables_exist` (istnieją `users`, `backup_codes`, `companies`, `company_users`, `login_attempts`, `audit_log`).
- [ ] **Step 2: Uruchom:** `composer install && vendor/bin/phpunit` → FAIL (klasy nie istnieją).
- [ ] **Step 3: Zaimplementuj** `Db`, `Migrator` i `001_base.sql` z kolumnami ze specyfikacji pkt 7 dla tych tabel (`companies` zawiera m.in. `access_status` `active|suspended|trial`, `access_until`, `plan`, pola danych firmy, `smtp_*` z hasłem w kolumnie `smtp_pass_enc`). `public/index.php` na razie zwraca `200 OK`; `.htaccess` kieruje wszystko do `index.php` i blokuje pliki zaczynające się od kropki.
- [ ] **Step 4: Uruchom** `vendor/bin/phpunit` → PASS. **Commit:** `git add -A && git commit -m "feat: project skeleton, Db wrapper, migrator, base schema"`

### Task 2: Szyfrowanie sekretów

**Files:** Create `src/Core/Crypto.php`, `tests/Core/CryptoTest.php`

**Interfaces:** Produces `App\Core\Crypto` – `__construct(string $base64Key)`, `encrypt(string $plain): string`, `decrypt(string $cipher): string` (rzuca `\RuntimeException` przy zmanipulowanym tekście). Klucz z `config['app_key']` (32 bajty base64), trzymany poza webrootem.

- [ ] **Step 1: Testy.** `test_roundtrip_returns_original` (w tym `"Zażółć gęślą jaźń"`), `test_two_encryptions_differ` (losowy nonce), `test_tampered_cipher_throws`, `test_wrong_key_throws`.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Core/CryptoTest.php` → FAIL.
- [ ] **Step 3: Zaimplementuj** na `sodium_crypto_secretbox` (nonce dołączony do szyfrogramu, całość base64).
- [ ] **Step 4:** testy PASS. **Commit:** `feat: secret encryption`

### Task 3: Money i formatowanie

**Files:** Create `src/Money/Money.php`, `src/Money/Formatter.php`, `tests/Money/MoneyTest.php`, `tests/Money/FormatterTest.php`

**Interfaces:** Produces `App\Money\Money` (niemutowalny): `Money::of(int $minor, string $currency)`, `add(Money): Money`, `sub(Money): Money`, `percentBp(int $bp): Money` (zaokrąglenie half-up), `mulQty(int $qtyMilli): Money` (`minor × qtyMilli / 1000`, half-up), `minor(): int`, `currency(): string`; operacje na różnych walutach rzucają `\InvalidArgumentException`. `App\Money\Formatter` – `money(Money, string $lang): string`, `date(\DateTimeInterface, string $lang): string`.

- [ ] **Step 1: Testy.** `percentBp`: `Money::of(96000,'EUR')->percentBp(1900)->minor() === 18240`. `mulQty`: `Money::of(45000,'EUR')->mulQty(2000)->minor() === 90000`; `mulQty(2500)` na 333 daje 833 (half-up z 832,5). Formatter: `1206,60 €` → `money(Money::of(120660,'EUR'),'de') === "1.206,60 €"`, `'pl'` → `"1 206,60 €"` (spacja nierozdzielająca U+00A0 jak zwraca `intl`), `'en'` → `"€1,206.60"`; PLN w `pl` → `"… zł"`; `date(2026-11-18,'de') === "18.11.2026"`, `'en'` → `"18/11/2026"`.
- [ ] **Step 2:** testy FAIL. **Step 3: Zaimplementuj** (`Formatter` na `NumberFormatter` z `intl`, locale `de_DE`, `pl_PL`, `en_GB`). **Step 4:** PASS. **Commit:** `feat: Money value object and locale formatter`

### Task 4: Tłumaczenia

**Files:** Create `src/I18n/Translator.php`, `lang/de.php`, `lang/pl.php`, `lang/en.php`, `tests/I18n/TranslatorTest.php`

**Interfaces:** Produces `App\I18n\Translator` – `__construct(string $langDir)`, `t(string $key, string $lang, array $vars = []): string` (zmienne `{name}`; brak klucza w `$lang` → `de` → sam klucz), `keys(string $lang): array`. Pliki `lang/*.php` zwracają płaską tablicę `klucz => tekst`; klucze w stylu `nav.dashboard`, `invoice.reverse_charge_note`. Teksty startowe: przepisać z `T` w makiecie (nawigacja, tabele, statusy, ustawienia, wezwania).

- [ ] **Step 1: Testy.** `test_parity` (`keys('pl')` i `keys('en')` równe `keys('de')`; to test, który pilnuje, że dodanie klucza w jednym języku bez pozostałych wywala build), `test_variable_substitution`, `test_fallback_to_german_then_key`.
- [ ] **Step 2:** FAIL. **Step 3:** implementacja + pliki językowe. **Step 4:** PASS. **Commit:** `feat: translator with de/pl/en`

### Task 5: Usługi uwierzytelniania

**Files:** Create `src/Auth/Totp.php`, `src/Auth/BackupCodes.php`, `src/Auth/RateLimiter.php`, `tests/Auth/TotpTest.php`, `tests/Auth/BackupCodesTest.php`, `tests/Auth/RateLimiterTest.php`

**Interfaces:**
- Consumes: `Db`, `Crypto` (Task 1, 2).
- Produces: `Totp` – `newSecret(): string`, `provisioningUri(string $secret, string $email, string $issuer = 'Faktura'): string`, `qrDataUri(string $uri): string`, `verify(string $secret, string $code, ?int $now = null): bool` (okno ±1 krok 30 s). `BackupCodes` – `__construct(Db $db)`, `generate(int $userId, int $count = 8): array` (zwraca kody jawne, w bazie tylko hash), `consume(int $userId, string $code): bool` (jednorazowy). `RateLimiter` – `__construct(Db $db, int $max = 5, int $windowSec = 900)`, `tooMany(string $key): bool`, `hit(string $key): void`, `clear(string $key): void`. Wartości 5 prób / 15 min to moja decyzja (specyfikacja mówi tylko „ograniczanie prób").

- [ ] **Step 1: Testy.** `verify` przyjmuje kod z bieżącego kroku i sąsiednich, odrzuca ±2 kroki; `consume` zwraca `true` raz, potem `false`; `generate(…, 8)` daje 8 różnych kodów, kody w bazie nie są jawne; `RateLimiter`: po 5 `hit` `tooMany` jest `true`, po `clear` `false`, wpisy starsze niż okno nie liczą się.
- [ ] **Step 2:** FAIL. **Step 3:** implementacja (otphp, endroid/qr-code, `password_hash` Argon2id dla kodów awaryjnych). **Step 4:** PASS. **Commit:** `feat: TOTP, backup codes, rate limiter`

### Task 6: Logowanie, sesje, CSRF

**Files:** Create `src/Core/App.php`, `src/Core/Session.php`, `src/Core/Csrf.php`, `src/Http/AuthController.php`, `src/Http/Middleware/RequireLogin.php`, `templates/login.twig`, `templates/login_2fa.twig`, `templates/2fa_setup.twig`, `tests/Http/AuthFlowTest.php`

**Interfaces:**
- Consumes: Task 1–5. Produces: `App\Core\App::handle(Request): Response` (routing przez FastRoute), `Session` (`start()`, `userId(): ?int`, `loginStage(): 'none'|'password_ok'|'full'`), `Csrf` (`token(): string`, `check(string $t): bool`), `RequireLogin` (przekierowuje na `/login`; konta bez włączonego 2FA kieruje na `/2fa/setup`, bez wyjątków).

- [ ] **Step 1: Testy** (żądania wywoływane bezpośrednio przez `App::handle`, bez serwera). `test_password_ok_requires_second_step` (po poprawnym haśle sesja ma `password_ok`, dostęp do `/` przekierowuje na `/login/2fa`), `test_valid_totp_completes_login`, `test_backup_code_works_once`, `test_wrong_password_and_unknown_email_give_same_message`, `test_post_without_csrf_is_rejected`, `test_five_failures_lock_further_attempts`, `test_account_without_2fa_is_forced_to_setup`, `test_session_cookie_flags` (`HttpOnly`, `SameSite=Lax`, `Secure` gdy HTTPS).
- [ ] **Step 2:** FAIL. **Step 3:** implementacja; `session_regenerate_id(true)` po każdym etapie logowania; hasło weryfikowane przez `password_verify`, `password_needs_rehash` obsłużone. **Step 4:** PASS. **Commit:** `feat: login with password and TOTP`

### Task 7: Izolacja firm

**Files:** Create `src/Tenant/CompanyContext.php`, `src/Tenant/AccessDenied.php`, `src/Repo/BaseRepo.php`, `tests/Tenant/IsolationTest.php`

**Interfaces:**
- Produces: `CompanyContext::forUser(Db $db, int $userId, int $companyId): self` (rzuca `AccessDenied`, gdy brak wpisu w `company_users`), `id(): int`, `role(): string`, `canIssue(\DateTimeImmutable $now): bool` (patrz Task 8). `abstract BaseRepo` – `__construct(Db $db, CompanyContext $ctx)`; chronione `scopedOne(string $table, int $id): ?array`, `scopedAll(string $table, string $where = '1=1', array $p = []): array`, `scopedInsert(string $table, array $row): int` (wstrzykuje `company_id`), `scopedUpdate(string $table, int $id, array $row): int`, `scopedDelete(string $table, int $id): int`. Wszystkie dokładają `company_id = :ctx` do zapytania.

- [ ] **Step 1: Testy.** Dwie firmy A i B, repo zbudowane dla A: `test_cannot_read_other_company_row_by_id` (zwraca `null`), `test_cannot_update_or_delete_other_company_row` (zwracają `0`, wiersz B bez zmian), `test_insert_forces_own_company_id` (podany w `$row` obcy `company_id` jest ignorowany), `test_user_without_membership_gets_access_denied`.
- [ ] **Step 2:** FAIL. **Step 3:** implementacja. **Step 4:** PASS. **Commit:** `feat: tenant context and scoped repositories`

### Task 8: Administracja firmami i dostęp po płatności

**Files:** Create `src/Repo/CompanyRepo.php`, `src/Http/AdminController.php`, `src/Http/Middleware/RequireAdmin.php`, `templates/admin/companies.twig`, `tests/Http/AdminTest.php`; Modify `src/Tenant/CompanyContext.php` (`canIssue`)

**Interfaces:** Produces `CompanyRepo` (nieskopiowane do firmy, tylko administrator): `create(array $data): int`, `setAccess(int $id, string $status, ?string $until): void`, `addUser(int $companyId, string $email, string $role): int`. `CompanyContext::canIssue(now)` = `access_status === 'active'` lub (`trial` i `access_until >= now`).

- [ ] **Step 1: Testy.** `test_non_admin_gets_403`, `test_admin_can_create_company_and_add_user`, `test_suspended_company_cannot_issue` (`canIssue` zwraca `false`), `test_suspended_company_user_can_still_log_in_and_read`, `test_expired_trial_cannot_issue`.
- [ ] **Step 2:** FAIL. **Step 3:** implementacja widoku jak „Verwaltung" w makiecie (lista firm, przycisk Aktywuj/Wstrzymaj). **Step 4:** PASS. **Commit:** `feat: admin companies and access status`

### Task 9: Layout i motyw

**Files:** Create `templates/layout.twig`, `public/assets/app.css`, `public/assets/app.js`, `public/assets/fonts/` (woff2 latin **i** latin-ext dla Barlow Condensed 700/800 i Source Sans 3 oraz `resources/fonts/SourceSans3.ttf`, `BarlowCondensed-Bold.ttf`, `BarlowCondensed-ExtraBold.ttf` – skopiować z repo `rafalarentde/pan-behapowiec`: `panbehapowiec/assets/fonts/*.woff2`, `panbehapowiec/fonts/`, `logo/fonty/`), `src/Http/DashboardController.php` (pusta strona startowa), `tests/Http/LayoutTest.php`

**Interfaces:** Produces szablon bazowy `layout.twig` z blokami `title`, `content`; menu z makiety (Übersicht, Rechnung, Angebote, Mahnungen, Kunden, Artikel, Einstellungen, admin tylko dla `is_admin`); przełącznik języka (zapis do `users.ui_lang`) i motywu (`localStorage`); zmienne CSS z palety; blok `{% block promo %}` na baner partnerski renderowany tylko gdy `config['promo_enabled']` jest prawdą (domyślnie `false`).

- [ ] **Step 1: Testy.** `test_css_defines_palette_tokens` (plik zawiera `#F5C400`, `#f4f2ea`, `#c4351f`), `test_fonts_include_latin_ext_files` (w `public/assets/fonts` są pliki `*-latin-ext.woff2`), `test_page_renders_in_all_three_languages`, `test_admin_menu_hidden_for_normal_user`, `test_promo_slot_hidden_by_default`.
- [ ] **Step 2:** FAIL. **Step 3:** layout według makiety (jasny/ciemny motyw, układ mobilny). **Step 4:** PASS. Wizualnie porównać z `docs/mockup/faktura-makieta.html` (zrzut ekranu Playwright, polskie znaki w tej samej czcionce). **Commit:** `feat: layout, design tokens, fonts`

---

## Etap 2: Bazy

### Task 10: Klienci i VIES

**Files:** Create `migrations/002_customers.sql`, `src/Repo/CustomerRepo.php`, `src/Vies/ViesClient.php`, `src/Vies/HttpViesClient.php`, `src/Http/CustomerController.php`, `templates/customers/{list,form}.twig`, `tests/Repo/CustomerRepoTest.php`, `tests/Http/CustomerControllerTest.php`

**Interfaces:** Produces `CustomerRepo extends BaseRepo` – `find(int $id): ?array`, `list(string $q = ''): array`, `save(array $data, ?int $id = null): int`. Kolumny wg spec pkt 7 plus `vies_status` (`valid|invalid|unavailable|none`) i `vies_checked_at`. `interface ViesClient { check(string $country, string $vatId): ViesStatus }`, `enum ViesStatus { Valid, Invalid, Unavailable }`; `HttpViesClient` woła usługę REST VIES Komisji Europejskiej (timeout 5 s, błąd sieci → `Unavailable`).

- [ ] **Step 1: Testy.** Repo: `test_list_filters_by_name_case_insensitive`, `test_cannot_load_other_company_customer` (IDOR, przez kontroler: `GET /customers/{id}` obcej firmy → 404), `test_name_with_html_is_stored_raw_and_escaped_on_render` (`<script>` w nazwie nie pojawia się nieescapowany w HTML). Kontroler z `FakeVies`: przycisk „VIES prüfen" zapisuje status i datę.
- [ ] **Step 2:** FAIL. **Step 3:** implementacja według ekranu „Kunden" (lista u góry, formularz pod spodem). **Step 4:** PASS. **Commit:** `feat: customers with VIES check`

### Task 11: Towary i usługi

**Files:** Create `migrations/003_items.sql`, `src/Repo/ItemRepo.php`, `src/Http/ItemController.php`, `templates/items/{list,form}.twig`, `tests/Repo/ItemRepoTest.php`

**Interfaces:** Produces `ItemRepo` – `find(int $id): ?array`, `list(): array`, `save(array $data, ?int $id = null): int`, `nameFor(array $item, string $lang): string` (brak nazwy w języku → `de` → pierwsza dostępna). Tabele `items` i `item_names(item_id, lang, name, description)`; cena `price_minor`, `currency`, `vat_bp` (`1900|700|0`), `unit`, `type` (`service|goods`).

- [ ] **Step 1: Testy.** `test_name_falls_back_to_german`, `test_prices_stored_as_integer_minor`, `test_only_allowed_vat_rates` (`vat_bp = 1500` → wyjątek walidacji), IDOR jak w Task 10.
- [ ] **Step 2–4:** FAIL → implementacja (ekran jak w makiecie: lista u góry, edycja pod spodem, trzy pola nazwy DE/PL/EN) → PASS. **Commit:** `feat: items and services`

### Task 12: Ustawienia firmy

**Files:** Create `src/Http/SettingsController.php`, `templates/settings/{company,numbers,dunning,mail,security}.twig`, `tests/Http/SettingsTest.php`; Modify `src/Repo/CompanyRepo.php`

**Interfaces:** Produces `CompanyRepo::settings(CompanyContext): array` i `saveSettings(CompanyContext, array): void` (firma, IBAN/BIC, logo, domyślny język i waluta, domyślny termin płatności, teksty wstępny/końcowy, SMTP z hasłem szyfrowanym `Crypto`, progi wezwań, `dunning_base_rate_bp` (`NULL` domyślnie), narzuty B2B/B2C w bp, ryczałt w minor, opłata za wezwanie). Logo: PNG/SVG do 1 MB, zapisywane poza `public/` i serwowane przez kontroler.

- [ ] **Step 1: Testy.** `test_smtp_password_never_stored_plain` (w bazie nie ma jawnego hasła, `settings()` zwraca zamaskowane), `test_logo_rejects_non_image_and_oversize`, `test_dunning_defaults_are_7_21_35_49_and_base_rate_null`, `test_settings_scoped_to_company`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: company settings`

### Task 13: Numeracja

**Files:** Create `migrations/004_numbering.sql`, `src/Domain/NumberFormat.php`, `src/Domain/NumberSequences.php`, `tests/Domain/NumberFormatTest.php`, `tests/Domain/NumberSequencesTest.php`; Modify `templates/settings/numbers.twig`

**Interfaces:** Produces `NumberFormat::parse(string $pattern): NumberFormat` (rzuca `InvalidNumberFormat`, gdy brak licznika), `render(\DateTimeImmutable $d, int $counter): string`, `hasYear(): bool`. `NumberSequences extends BaseRepo` – `configure(string $docType, string $pattern, bool $resetYearly): void`, `preview(string $docType, \DateTimeImmutable $d): string`, `next(string $docType, \DateTimeImmutable $d): string` (w transakcji z `forUpdate()`; typy `invoice|offer|dunning|credit_note`; domyślne formaty `RE-{YYYY}-{NNN}`, `AN-{YYYY}-{NNN}`, `MA-{YY}{MM}-{NNN}`, `KO-{YYYY}-{NNN}`).

- [ ] **Step 1: Testy.** `render('RE-{YYYY}-{NNN}', 2026-11-18, 42) === 'RE-2026-042'`; `render('FV/{YY}/{MM}/{NNNN}', …, 42) === 'FV/26/11/0042'`; `{N}` przy 1234 daje `1234`; `parse('FAKTURA')` rzuca; `test_yearly_reset_restarts_counter_in_new_year`; `test_no_reset_continues_across_years`; `test_reset_without_year_in_pattern_is_rejected_on_configure`; `test_two_consecutive_next_calls_never_repeat`; `test_sequences_are_separate_per_company_and_type`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. Edytor w ustawieniach pokazuje `preview()` i ostrzeżenia jak w makiecie. **Commit:** `feat: editable numbering formats and sequences`

---

## Etap 3: Faktury

### Task 14: VAT i sumy

**Files:** Create `src/Domain/VatRule.php`, `src/Domain/Totals.php`, `src/Domain/Line.php`, `tests/Domain/VatRuleTest.php`, `tests/Domain/TotalsTest.php`

**Interfaces:** Produces `Line` (`qtyMilli:int`, `unitMinor:int`, `discountBp:int`, `vatBp:int`). `VatRule::decide(array $buyer): VatDecision` gdzie `$buyer = ['country'=>'PL','is_company'=>true,'vat_id'=>'PL123…','vies'=>ViesStatus]`; `VatDecision` (`reverseCharge:bool`, `needsConfirmation:bool`, `noteKey:?string`). Reverse-Charge tylko, gdy: kraj UE ≠ DE, firma, jest numer VAT i `vies === Valid`. `Totals::compute(array $lines, bool $reverseCharge, string $currency, ?string $eurRate = null): Totals` – pola `netMinor`, `vat` (`array<int bp, int minor>`), `vatMinor`, `grossMinor`, `vatEurMinor:?int`. Netto pozycji zaokrąglane half-up po rabacie, VAT liczony od sumy netto per stawka. `$eurRate` = jednostek waluty na 1 EUR jako string, np. `"4.2650"`.

- [ ] **Step 1: Testy.** Z makiety: pozycje `2×450,00@19`, `1×60,00@19`, `5×12,00@7` → `netMinor 102000`, `vat[1900] 18240`, `vat[700] 420`, `grossMinor 120660`. **Zaokrąglanie:** trzy pozycje po `33` minor @19% → `vatMinor === 19` (nie 18). `reverseCharge=true` → cały `vat` pusty, `grossMinor === netMinor`. PLN: VAT `100000` minor przy kursie `"4.2650"` → `vatEurMinor === 23447`. `VatRule`: PL, firma, ważny VIES → `reverseCharge`; PL osoba prywatna → nie; PL firma bez numeru → nie; **VIES `Unavailable` lub `Invalid` → `reverseCharge=false`, `needsConfirmation=true`** (Review Focus 1); DE firma → nie.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: VAT decision and totals`

### Task 15: Dokumenty (rdzeń)

**Files:** Create `migrations/005_documents.sql`, `src/Repo/DocumentRepo.php`, `src/Domain/DocumentService.php`, `tests/Domain/DocumentServiceTest.php`

**Interfaces:**
- Consumes: Task 7, 13, 14. Produces `DocumentService` – `createDraft(string $type, array $data): int`, `updateDraft(int $id, array $data): void`, `deleteDraft(int $id): void`, `issue(int $id, \DateTimeImmutable $at): string` (zwraca numer; wymaga `ctx->canIssue`), `addPayment(int $docId, int $amountMinor, \DateTimeImmutable $on): void`, `balanceMinor(int $docId): int`, `paymentStatus(int $docId, \DateTimeImmutable $today): string` (`draft|open|partial|paid|overdue`), `createCorrection(int $invoiceId): int`. Tabele `documents` (`type` `invoice|credit_note|offer|dunning`, `status` `draft|issued`, `number`, `related_document_id`, snapshoty `seller_json`, `buyer_json`, daty, `currency`, `eur_rate`, `reverse_charge`, sumy, `lang`), `document_lines` (snapshot), `payments`.

- [ ] **Step 1: Testy.** `test_issue_assigns_number_and_locks` (po `issue` `updateDraft` rzuca `DocumentLocked`), `test_issued_document_cannot_be_deleted`, `test_draft_has_no_number_and_deleting_it_leaves_no_gap` (wystaw A=…001, szkic B usunięty, wystaw C=…002), `test_snapshot_survives_customer_edit` (zmiana nazwy klienta nie zmienia wystawionej faktury), `test_partial_payment_status_and_balance`, `test_overpayment_rejected`, `test_correction_links_to_original_and_zeroes_it`, `test_suspended_company_cannot_issue`, `test_cross_company_document_not_visible`.
- [ ] **Step 2–4:** FAIL → implementacja (VAT i sumy wyliczane przez `VatRule`/`Totals` przy `issue`, wynik zapisany) → PASS. **Commit:** `feat: documents, issuing, payments, corrections`

### Task 16: Edytor faktury

**Files:** Create `src/Http/InvoiceController.php`, `templates/invoices/{list,edit,show}.twig`, `public/assets/invoice.js`, `tests/Http/InvoiceControllerTest.php`

**Interfaces:** Consumes `DocumentService`, `CustomerRepo`, `ItemRepo`, `NumberSequences::preview`. Endpoint `POST /invoices/recalc` zwraca JSON z sumami z `Totals::compute` (jedno źródło prawdy, bez liczenia w JS). Formularz jak w makiecie: klient, język dokumentu, waluta i kurs, pozycje (też z bazy artykułów), Reverse-Charge wykryty automatycznie z ostrzeżeniem przy `needsConfirmation`.

- [ ] **Step 1: Testy.** `test_recalc_returns_server_totals_for_makieta_example` (gross `120660`), `test_selecting_pl_customer_with_valid_vies_defaults_to_reverse_charge_and_pln`, `test_unverified_vies_requires_explicit_confirmation_to_issue`, `test_issue_button_blocked_for_suspended_company`, `test_foreign_invoice_id_returns_404`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: invoice editor`

### Task 17: PDF

**Files:** Create `src/Pdf/PdfRenderer.php`, `templates/pdf/{invoice,offer,dunning}.twig`, `tests/Pdf/PdfRendererTest.php`

**Interfaces:** Produces `PdfRenderer::render(int $documentId): string` (bajty PDF; jedyne źródło danych to snapshot dokumentu; język z `documents.lang`), `html(int $documentId): string` (dla testów). Czcionki TTF z `resources/fonts` zarejestrowane w Dompdf. Układ: nagłówek z logo firmy i numerem, nabywca/sprzedawca, tabela pozycji, sumy per stawka, adnotacja Reverse-Charge i linia „VAT w EUR + kurs" dla PLN/USD, dane bankowe, stopka. Wygląd końcowy ustalamy z właścicielem później.

- [ ] **Step 1: Testy.** `test_fonts_cover_polish_and_german_glyphs` (przez `FontLib` sprawdź, że `cmap` każdego pliku TTF zawiera `ąćęłńóśźżĄĆĘŁŃÓŚŹŻßäöüÄÖÜ`), `test_reverse_charge_note_present_in_selected_language` (de/pl/en), `test_pln_invoice_shows_vat_in_eur_and_rate`, `test_html_escapes_customer_name` (`<script>alert(1)</script> & Co` → escapowane), `test_render_returns_valid_pdf` (zaczyna się od `%PDF-`), `test_long_customer_name_and_30_lines_paginate`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: PDF rendering with full Latin Extended fonts`

### Task 18: Wysyłka maili

**Files:** Create `migrations/006_email_log.sql`, `src/Mail/MailTransport.php`, `src/Mail/SmtpTransport.php`, `src/Mail/DocumentMailer.php`, `templates/mail/{invoice,offer,dunning}.{de,pl,en}.twig`, `tests/Mail/DocumentMailerTest.php`; Modify `src/Http/InvoiceController.php`

**Interfaces:** Produces `interface MailTransport { send(array $smtp, string $from, string $fromName, string $to, string $subject, string $body, array $attachments): void }`, `SmtpTransport` (PHPMailer), `DocumentMailer::preview(int $docId): array{to,subject,body}`, `send(int $docId, string $to, string $subject, string $body): void` (zapis w `email_log`, wynik `sent|failed`, PDF w załączniku). Domyślne teksty maili po trzy języki do akceptacji właściciela.

- [ ] **Step 1: Testy** z `FakeTransport`. `test_send_attaches_pdf_and_logs`, `test_failure_is_logged_and_throws_readable_error`, `test_nothing_is_sent_on_preview`, `test_invalid_recipient_rejected`, `test_uses_company_smtp_credentials_decrypted`, `test_mail_subject_and_body_in_document_language`.
- [ ] **Step 2–4:** FAIL → implementacja (podgląd, edycja treści, przycisk „Wystaw i wyślij" i osobno „Wyślij ponownie") → PASS. **Commit:** `feat: send documents by email`

---

## Etap 4: Oferty

### Task 19: Oferty

**Files:** Create `src/Http/OfferController.php`, `templates/offers/{list,edit,show}.twig`, `tests/Domain/OfferTest.php`; Modify `src/Domain/DocumentService.php`

**Interfaces:** Produces `DocumentService::setOfferStatus(int $id, string $status): void` (`sent|accepted|rejected|expired`), `convertOffer(int $offerId): int` (tworzy szkic faktury z pozycjami i klientem oferty, ustawia `related_document_id`). Oferta używa numeracji `offer`, `valid_until`, tego samego edytora, PDF i maila.

- [ ] **Step 1: Testy.** `test_only_accepted_offer_can_be_converted`, `test_conversion_copies_lines_customer_currency_and_links_back`, `test_converted_invoice_is_draft_without_number`, `test_expired_status_computed_from_valid_until`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: offers with conversion to invoice`

---

## Etap 5: Wezwania do zapłaty

### Task 20: Logika wezwań

**Files:** Create `src/Domain/Dunning.php`, `src/Domain/DunningSettings.php`, `tests/Domain/DunningTest.php`

**Interfaces:** Produces `enum DunningLevel: int { Reminder = 0; First = 1; Second = 2; Final = 3 }`. `Dunning::suggest(int $daysOverdue, ?DunningLevel $lastSent, DunningSettings $s): ?DunningLevel` (zwraca najwyższy próg ≤ `daysOverdue`, który jest wyższy niż `lastSent`; inaczej `null`). `Dunning::interest(Money $outstanding, int $days, ?int $baseRateBp, int $surchargeBp): ?Money` (`outstanding × (base+surcharge) × days / 365`, half-up; `null`, gdy `baseRateBp === null`, czyli użytkownik jeszcze nie wpisał stopy). `Dunning::flatFee(bool $isCompany): Money` → 40 € tylko dla firm, dla konsumenta wyjątek `FlatFeeNotAllowed`.

- [ ] **Step 1: Testy.** Progi domyślne: `suggest(6,null)` → `null`; `suggest(7,null)` → `Reminder`; `suggest(21,null)` → `First`; `suggest(21, First)` → `null`; `suggest(38, First)` → `Second`; `suggest(60, Second)` → `Final`. Odsetki: `interest(Money::of(142800,'EUR'), 21, 127, 900) === Money::of(844,'EUR')` (10,27% p.a.); `interest(…, baseRateBp: null, …) === null`; ryczałt konsumenta rzuca wyjątek.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: dunning rules and interest`

### Task 21: Wezwania (UI, PDF, wysyłka ręczna)

**Files:** Create `src/Http/DunningController.php`, `templates/dunning/{list,preview}.twig`; Modify `src/Domain/DocumentService.php` (`createDunning(int $invoiceId, DunningLevel $level, bool $withInterest, bool $withFlatFee): int`), `tests/Http/DunningControllerTest.php`

**Interfaces:** Consumes Task 15, 17, 18, 20. Lista zaległych: faktury `issued`, saldo > 0, po terminie; kolumny: dni, ostatnio wysłane, podpowiedź. Wezwanie to dokument typu `dunning` z `related_document_id`, poziomem, kwotą odsetek i ryczałtu. Odsetki liczone od **salda do zapłaty** (`balanceMinor`), nie od kwoty faktury. Przełączniki odsetek i ryczałtu domyślnie wyłączone; odsetki niedostępne z komunikatem, gdy brak stopy bazowej w ustawieniach.

- [ ] **Step 1: Testy.** `test_paid_invoice_not_listed`, `test_partial_payment_interest_uses_remaining_balance` (Review Focus 3), `test_payment_after_suggestion_removes_invoice_from_list`, `test_interest_and_flat_fee_off_by_default`, `test_flat_fee_unavailable_for_private_customer`, `test_interest_disabled_without_base_rate`, `test_nothing_sent_without_explicit_post`, `test_foreign_currency_invoice_dunning_keeps_currency`, `test_dunning_gets_own_number_from_dunning_sequence`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: dunning screens, PDF and manual sending`

---

## Etap 6: Zamknięcie

### Task 22: Przegląd (dashboard)

**Files:** Modify `src/Http/DashboardController.php`, `templates/dashboard.twig`; Create `src/Repo/ReportRepo.php`, `tests/Repo/ReportRepoTest.php`

**Interfaces:** Produces `ReportRepo::totals(\DateTimeImmutable $today): array` z kluczami `open`, `overdue`, `due_in_7`, `paid_month` (każdy `{minor:int, count:int}`, w walucie firmy; dokumenty w innych walutach liczone osobno i oznaczone), oraz `recent(int $limit = 10): array`. Pasek planu pokazuje liczbę wystawionych w tym miesiącu dokumentów tylko informacyjnie (bez egzekwowania limitów).

- [ ] **Step 1: Testy.** `test_overdue_excludes_paid_and_drafts`, `test_due_in_7_boundaries` (dzień terminu liczy się, ósmy dzień nie), `test_totals_scoped_to_company`, `test_other_currency_not_summed_into_eur`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: dashboard`

### Task 23: Eksport danych i dziennik zdarzeń

**Files:** Create `src/Domain/Exporter.php`, `src/Http/ExportController.php`, `src/Core/Audit.php`, `tests/Domain/ExporterTest.php`

**Interfaces:** Produces `Exporter::zip(): string` (ZIP z `documents.json`, `customers.json`, `items.json` i PDF-ami wystawionych dokumentów), działający także dla firmy `suspended`. `Audit::log(string $action, array $detail = []): void` zapisuje do `audit_log` logowania, wystawienia, wysyłki, zmiany ustawień i zmiany statusu dostępu.

- [ ] **Step 1: Testy.** `test_export_contains_only_own_company_data`, `test_suspended_company_can_export`, `test_audit_records_login_issue_send_and_settings_change`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Commit:** `feat: data export and audit log`

### Task 24: Wdrożenie i przegląd bezpieczeństwa

**Files:** Create `.github/workflows/deploy.yml`, `bin/build-release.sh`, `README.md`, `docs/DEPLOY.md`, `tests/Security/SecurityHeadersTest.php`

**Interfaces:** Workflow na push do `main`: `composer install --no-dev --optimize-autoloader`, uruchomienie testów, zapis gotowej paczki (z `vendor/`, bez `tests/` i `docs/`) na gałąź `deploy`, tak jak w repo `pan-behapowiec`. `docs/DEPLOY.md`: subdomena `faktury.panbehapowiec.pl` wskazuje na `public/`; `config/config.php` i `app_key` poza webrootem; cron nie jest wymagany; lista punktów „do weryfikacji ze Steuerberaterem".

- [ ] **Step 1: Testy.** `test_security_headers_present` (`X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy` bez `unsafe-inline` dla skryptów), `test_dotfiles_and_config_not_served`, `test_errors_do_not_leak_stack_traces_in_production_mode`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS. **Step 5:** sprawdź workflow lokalnie (`bin/build-release.sh` buduje paczkę, a `php -l` przechodzi dla wszystkich plików) i przejdź ręcznie listę: logowanie z 2FA, firma A i B nie widzą swoich danych, wystawienie faktury DE, faktura PL z Reverse-Charge, Mahnung bez odsetek. **Commit:** `chore: deployment pipeline and docs`

---

## Self-review

- **Pokrycie specyfikacji:** logowanie+2FA (6), firmy i izolacja (7, 8), 3 języki (4, 9), waluty (3, 14), klienci/VIES (10), towary z nazwami per język (11), numeracja edytowalna (13), VAT i Reverse-Charge (14), faktury/korekty/płatności (15, 16), PDF z polskimi znakami (17), wysyłka mailem (18), oferty (19), wezwania z opcjonalnymi odsetkami i ryczałtem (20, 21), dostęp po płatności i eksport dla wstrzymanych (8, 23), miejsce na baner partnerski (9).
- **Luka znaleziona i naprawiona w przeglądzie:** pkt 2b specyfikacji (wyłączone domyślnie miejsce na baner) nie miał zadania. Dodane do Task 9 razem z testem `test_promo_slot_hidden_by_default`.
- **Spójność typów:** `Money`/`Line`/`Totals` używane tak samo w 14, 15, 17, 20, 21; `NumberSequences::next` używany w 15 i 21; `DocumentService` dopisywany w 19 i 21 bez zmiany sygnatur z Task 15.
- **Proporcje:** plan to lista decyzji i testów, bez ciał funkcji.
