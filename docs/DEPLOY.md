# Wdrożenie (Hostinger, subdomena faktury.panbehapowiec.pl)

## 1. Co jest wdrażane

Po każdym pushu na `main` GitHub Actions (`.github/workflows/deploy.yml`) uruchamia testy, buduje
paczkę przez `bin/build-release.sh` i wypycha ją (jeden commit, force-push) na gałąź **`deploy`**.
Gałąź `deploy` zawiera gotowe drzewo: `src/`, `templates/`, `lang/`, `migrations/`, `public/`,
`resources/fonts/`, `bin/`, `config/*.php` (bez `config.php`), `vendor/` (tylko produkcyjne), puste
`storage/{pdf-cache,tmp,logos,archive}`. Nie ma w niej `tests/`, `docs/`, `.git*`, `.superpowers`.

Paczkę można zbudować też ręcznie: `bin/build-release.sh [katalog]` (domyślnie `build/`), wynik to
`build/faktura-release/` i `build/faktura-release.tar.gz`. Skrypt kończy się błędem, jeśli
któryś plik PHP nie przechodzi `php -l`.

Na hosting trafia zawartość gałęzi `deploy` (Git w hPanelu z auto-deployem albo ręczne wgranie
archiwum). Pliki, których nie ma w gałęzi (`config/config.php`, zawartość `storage/`), nie mogą
zostać skasowane przy aktualizacji: nie uruchamiaj `git clean -x` w katalogu aplikacji.

## 2. Subdomena i katalog publiczny

- Subdomena `faktury.panbehapowiec.pl` musi wskazywać na katalog **`public/`** aplikacji
  (w hPanelu: Domeny, Subdomeny, własny katalog główny). Nic poza `public/` nie może być widoczne
  z sieci: `src/`, `config/`, `storage/`, `vendor/`, `migrations/`, `bin/` leżą wyżej.
- Jeśli panel nie pozwala ustawić katalogu głównego na `.../public`, nie kopiuj aplikacji do
  `public_html`; zrób dowiązanie symboliczne (subdomena -> `public/`) albo przenieś aplikację na VPS.
- `public/.htaccess` (Apache): wszystko, co nie jest istniejącym plikiem statycznym, trafia do
  `index.php`; pliki zaczynające się od kropki i pliki typu `.sqlite/.sql/.ini/.log/.lock/.json/.env`
  są zabronione; wyłączone listowanie katalogów i nagłówek `X-Powered-By`. Przy nginx na VPS trzeba to
  odtworzyć w konfiguracji serwera (`try_files $uri /index.php`, `location ~ /\. { deny all; }`).
- Wymuś HTTPS (certyfikat Hostingera) i ustaw w konfiguracji `base_url` na adres z `https://`:
  wtedy aplikacja sama wysyła `Strict-Transport-Security` (max-age 1 rok).
- Aplikacja wysyła `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy` i `Content-Security-Policy` (bez `unsafe-inline`). Pliki statyczne z
  `public/assets/` serwuje Apache bez tych nagłówków; to jest zamierzone.

## 3. Konfiguracja poza webrootem

1. Skopiuj `config/config.example.php` do pliku `config.php` i uzupełnij: dane MySQL, `app_key`,
   `base_url`, `debug => false`.
2. Plik trzymaj **poza katalogiem publicznym**. Domyślnie aplikacja czyta `<aplikacja>/config/config.php`
   (to jest nad `public/`, więc poza webrootem). Bezpieczniej jest trzymać go jeszcze wyżej, poza
   drzewem wdrażanym z Gita, i wskazać zmienną środowiskową **`FAKTURA_CONFIG=/home/uXXXX/faktura-config/config.php`**
   (np. `SetEnv FAKTURA_CONFIG ...` w konfiguracji serwera/hPanelu; ten sam plik czytają `bin/*.php`).
   Nie dopisuj `SetEnv` do `public/.htaccess`, bo plik jest nadpisywany przy każdym wdrożeniu.
3. `app_key` to base64 z 32 losowych bajtów: `php -r 'echo base64_encode(random_bytes(32)), "\n";'`.
   Szyfruje sekrety TOTP i hasła SMTP. **Zrób jego kopię w menedżerze haseł**: utrata lub zmiana klucza
   oznacza, że sekrety 2FA i hasła SMTP trzeba ustawić od nowa. Uprawnienia pliku: `chmod 600`.
