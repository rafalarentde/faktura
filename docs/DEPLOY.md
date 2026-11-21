# Wdrożenie (Hostinger, subdomena faktury.panbehapowiec.pl)

## 1. Co jest wdrażane

Po każdym pushu na `main` GitHub Actions (`.github/workflows/deploy.yml`) uruchamia testy, buduje
paczkę przez `bin/build-release.sh` i wypycha ją (jeden commit, force-push) na gałąź **`deploy`**.
Gałąź `deploy` zawiera gotowe drzewo: `src/`, `templates/`, `lang/`, `migrations/`, `public/`,
`resources/fonts/`, `bin/`, `config/*.php` (bez `config.php`), `vendor/` (tylko produkcyjne), puste
`storage/{pdf-cache,tmp,logos}`. Nie ma w niej `tests/`, `docs/`, `.git*`, `.superpowers`.

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
4. Opcjonalnie `storage_path` (zapisywalny katalog z `pdf-cache/`, `tmp/`, `logos/`) poza drzewem aplikacji.

## 4. Katalog storage/

Serwer WWW (użytkownik PHP) musi mieć prawo zapisu do `storage/pdf-cache`, `storage/tmp` i
`storage/logos` (zwykle `chmod 755`/`775`, właściciel = konto hostingowe). Zawartość tych katalogów
nie jest w Gicie. `storage/` nie może być pod `public/`.

## 5. Baza danych i pierwszy użytkownik

