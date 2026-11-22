# Faktura

Własna aplikacja do wystawiania faktur, korekt, ofert i wezwań do zapłaty (Mahnung) dla kilku firm
w jednej instalacji. Interfejs i dokumenty po niemiecku, polsku i angielsku; waluty EUR, PLN, USD.
Logowanie hasłem i kodem 2FA (TOTP). Działa na subdomenie `faktury.panbehapowiec.pl`.

Aplikacja nie jest programem księgowym i nie zastępuje Steuerberatera. Punkty prawne, które trzeba
potwierdzić przed użyciem produkcyjnym, są zebrane w [docs/DEPLOY.md](docs/DEPLOY.md).

## Stos

- PHP 8.3 (rozszerzenia: intl, pdo, sodium, mbstring, dom, gd), bez frameworka: własne routowanie na
  FastRoute, szablony Twig.
- MySQL na produkcji, SQLite w testach i do pracy lokalnej. Kwoty jako liczby całkowite (grosze/centy).
- Dompdf (PDF z polskimi i niemieckimi znakami), PHPMailer (SMTP firmy), OTPHP + endroid/qr-code (2FA).
- Front: zwykły CSS i JS z `public/assets/`, bez inline'owych skryptów i stylów (CSP `script-src 'self'`).
- Czcionki: Barlow Condensed i Source Sans 3 na licencji SIL OFL 1.1; teksty licencji w
  `resources/fonts/OFL-Barlow.txt` i `resources/fonts/OFL-SourceSans3.txt` (kopie w `public/assets/fonts/`).

## Praca lokalna

```bash
composer install
cp config/config.example.php config/config.php      # ustaw bazę (np. driver sqlite) i app_key
php bin/migrate.php                                  # migracje
php bin/create-user.php ty@example.com --admin       # hasło z STDIN; 2FA ustawiasz przy pierwszym logowaniu
php -S 127.0.0.1:8000 -t public bin/dev-router.php   # http://127.0.0.1:8000
```

Inny plik konfiguracji: zmienna środowiskowa `FAKTURA_CONFIG=/ścieżka/config.php`.

## Testy

```bash
vendor/bin/phpunit                       # cały zestaw (ok. 6-7 minut)
vendor/bin/phpunit tests/Security        # nagłówki, dotfiles, błędy bez stack trace
```

Testy działają na SQLite. Ścieżki współbieżności (numeracja, płatności) nie były jeszcze
uruchamiane na prawdziwym MySQL; patrz lista przed uruchomieniem produkcyjnym w DEPLOY.md.

## Wydanie i wdrożenie

`bin/build-release.sh` buduje paczkę (`build/faktura-release/` i `.tar.gz`) z zależnościami
produkcyjnymi, bez `tests/` i `docs/`. GitHub Actions (`.github/workflows/deploy.yml`) robi to samo
po każdym pushu na `main` (po zielonych testach) i wypycha wynik na gałąź `deploy`.
Instrukcja: [docs/DEPLOY.md](docs/DEPLOY.md).

## Układ katalogów

`src/` kod, `templates/` Twig, `lang/` tłumaczenia (jeden plik na język), `migrations/` SQL,
`bin/` skrypty CLI, `public/` jedyny katalog widoczny z sieci, `storage/` zapisywalne dane
(`pdf-cache`, `tmp`, `logos`, `archive`: archiwalne PDF wystawionych dokumentów, wchodzi do kopii zapasowych), `resources/fonts/` czcionki PDF, `docs/` projekt i plan.