4. Opcjonalnie `storage_path` (zapisywalny katalog z `pdf-cache/`, `tmp/`, `logos/`, `archive/`) poza drzewem aplikacji.

## 4. Katalog storage/

Serwer WWW (użytkownik PHP) musi mieć prawo zapisu do `storage/pdf-cache`, `storage/tmp`,
`storage/logos` i **`storage/archive`** (zwykle `chmod 755`/`775`, właściciel = konto hostingowe).
Zawartość tych katalogów nie jest w Gicie. `storage/` nie może być pod `public/`.

### Archiwum PDF (GoBD): `storage/archive/`

Każdy wystawiony dokument (faktura, korekta, oferta, wezwanie) ma zarchiwizowany plik PDF w
`storage/archive/<id_firmy>/<id_dokumentu>.pdf` (katalogi `0700`, pliki `0600`, poza webrootem). Plik powstaje
od razu po wystawieniu (przy błędzie zapisu: przy pierwszym podglądzie PDF, wysyłce maila lub eksporcie) i jest
**zapisywany tylko raz**: pobranie, załącznik maila i eksport ZIP zawsze oddają te same bajty, więc późniejsza
zmiana logo, tekstów, szablonu czy danych firmy nie zmienia już wystawionego dokumentu. Suma SHA-256 jest w
tabeli `document_archives` (UNIQUE po `document_id`) oraz, dla każdej wysyłki, w `email_log.pdf_sha256`.

- **To są dane prawnie istotne (Aufbewahrung, GoBD).** `storage/archive/` **musi wchodzić do kopii zapasowych**
  razem z bazą (inaczej niż `pdf-cache/` i `tmp/`, które można skasować) i nie wolno go czyścić. Kopię bazy i
  archiwum rób z tego samego momentu; nie uruchamiaj `git clean -x` w katalogu aplikacji.
- Brak zapisu do `storage/archive` powoduje błąd 500 przy pobraniu/wysyłce PDF (celowo: dokument bez
  archiwalnej kopii nie jest oddawany).
- Brakujący plik (utrata dysku, odtworzenie bazy bez plików) program wygeneruje ponownie, zapisze i zapisze
  zdarzenie `document.archive_restored` w dzienniku (`sha_changed` = czy nowy PDF różni się od zapisanej sumy;
  suma w bazie jest wtedy aktualizowana). Taki PDF może się różnić od oryginału, jeśli teksty lub logo
  zmieniły się w międzyczasie: dlatego kopie zapasowe archiwum są obowiązkowe.
- Plik, którego suma SHA-256 nie zgadza się z zapisaną, jest traktowany jako naruszenie integralności:
  błąd 500, plik nie jest ani oddawany, ani nadpisywany.
- Plik bez wiersza w bazie (np. baza odtworzona ze starszej kopii) nie jest nigdy uznawany za archiwum:
  przy archiwizacji dokumentu o tym id zostaje zastąpiony nowym plikiem.

## 5. Baza danych i pierwszy użytkownik

```bash
php bin/migrate.php                       # nakłada brakujące migracje 001..011 (idempotentne)
php bin/create-user.php admin@example.com --admin     # hasło z STDIN; 2FA ustawiane przy pierwszym logowaniu
```

Bez SSH: uruchom skrypty przez cron hPanelu (jednorazowo) albo z lokalnej maszyny wskazując
`FAKTURA_CONFIG` na konfigurację ze zdalną bazą (jeśli Hostinger zezwala na zdalny dostęp do MySQL).
Firmy, członków i status dostępu ustawia administrator w aplikacji (`/admin`).

### Uwaga dla istniejących baz: migracja 005 edytowana w miejscu (R22)

Plik `migrations/005_numbering.sql` został poprawiony po tym, jak mógł już być uruchomiony (commit
5a2e618): do `number_counters` doszła kolumna `last_year`. Migrator nie ponawia zastosowanych migracji,
więc baza, która przeszła starszą wersję 005, **przed wdrożeniem nowego kodu** wymaga:

```sql
ALTER TABLE number_counters ADD COLUMN last_year BIGINT NOT NULL DEFAULT 0;
```

Nowe, puste bazy nie wymagają niczego. Sprawdzenie: `SHOW COLUMNS FROM number_counters LIKE 'last_year';`.
Pozostałe migracje nie były zmieniane po wydaniu.