```bash
php bin/migrate.php                       # nakłada brakujące migracje 001..010 (idempotentne)
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
- [ ] **SMTP, SNI i IPv6 (R32):** nazwa serwera TLS (SNI) ma pochodzić z hosta skonfigurowanego w firmie, nie
  z rozwiązanego adresu IP; współdzielone hosty SMTP bez poprawnego certyfikatu domyślnego mogą się nie
  połączyć. Reguły SSRF (`SmtpTargetPolicy`) nie obejmują jeszcze kilku egzotycznych zakresów IPv6
  (`::ffff:0:127.0.0.1` SIIT, `64:ff9b:1::/48`, `fec0::/10`, `100::/64`, `3fff::/20`); to wzmocnienie jest
  nadal otwarte. Test z prawdziwym serwerem SMTP jest więc obowiązkowy.
- [ ] **Smoke test na prawdziwym MySQL:** cały zestaw testów działał wyłącznie na SQLite. Przed
  produkcją uruchom raz na MySQL ścieżki współbieżne: równoległe wystawianie faktur w jednej firmie
  (numeracja bez duplikatów, kolejność blokad: format, potem kubełki rosnąco), równoległe płatności
  do jednej faktury, wystawienie podczas edycji szkicu, zmiana „zerowanie co rok" w trakcie wystawiania.
  Sprawdź też UNIQUE `(company_id, type, number)` i ponowienie po duplikacie (R24).
- [ ] **Kopie zapasowe:** włączone kopie Hostingera (baza + pliki), osobny eksport bazy (`mysqldump`)
  przed każdym wdrożeniem z migracją, kopia `config.php` i `app_key` poza hostingiem. Sprawdź
  odtworzenie z kopii.
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

- **Sesje po zmianie hasła:** inne zalogowane sesje użytkownika nie są unieważniane po zmianie hasła
  lub resecie 2FA (poza sprawdzeniem TOTP w sesji); planowany `session_epoch` per użytkownik.
- **Numeracja (R23):** przy wyłączonym zerowaniu rocznym i wystawieniu z datą wsteczną w roku, który ma
  wyższy licznik roczny, numery bywają **pomijane, nigdy powtarzane**. Dotyczy tylko firmy, która wcześniej
  miała zerowanie roczne i je wyłączyła. Unikalność jest wymogiem prawnym, ciągłość pilnuje program
  w zwykłym użyciu. Zmiana formatu w trakcie roku może dać numer równy już wystawionemu: wtedy program
  losuje kolejny licznik (R24).
- **Interfejs po niemiecku używa nieformalnego „du"** (zgodnie z makietą i sevDesk). Zmiana na „Sie" to
  edycja tekstów w `lang/de.php`.
- **Pulpit i listy faktur** ładują faktury firmy do PHP i liczą statusy w pamięci; przy tysiącach faktur
  będą wolniejsze (do zoptymalizowania zapytaniem agregującym).
- **Limit planu darmowego nie jest egzekwowany:** model danych ma plan i limity, ale aplikacja ich nie
  sprawdza do czasu ustalenia oferty. Dostęp włącza i wstrzymuje administrator ręcznie.
- Kolejne drobiazgi z przeglądów (m.in. brak przycisku usunięcia zapisanego hasła SMTP, czasy w logu
  maili ustawione na Europe/Berlin) są zapisane w `.superpowers/sdd/*/progress.md`.

## 10. Do weryfikacji ze Steuerberaterem

Aplikacja nie zastępuje doradcy. Poniższe punkty są zaimplementowane jako konfigurowalne lub
ostrożne wartości domyślne i wymagają potwierdzenia przed użyciem produkcyjnym.

1. **Kleinunternehmer (§19 UStG) wyłączony.** Aplikacja zawsze liczy VAT 19%/7%/0% i nie ma wariantu
   faktury bez VAT z adnotacją §19. Potwierdzić, że żadna z firm nie jest Kleinunternehmerem.
2. **Obowiązkowe dane faktury, §14 UStG:** nazwa i adres wystawcy i nabywcy, Steuernummer lub USt-IdNr
   wystawcy, data wystawienia, numer jednorazowy i kolejny, rodzaj i ilość towaru lub zakres usługi,
   data dostawy/wykonania usługi, netto per stawka, stawka i kwota VAT, rabaty, adnotacje. Program
   nie pozwala wystawić faktury bez danych wystawcy/nabywcy i numeru podatkowego (R29); potwierdzić
   kompletność układu PDF i polskich/angielskich wersji.
3. **Numeracja jednorazowa i ciągła (§14 UStG):** edytowalne formaty, numeracja bez dziur w zwykłym
   użyciu, pomijanie (nie powtarzanie) w przypadku z pkt „Numeracja (R23)". Czy dopuszczalne są
   pominięcia w opisanym rzadkim przypadku?
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
8. **GoBD:** niezmienność wystawionych dokumentów (brak edycji i usuwania, tylko korekta), dziennik
   zdarzeń (wpisy logowań są kasowane po 90 dniach, zdarzenia biznesowe zostają), brak archiwizacji
   treści maili (jest tylko `email_log`), potrzeba dokumentacji procesowej (Verfahrensdokumentation).
9. **Okres przechowywania (Aufbewahrung):** przyjęto 8 lat dla faktur i dokumentów księgowych;
   potwierdzić okres i to, że aplikacja ani administrator nie usuwają wystawionych dokumentów w tym czasie.
   Eksport ZIP (dokumenty, klienci, towary, PDF) jest dostępny także dla firmy wstrzymanej.
10. **E-Rechnung:** model danych ma umożliwiać ZUGFeRD/XRechnung, ale pierwsza wersja wystawia tylko
    PDF. Sprawdzić obowiązki wystawiania e-faktur w B2B w Niemczech i terminy przejściowe.
11. **Hostinger, lokalizacja danych i DSGVO:** w jakim kraju/centrum danych leży baza i pliki, umowa
    powierzenia przetwarzania (**AV-Vertrag**, art. 28 RODO) z Hostingerem, umowy z klientami korzystającymi
    z aplikacji jako podmiotami przetwarzającymi dane swoich kontrahentów, okresy retencji danych osobowych
    (`email_log`, dziennik zdarzeń).
12. **Reklamy w planie darmowym** (jeśli kiedykolwiek wejdą): zgody i oznaczanie reklam. Miejsce na baner
    jest domyślnie wyłączone.
