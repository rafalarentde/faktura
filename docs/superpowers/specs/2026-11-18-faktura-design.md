# Faktura – aplikacja do wystawiania faktur, ofert i wezwań do zapłaty (projekt)

Data: 2026-11-18. Status: **do zatwierdzenia przez właściciela**.
Dokument jest napisany tak, żeby dobry programista mógł z niego zaplanować pracę bez dopytywania. Rzeczy ustalone z właścicielem są oznaczone **[ustalone]**, moje decyzje **[moja decyzja]**, rzeczy do potwierdzenia prawnie **[do weryfikacji]**.

## 1. Cel i powód

Właściciel prowadzi firmę w Niemczech i potrzebuje własnego, wygodnego programu do fakturowania: z bazą klientów, bazą towarów i usług, dowolną numeracją i wysyłką dokumentów prosto z programu. Ma z niego korzystać 2-3 firmy (różni użytkownicy), a docelowo program ma się nadawać do sprzedaży innym firmom. Dlatego od początku budujemy go jako system wielofirmowy (multi-tenant) i wielojęzyczny, ale **bez przedwczesnej komplikacji**: sprzedażowe dodatki (rejestracja, płatności, plany) dochodzą dopiero później.

## 2. Zakres pierwszej wersji [ustalone]

- Logowanie: hasło + kod 2FA (TOTP z aplikacji uwierzytelniającej).
- Firmy z odrębnymi danymi, konta użytkowników.
- Interfejs i dokumenty w języku **niemieckim, polskim i angielskim** (przełącznik). Dodanie kolejnego języka ma polegać na dodaniu jednego pliku tłumaczeń.
- Waluty **EUR, PLN, USD**.
- Baza klientów, baza towarów i usług.
- **Faktury** i **korekty**, PDF do pobrania, wysyłka mailem z aplikacji, statusy płatności.
- **Oferty (Angebote)** z zamianą na fakturę jednym kliknięciem.
- **Wezwania do zapłaty (Mahnungi)**: przypomnienie, 1., 2. i ostatnie wezwanie. **Wysyłane ręcznie**, program tylko podpowiada, co i komu wysłać. Odsetki za zwłokę opcjonalne, włączane świadomie przy każdym wezwaniu.
- VAT: normalne zasady (bez Kleinunternehmer). Niemcy 19% i 7%. Klient z UE z ważnym numerem VAT-UE: Reverse-Charge, 0% z adnotacją.
- Wygląd zgodny z Panem Behapowcem (kolory, czcionki, jasny i ciemny motyw). Szczegóły graficzne (układ PDF, logo, ozdobniki) ustalamy później.

### 2a. Model sprzedaży i dostęp po opłaceniu [ustalone]