## 6. Czcionki PDF i cache

Przy pierwszym renderze PDF Dompdf buduje cache metryk czcionek w `storage/pdf-cache/`. Po wdrożeniu
**wygeneruj jeden PDF** (wystaw lub podejrzyj dowolną fakturę), żeby rozgrzać cache; potem renderowanie
jest szybkie. Po wymianie lub dodaniu pliku w `resources/fonts/` **opróżnij `storage/pdf-cache/`**
(zostaw `.gitkeep`), inaczej PDF-y użyją starych metryk. Licencje czcionek (SIL OFL 1.1):
`resources/fonts/OFL-*.txt`.

## 7. Cron

Jedno zadanie, nieobowiązkowe dla działania aplikacji, ale zalecane (retencja dziennika logowań, 90 dni;
zdarzenia biznesowe zostają):

```
30 3 * * * php /ścieżka/do/aplikacji/bin/purge-audit.php
```

## 8. Lista przed uruchomieniem produkcyjnym

- [ ] **Prawdziwy mail testowy:** w ustawieniach firmy skonfiguruj prawdziwy SMTP i wyślij fakturę/wiadomość
  na własny adres; sprawdź PDF w załączniku, polskie i niemieckie znaki, nagłówki (SPF/DKIM domeny nadawcy).
- [ ] **SMTP, SNI i IPv6:** połączenie idzie na sprawdzony adres IP, a nazwa serwera dla certyfikatu i SNI
  pochodzi z hosta skonfigurowanego w firmie (`peer_name` w opcjach SSL; PHP używa go także jako SNI, więc
  uznajemy to za poprawne). Współdzielone hosty SMTP bez poprawnego certyfikatu domyślnego mogą mimo to nie
  połączyć. Reguły SSRF (`SmtpTargetPolicy`) przyjmują dla IPv6 wyłącznie `2000::/3` z wyłączeniem
  `2001::/23`, `2001:db8::/32`, `2002::/16` i `3fff::/20` (formy IPv4-mapped/compatible, NAT64, SIIT i
  zakresy lokalne odpadają razem z całym zakresem poza `2000::/3`). Test z prawdziwym serwerem SMTP
  (wysłanie wiadomości na własny adres) pozostaje obowiązkowy.
- [ ] **Smoke test na prawdziwym MySQL:** zestaw testów domyślnie działa na SQLite. Zestaw przeszedł
  też na MariaDB 10.11 (`FAKTURA_TEST_MYSQL='host|user|pass' vendor/bin/phpunit`, każdy test dostaje
  świeżą bazę), a równoległe wystawianie faktur i płatności sprawdza skrypt `bin/smoke-concurrency.php`.
  Uruchom go raz na bazie Hostingera (pusta lub testowa baza po `bin/migrate.php`, nigdy produkcyjna):
  `FAKTURA_CONFIG=/ścieżka/config.php FAKTURA_SMOKE_CONFIRM=yes php bin/smoke-concurrency.php 40 8`
  (40 faktur, 8 procesów; po sukcesie sam sprząta; wynik `ALL CHECKS PASSED`). Jeśli Hostinger blokuje
  `proc_open`, uruchom go z własnego komputera na zdalnej bazie. Ścieżki współbieżne objęte kontrolą: równoległe wystawianie faktur w jednej firmie
  (numeracja bez duplikatów, kolejność blokad: format, potem kubełki rosnąco), równoległe płatności
  do jednej faktury, wystawienie podczas edycji szkicu, zmiana „zerowanie co rok" w trakcie wystawiania.
  Sprawdź też UNIQUE `(company_id, type, number)` i ponowienie po duplikacie (R24).
- [ ] **Kopie zapasowe:** włączone kopie Hostingera (baza + pliki), osobny eksport bazy (`mysqldump`)
  przed każdym wdrożeniem z migracją, kopia `config.php` i `app_key` poza hostingiem. **Kopia obejmuje
  `storage/archive/`** (archiwalne PDF-y wystawionych dokumentów, dane prawnie istotne; `pdf-cache/` i `tmp/`
  nie są potrzebne). Sprawdź odtworzenie z kopii: baza + archiwum z tego samego momentu.
- [ ] **HTTPS i HSTS:** certyfikat aktywny, przekierowanie http -> https, `base_url` z `https://`,
  w odpowiedzi nagłówek `Strict-Transport-Security`, ciasteczko sesji z `Secure`.
- [ ] `debug => false`, `promo_enabled => false`, `mail_allow_private_hosts => false`.
- [ ] `https://faktury.panbehapowiec.pl/.env`, `/.git/config`, `/config/config.php`, `/storage/`,
  `/vendor/autoload.php` zwracają 403/404.
- [ ] Konto administratora z 2FA, hasło silne i unikalne; konta zwykłych użytkowników dodane po
  weryfikacji firm (dostęp włączany ręcznie po płatności).
- [ ] Jeden pełny przebieg na danych jednej prawdziwej firmy (wystawienie, PDF, mail, płatność,
  korekta, wezwanie), potem zatwierdzenie przez Steuerberatera.

## 9. Znane ograniczenia

- **Brak samoobsługowego resetu hasła:** użytkownik, który zapomniał hasła, prosi administratora. W panelu
  `/admin/companies/{id}` przy członku firmy jest akcja „Temporäres Passwort": losowe hasło (16 znaków)
  pokazywane jednorazowo, zapisywane tylko jako hash, wpis w logu audytu bez hasła; użytkownik musi je zmienić
  przy najbliższym wejściu, a wszystkie jego sesje i blokada logowania są kasowane. Dla kont administratorów
  akcja nie istnieje (administrator zmienia hasło w Einstellungen > Sicherheit; przy utraconym haśle
  administratora trzeba podmienić `password_hash` w bazie albo założyć drugie konto przez `bin/create-user.php`). Strona `/settings/security` (hasło, kody zapasowe, 2FA) działa
  bez przypisanej firmy.
- **Unieważnianie sesji:** `users.session_epoch` (migracja 011) jest kopiowany do sesji przy logowaniu i
  porównywany przy każdym żądaniu. Zmiana hasła, wygenerowanie kodów zapasowych, włączenie 2FA, reset 2FA
  przez administratora i hasło tymczasowe zwiększają licznik: wszystkie inne sesje użytkownika kończą się, a
  sesja wykonująca zmianę zostaje. Po wdrożeniu migracji 011 jednorazowo wylogowani zostaną wszyscy (stare
  sesje nie mają licznika).
- **Limit logowania per adres IP:** 5 prób na 15 minut dla adresu IP (i osobno dla adresu e-mail). Użytkownicy
  za wspólnym NAT (biuro, operator komórkowy) dzielą ten limit; seria nieudanych prób jednej osoby może
  chwilowo zablokować pozostałych z tej sieci. Każda próba jest liczona przed sprawdzeniem hasła (także
  zablokowana), więc ciągłe próby przedłużają blokadę.
- **MySQL:** połączenie ustawia `sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'` i `time_zone='+00:00'`
  (UTC dla `CURRENT_TIMESTAMP`). Wystawienie dokumentu i utworzenie wezwania do zapłaty są ponawiane raz po
  deadlocku lub przekroczeniu czasu blokady (błędy 1213/1205); drugi błąd jest zgłaszany użytkownikowi.
- **Composer:** `composer.json` ma `config.platform.php = 8.3.0`, żeby `composer.lock` nie wymagał nowszego PHP
  niż na hostingu; po zmianie wersji PHP na serwerze zaktualizuj tę wartość i uruchom
  `composer update --lock`.
- **Numeracja (R23):** przy wyłączonym zerowaniu rocznym i wystawieniu z datą wsteczną w roku, który ma
  wyższy licznik roczny, numery bywają **pomijane, nigdy powtarzane**. Dotyczy tylko firmy, która wcześniej
  miała zerowanie roczne i je wyłączyła. Unikalność jest wymogiem prawnym, ciągłość pilnuje program
  w zwykłym użyciu. Zmiana formatu w trakcie roku może dać numer równy już wystawionemu: wtedy program
  losuje kolejny licznik (R24).