Aplikacja działa na subdomenie Pana Behapowca (zapis właściciela: „faktury pan.bhpowiec.pl", do potwierdzenia dokładna nazwa, zakładam `faktury.panbehapowiec.pl`). Firma zewnętrzna dostaje dostęp **dopiero po dokonaniu płatności**. Wzorcem użytkowym jest sevDesk, z którego właściciel korzysta dziś (sevDesk ma bezpłatny plan z limitem 3 faktur miesięcznie i płatny plan od kilkunastu euro miesięcznie, czyli szanse na porównywalną ofertę są realne, ale cennik ustala właściciel).

W pierwszej wersji:
- Każda firma ma pole **status dostępu**: `aktywna`, `wstrzymana` (brak płatności), `próbna` (opcjonalnie), plus datę ważności dostępu.
- Dostęp włącza i wyłącza **administrator ręcznie** (po otrzymaniu płatności np. przelewem lub PayPalem). Firma wstrzymana może się zalogować i **pobrać swoje dane i dokumenty**, ale nie wystawia nowych. Danych nie blokujemy jako zakładnika, bo faktury są jej dokumentami księgowymi.
- Model danych i ustawienia od razu przewidują **plan** firmy (nazwa planu, limity, np. liczba dokumentów miesięcznie lub liczba użytkowników), ale bez ich egzekwowania, dopóki właściciel nie ustali oferty.

Później (osobny etap, **nie wchodzi do pierwszej wersji**): samodzielna rejestracja nowych firm, automatyczne płatności (np. Stripe lub PayPal), automatyczne włączanie i wyłączanie dostępu, faktury za abonament wystawiane przez samą aplikację.

### 2b. Reklamy w planie darmowym [moja decyzja, do zatwierdzenia]

Właściciel myślał o zarabianiu na reklamach w planie darmowym. Nie budujemy ich w pierwszej wersji. W układzie ekranów zostawiamy jedno **miejsce na baner partnerski** (domyślnie wyłączone), a w planie darmowym użyjemy na razie własnych komunikatów („przejdź na plan płatny"). Jeśli reklamy wejdą później, to jako **oznaczone „Werbung/Anzeige" polecenia partnerów powiązane z firmą** (np. konto firmowe, Steuerberater online, ubezpieczenie), bez zewnętrznych sieci reklamowych i bez skryptów śledzących. Reklam nigdy nie umieszczamy w PDF-ach wysyłanych klientom. Uzasadnienie w rozmowie z właścicielem: niskie przychody z wyświetleń, kwestie RODO/zgód i zaufanie użytkowników do programu z danymi finansowymi. **[do weryfikacji]** wymogi dotyczące zgód i oznaczania reklam przed jakimkolwiek uruchomieniem.

## 3. Poza zakresem (na później)

Faktury cykliczne, faktury proforma, e-faktura ZUGFeRD/XRechnung (**model danych ma ją umożliwiać**), samodzielna rejestracja nowych firm, płatności za program, import z innych programów, księgowość (konta, JPK, DATEV), integracje z bankiem.

## 4. Czego to ma NIE robić (anty-cele)

- Nie jest programem księgowym i nie zastępuje Steuerberatera.
- Wystawionej faktury nie można usunąć ani edytować treści. Można ją tylko skorygować dokumentem korygującym.
- Nic nie wysyła się samo (faktury, oferty, wezwania). Zawsze świadome kliknięcie użytkownika.
- Użytkownik jednej firmy nigdy nie widzi danych innej firmy. To musi być sprawdzane w kodzie przy każdym zapytaniu, nie tylko w interfejsie.

## 5. Ograniczenia

- Hosting: obecny Hostinger (PHP + MySQL), wgrywanie plików jak przy stronie Pana Behapowca. Bez założenia, że jest dostęp SSH. Zależności (vendor/) dostarczamy zbudowane.
- Kod ma się dać przenieść na VPS bez przepisywania: konfiguracja w jednym pliku ustawień, standardowy SQL, brak zależności od panelu Hostingera.
- Adres aplikacji: subdomena Pana Behapowca [ustalone], konfigurowalna w pliku ustawień. Dokładna nazwa do potwierdzenia (pkt 12).
- Dane finansowe cudzych firm: zabezpieczenia traktujemy poważnie od pierwszego dnia (pkt 9).

## 6. Podejścia, które rozważyłam

1. **Lekki PHP z kilkoma sprawdzonymi bibliotekami** (Twig, Dompdf, PHPMailer, biblioteka TOTP, kod QR) **– wybrane.** Działa na obecnym hostingu, mało ruchomych części, łatwo wgrać i przenieść. Minus: własne routowanie i struktura, więc trzeba trzymać dyscyplinę w kodzie.
2. **Laravel.** Dużo gotowych elementów (konta, kolejki, migracje). Minusy: cięższy, wymaga SSH/Composera na serwerze lub starannego pakowania, więcej magii dla początkującego właściciela przy późniejszych zmianach.
3. **React + Node + PostgreSQL na VPS.** Nowocześniejszy i dobry pod sprzedaż w skali, ale wymaga administrowania serwerem i nowych kosztów. Odrzucone na start, właściciel wybrał obecny hosting. Do rozważenia, gdy pojawią się płacący klienci spoza jego firm.

## 7. Model danych (zarys)

Każda tabela biznesowa ma `company_id`. Kwoty trzymamy jako liczby całkowite (grosze/centy) plus kod waluty. Nigdy float.

- `users`: e-mail, hash hasła (Argon2id), sekret TOTP (zaszyfrowany), kody awaryjne (zahaszowane), język interfejsu, rola systemowa (admin/zwykły).
- `companies`: nazwa, adres, Steuernummer, USt-IdNr, IBAN, BIC, bank, logo, domyślny język dokumentów, domyślna waluta, domyślny termin płatności, ustawienia SMTP (hasło zaszyfrowane), teksty domyślne (wstęp, zakończenie, stopka), stawka odsetek i ustawienia ryczałtu.
- `company_users`: kto należy do której firmy i z jaką rolą (właściciel/użytkownik).
- `customers`: dane adresowe, kraj, NIP/USt-IdNr, flaga czy firma, e-mail, domyślny język dokumentów, waluta, termin płatności, notatki. Wynik ostatniej weryfikacji VIES z datą.
- `items`: nazwa, opis, jednostka, cena netto, waluta, stawka VAT, typ (towar/usługa). Nazwa i opis w wielu językach (opcjonalnie, z domyślnym).
- `documents`: wspólna tabela dla faktur, korekt, ofert i wezwań, z polem `type`, `status`, `number`, `language`, `currency`, `exchange_rate`, daty (wystawienia, sprzedaży/wykonania usługi, płatności), `related_document_id` (korekta -> faktura, faktura -> oferta, wezwanie -> faktura), kopia danych sprzedawcy i nabywcy w momencie wystawienia (snapshot), sumy, adnotacja podatkowa.
- `document_lines`: pozycje (snapshot: nazwa, opis, ilość, jednostka, cena, rabat %, stawka VAT).
- `payments`: data, kwota, uwagi, powiązanie z fakturą (obsługa płatności częściowych).
- `number_sequences`: firma + typ dokumentu + (opcjonalnie rok) + format + licznik.
- `email_log`: do kogo, kiedy, jaki dokument, wynik wysyłki.
- `audit_log`: logowania, wystawienia, wysyłki, zmiany ustawień (kto, kiedy).

## 8. Zachowanie

### 8.1 Cykl życia dokumentu
Faktura: `szkic` -> `wystawiona` (nadany numer, zablokowana) -> `częściowo opłacona` / `opłacona`. Status „po terminie" jest wyliczany z terminu płatności, a nie ustawiany ręcznie. Szkic można usuwać. **Numer nadawany dopiero przy wystawieniu**, w transakcji z blokadą, żeby nie powstawały dziury w numeracji. Korekta to osobny dokument powiązany z fakturą, który ją wyzerowuje lub zmienia.
Oferta: `szkic` -> `wysłana` -> `zaakceptowana` / `odrzucona` / `wygasła`. Zaakceptowaną ofertę można zamienić na fakturę (kopiuje pozycje i klienta).

### 8.2 Numeracja [ustalone: w pełni edytowalna]
Format jest zwykłym tekstem z symbolami, ustawianym osobno dla firmy i rodzaju dokumentu (faktura, oferta, wezwanie, korekta). Dozwolone: dowolny własny tekst, litery, cyfry, myślniki i ukośniki, symbole daty `{YYYY}`, `{YY}`, `{MM}`, `{DD}` oraz licznik `{N}`, `{NN}`, `{NNN}` (liczba liter N to liczba cyfr z zerami z przodu). Przykłady: `RE-{YYYY}-{NNN}`, `FV/{YY}/{MM}/{NNNN}`. Edytor w interfejsie pokazuje na żywo następny numer i podpowiada symbole przyciskami. Licznik może zerować się co rok lub nie. Program pilnuje unikalności numeru i kolejności. Przy zmianie formatu w trakcie roku pokazuje ostrzeżenie o ciągłości numeracji. **[do weryfikacji]** wymagania §14 UStG (numer jednorazowy i ciągły).

### 8.3 VAT i waluty
- Stawka VAT jest ustawiana na pozycji. Program liczy sumy per stawka.
- Klient z innego kraju UE, firma, z ważnym numerem VAT-UE -> 0% VAT, adnotacja Reverse-Charge w języku dokumentu, numery VAT obu stron. Numer VAT-UE klienta można sprawdzić w VIES (wynik zapisujemy z datą). Brak numeru lub osoba prywatna -> niemiecki VAT.
- Dokument w PLN lub USD: pole kursu przeliczenia i kwota VAT w EUR. **[do weryfikacji]** dokładne wymagania co do kursu (źródło, dzień) i adnotacji ze Steuerberaterem.
- Zaokrąglanie: liczymy w groszach, zaokrąglenie stawki VAT per pozycja lub per suma ustalimy po sprawdzeniu praktyki, wybór wpisany w ustawieniach.

### 8.4 Wezwania do zapłaty [ustalone: ręcznie]
Ekran „Zaległe faktury" pokazuje wszystkie faktury po terminie z kolumnami: dni po terminie, ostatnio wysłane wezwanie, **podpowiedź kolejnego kroku** (np. po 7 dniach przypomnienie, po 21 pierwsze wezwanie, po 35 drugie, po 49 ostatnie). Progi dni są ustawiane per firma. Użytkownik wybiera fakturę, podgląda PDF i wiadomość, dopiero wtedy klika „wyślij". W formularzu wezwania są dwa osobne przełączniki, **domyślnie wyłączone** [ustalone]:
- **Odsetki za zwłokę** (stawka z ustawień firmy, edytowalna, bo podstawowa stopa procentowa zmienia się co pół roku; podpowiedź: podstawa + 9 pkt dla B2B, 5 pkt dla konsumentów).
- **Ryczałt 40 €** (tylko B2B).
Opcjonalnie stała opłata za wezwanie. **[do weryfikacji]** §288 BGB i praktyka dla tych pozycji.

### 8.5 Wysyłka mailem
Przez skrzynkę SMTP domeny danej firmy. Wiadomość w języku dokumentu (szablony DE/PL/EN, edytowalne). Podgląd przed wysłaniem, PDF w załączniku, wpis w `email_log`, możliwość ponownej wysyłki. Przy błędzie SMTP czytelny komunikat.

### 8.6 Języki
Dwa niezależne ustawienia: język **interfejsu** (per użytkownik) i język **dokumentu** (per klient z możliwością zmiany na dokumencie). Pliki tłumaczeń `de`, `pl`, `en`. Daty, liczby i waluty formatowane według języka dokumentu.

## 9. Bezpieczeństwo

Hasła Argon2id. TOTP (RFC 6238) z kodem QR przy konfiguracji i kodami awaryjnymi. 2FA wymagane dla każdego konta, bez wyjątków. Ograniczanie prób logowania. Ochrona CSRF, sesje z flagami `HttpOnly`/`Secure`/`SameSite`, zapytania przygotowane (PDO), escapowanie wyjścia w szablonach. Sekrety (TOTP, hasła SMTP) szyfrowane kluczem trzymanym poza webrootem. Plik konfiguracyjny i kod poza katalogiem publicznym. Wszystkie zapytania filtrowane po `company_id`, z testami sprawdzającymi brak wycieku między firmami. Kopie zapasowe: eksport bazy w panelu administratora i kopie Hostingera. Dane faktur trzeba przechowywać zgodnie z przepisami (**[do weryfikacji]** okres, obecnie podawany jako 8 lat), więc nie ma trwałego usuwania wystawionych dokumentów.

## 10. Wygląd

Polskie znaki: czcionki muszą mieć pełny zestaw Latin Extended (ą, ę, ł, ś, ż, ć, ń, ó, ź) **zarówno w interfejsie, jak i w PDF**. W makiecie błąd polegał na wbudowaniu samego podzbioru łacińskiego. Do PDF używamy plików TTF z pełnym zestawem znaków, a obecność polskich liter sprawdzamy testem generowania PDF.

Makieta (wyrzucany prototyp, nie w repo) pokazuje: przegląd, edytor faktury z podglądem PDF, oferty, wezwania, klientów, towary i usługi, ustawienia (firma, numeracja, wezwania, e-mail, bezpieczeństwo), widok administratora z włączaniem dostępu po płatności oraz logowanie z kodem 2FA.

Kolory i czcionki z repozytorium Pana Behapowca: żółty `#F5C400` (jaśniejszy `#ffd633`), czerń `#0f0f0f`, grafity `#1b1b1b` i `#2a2a2a`, tło `#f4f2ea`, tekst `#2b2b2b`, tekst pomocniczy `#636363`. Statusy: zielony `#1f8a43`, pomarańczowy `#d68a00`, czerwony `#c4351f`. Czcionki: Barlow Condensed (nagłówki) i Source Sans 3 (tekst), pliki są w repo `pan-behapowiec` (`panbehapowiec/fonts`, `logo/fonty`). Jasny i ciemny motyw przez zmienne CSS. Interfejs działa na telefonie i tablecie (właściciel często pracuje z tabletu).

## 11. Etapy budowy

1. **Fundament:** struktura projektu, konfiguracja, logowanie, 2FA, firmy, użytkownicy, tłumaczenia, motyw graficzny.
2. **Bazy:** klienci (z weryfikacją VIES), towary i usługi, ustawienia firmy i numeracji.
3. **Faktury:** szkic -> wystawienie, VAT i waluty, PDF, wysyłka mailem, płatności, korekty.
4. **Oferty:** szablon, wysyłka, statusy, zamiana na fakturę.
5. **Wezwania do zapłaty:** lista zaległych, podpowiedzi, PDF i wysyłka, opcjonalne odsetki i ryczałt.
6. **Po tym:** przegląd bezpieczeństwa, test z prawdziwymi danymi jednej firmy, wdrożenie.

Każdy etap kończy się działającym fragmentem, który właściciel może wypróbować.

## 12. Do ustalenia z właścicielem

- Dokładna nazwa subdomeny (zakładam `faktury.panbehapowiec.pl`).
- Które elementy sevDesk właściciel chce odtworzyć, a które mu przeszkadzają (wzorzec układu ekranów).
- Dane trzech firm do konfiguracji początkowej (dopiero w etapie 2, nie blokuje startu).

## 13. Pozostawione budowniczemu

Struktura katalogów, konkretny wybór bibliotek w ramach pkt 6.1, szczegóły układu PDF, treść domyślnych szablonów maili i tekstów wezwań (po trzech językach, do akceptacji właściciela), wybór metody zaokrągleń VAT po konsultacji z praktyką, sposób pakowania i wgrywania na Hostinger (np. GitHub Actions jak w istniejącej stronie).