- **Storno płatności:** płatność można anulować (kontr-zapis z ujemną kwotą, `payments.reverses_payment_id`;
  wiersze płatności nigdy nie są zmieniane ani kasowane). Każdą płatność można anulować raz, tylko w całości
  i tylko dla wystawionej faktury; zapis jest w dzienniku (`document.payment_reverse`). Kwota zapłacona,
  saldo, status, lista wezwań i pulpit liczą **sumę netto**, więc po stornie faktura wraca do „otwartej"
  (lub „po terminie"). Data płatności nie może być w przyszłości.
- **Data wsteczna wystawienia (§14 UStG, kolejność numerów):** program pozwala wystawić dokument z datą
  wcześniejszą niż ostatnio wystawiony dokument tego rodzaju (np. po urlopie), ale ostrzega w formularzu
  (bez blokady). Numery rosną z kolejnością wystawiania, nie z datami: Steuerberater powinien potwierdzić,
  czy takie odstępstwa są dla firmy akceptowalne (pkt 3 poniżej).
- **Odsetki za zwłokę, ograniczenia:** stopa bazowa jest wpisywana ręcznie w formularzu w zakresie
  **0 do 30 %** (ujemnej stopy bazowej, obowiązującej do 2022 r., nie da się wpisać: przy ujemnej stopie ustawienie 0
  zawyża ustawowe odsetki, więc w takim okresie nie włączaj odsetek albo skoryguj kwotę ręcznie; model
  obliczeń zna wartości ujemne, formularz ich nie przyjmuje); dopłata B2B/B2C 0 do 20 pkt, ryczałt i opłata
  0 do 1000 €, progi 1 do 365 dni. **Jedna stopa na cały okres:** odsetki liczone są jedną roczną stopą
  (stopa bazowa + dopłata, stan na dzień wezwania) przez wszystkie dni zwłoki; zmiany stopy bazowej
  1 stycznia i 1 lipca (§247 BGB) w trakcie zwłoki nie są rozdzielane na okresy. Odsetki zerowe lub ujemne
  (stopa łączna <= 0) nie są drukowane w wezwaniu.
- **Interfejs po niemiecku używa nieformalnego „du"** (zgodnie z makietą i sevDesk). Zmiana na „Sie" to
  edycja tekstów w `lang/de.php`.
- **Pulpit i listy faktur** ładują faktury firmy do PHP i liczą statusy w pamięci; przy tysiącach faktur
  będą wolniejsze (do zoptymalizowania zapytaniem agregującym).
- **Limit planu darmowego nie jest egzekwowany:** model danych ma plan i limity, ale aplikacja ich nie
  sprawdza do czasu ustalenia oferty. Dostęp włącza i wstrzymuje administrator ręcznie.
- Kolejne drobiazgi z przeglądów (m.in. czasy w logu maili ustawione na Europe/Berlin) są zapisane w `.superpowers/sdd/*/progress.md`.

## 10. Do weryfikacji ze Steuerberaterem

Aplikacja nie zastępuje doradcy. Poniższe punkty są zaimplementowane jako konfigurowalne lub
ostrożne wartości domyślne i wymagają potwierdzenia przed użyciem produkcyjnym.

1. **Kleinunternehmer (§19 UStG) wyłączony.** Aplikacja zawsze liczy VAT 19%/7%/0% i nie ma wariantu
   faktury bez VAT z adnotacją §19. Potwierdzić, że żadna z firm nie jest Kleinunternehmerem.
2. **Obowiązkowe dane faktury, §14 UStG:** nazwa i adres wystawcy i nabywcy, Steuernummer lub USt-IdNr
   wystawcy, data wystawienia, numer jednorazowy i kolejny, rodzaj i ilość towaru lub zakres usługi,
   data dostawy/wykonania usługi (pusta = data wystawienia, wtedy dokument ma adnotację „Leistungsdatum
   entspricht Rechnungsdatum"; okres rozliczeniowy nie ma osobnego pola, wystarczy opisać go w pozycji),
   netto per stawka, stawka i kwota VAT, rabaty, adnotacje. Program
   nie pozwala wystawić faktury bez danych wystawcy/nabywcy i numeru podatkowego (R29); potwierdzić
   kompletność układu PDF i polskich/angielskich wersji.
3. **Numeracja jednorazowa i ciągła (§14 UStG):** edytowalne formaty, numeracja bez dziur w zwykłym
   użyciu, pomijanie (nie powtarzanie) w przypadku z pkt „Numeracja (R23)". Czy dopuszczalne są
   pominięcia w opisanym rzadkim przypadku? **Data wsteczna:** dokument można wystawić z datą
   wcześniejszą niż ostatnio wystawiony tego rodzaju (z ostrzeżeniem, bez blokady); numer jest wtedy
   wyższy niż w dokumentach z późniejszą datą. Czy to dopuszczalne i jak to opisać w Verfahrensdokumentation?
4. **Reverse Charge:** tekst adnotacji (DE: „Steuerschuldnerschaft des Leistungsempfängers ...
   Innergemeinschaftliche Leistung gemäß Art. 196 MwStSystRL", PL i EN analogicznie), numery VAT obu stron,
   weryfikacja VIES zapisywana z datą. Tekst dotyczy usług; dla dostaw towarów do UE obowiązuje inna
   adnotacja (zwolniona dostawa wewnątrzwspólnotowa) i może być potrzebna Zusammenfassende Meldung.
5. **Waluty PLN/USD:** kurs przeliczenia i kwota VAT w EUR (wymóg co do źródła i dnia kursu, adnotacja).
6. **Zaokrąglanie VAT:** per pozycja czy per suma (ustawienie w aplikacji).
7. **Wezwania (Mahnung):** odsetki za zwłokę §288 BGB (podstawa + 9 pkt B2B, + 5 pkt konsumenci; stopa
   bazowa zmienia się co pół roku i jest wpisywana ręcznie w ustawieniach), ryczałt 40 € (§288 ust. 5 BGB,
   tylko B2B), ewentualna opłata za wezwanie. Odsetki i ryczałt są domyślnie wyłączone i włączane
   świadomie przy każdym wezwaniu. Potwierdzić wzory, kwoty i terminy kolejnych stopni.
8. **GoBD:** niezmienność wystawionych dokumentów (brak edycji i usuwania, tylko korekta), archiwalna
   kopia PDF zapisywana raz (`storage/archive/`, suma SHA-256, zapis w bazie i w `email_log`; patrz
   sekcja 4), dziennik zdarzeń (wpisy logowań są kasowane po 90 dniach, zdarzenia biznesowe zostają),
   brak archiwizacji treści maili (jest tylko `email_log` z sumą załączonego PDF), potrzeba dokumentacji
   procesowej (Verfahrensdokumentation). Do potwierdzenia: czy archiwum plikowe plus baza spełnia wymogi
   niezmienności i czytelności (Unveränderbarkeit, maszynowa ocena danych), oraz data wsteczna (pkt 3).
9. **Okres przechowywania (Aufbewahrung):** przyjęto 8 lat dla faktur i dokumentów księgowych;
   potwierdzić okres i to, że aplikacja ani administrator nie usuwają wystawionych dokumentów w tym czasie.
   Eksport ZIP (dokumenty, klienci, towary, PDF) jest dostępny także dla firmy wstrzymanej.
10. **E-Rechnung:** w Niemczech od 1.1.2025 każdy przedsiębiorca krajowy w B2B musi umieć **odbierać**
    e-faktury (XRechnung/ZUGFeRD, EN 16931); sam PDF nie jest e-fakturą. **Obowiązek wystawiania** e-faktur
    w krajowym B2B: od **1.1.2027** dla firm z obrotem ponad 800 000 € w roku poprzednim, od **1.1.2028**
    dla wszystkich (do końca 2026 oraz do końca 2027 dla mniejszych firm dopuszczalne są jeszcze faktury
    papierowe/PDF za zgodą odbiorcy). **Aplikacja produkuje wyłącznie PDF**; ZUGFeRD (PDF/A-3 z XML) jest
    planowany, ale jeszcze go nie ma. Do ustalenia ze Steuerberaterem: od kiedy konkretna firma musi
    wystawiać e-faktury i jak do tego czasu obsługiwać odbiór (osobna skrzynka e-mail dla e-faktur, archiwizacja
    otrzymanych XML przez firmę poza tą aplikacją).
11. **Hostinger, lokalizacja danych i DSGVO:** w jakim kraju/centrum danych leży baza i pliki, umowa
    powierzenia przetwarzania (**AV-Vertrag**, art. 28 RODO) z Hostingerem, umowy z klientami korzystającymi
    z aplikacji jako podmiotami przetwarzającymi dane swoich kontrahentów, okresy retencji danych osobowych
    (`email_log`, dziennik zdarzeń).
12. **Reklamy w planie darmowym** (jeśli kiedykolwiek wejdą): zgody i oznaczanie reklam. Miejsce na baner
    jest domyślnie wyłączone.
