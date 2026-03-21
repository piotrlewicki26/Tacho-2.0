# TachoPro 2.0

**System zarządzania i rozliczania czasu pracy kierowców** oparty na PHP 8+ i MySQL.

## Wymagania

| Składnik | Minimalna wersja |
|----------|-----------------|
| PHP | 8.1 |
| MySQL / MariaDB | 5.7 / 10.3 |
| Serwer WWW | Apache 2.4+ (z mod_rewrite) lub Nginx |
| PHP rozszerzenia | PDO, PDO_MySQL, mbstring, openssl, fileinfo |

## Instalacja

### 1. Wgraj pliki na serwer

Skopiuj zawartość repozytorium do katalogu publicznego serwera WWW (np. `public_html`).

### 2. Utwórz bazę danych MySQL

```sql
CREATE DATABASE tachopro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tachopro_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON tachopro.* TO 'tachopro_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Skonfiguruj połączenie z bazą danych

Skopiuj `config.example.php` jako `config.php` i uzupełnij dane:

```php
define('CFG_DB_HOST', 'localhost');
define('CFG_DB_NAME', 'tachopro');
define('CFG_DB_USER', 'tachopro_user');
define('CFG_DB_PASS', 'STRONG_PASSWORD');
```

### 4. Uruchom kreator instalacji

Przejdź do `https://twojadomena.pl/setup.php` i wypełnij formularz:
- Nazwa firmy
- Login i hasło administratora

Kreator automatycznie:
- Tworzy schemat bazy danych
- Generuje unikalny kod firmy (niemodyfikowalny)
- Tworzy konto superadmin
- Wystawia domyślną licencję Core na 1 rok

### 5. Zabezpiecz instalację

Po zakończeniu instalacji **usuń lub zablokuj dostęp** do pliku `setup.php`.

---

## Architektura systemu

```
/
├── index.php           → Przekierowanie do dashboard
├── login.php           → Logowanie (CSRF, rate-limiting, bcrypt)
├── logout.php          → Wylogowanie
├── setup.php           → Kreator instalacji (uruchom raz!)
├── dashboard.php       → Panel główny
├── drivers.php         → Zarządzanie kierowcami
├── vehicles.php        → Zarządzanie pojazdami
├── files.php           → Archiwum plików DDD
├── reports.php         → Raporty i wykresy
├── company.php         → Zarządzanie firmą
├── license.php         → Zarządzanie licencjami
├── settings.php        → Ustawienia (profil, hasło, grupy, użytkownicy)
│
├── api/
│   └── files.php       → API: upload / pobierz / usuń plik DDD
│
├── modules/
│   ├── driver_analysis/    → Analiza czasu pracy kierowcy (licencja)
│   ├── vehicle_analysis/   → Analiza danych pojazdu (licencja)
│   └── delegation/         → Moduł delegacji (licencja)
│
├── includes/
│   ├── db.php              → Połączenie PDO z bazą
│   ├── auth.php            → Uwierzytelnianie, sesje, CSRF, rate-limit
│   ├── functions.php       → Pomocnicze funkcje PHP
│   └── license_check.php   → Sprawdzanie modułów licencji
│
├── templates/
│   ├── header.php          → Nagłówek HTML (topbar + sidebar)
│   └── footer.php          → Stopka HTML (modal DDD + skrypty)
│
├── assets/
│   ├── css/style.css       → Niestandardowe style (CSS variables)
│   └── js/app.js           → JavaScript (sidebar, upload, paginacja)
│
├── sql/
│   └── schema.sql          → Schemat bazy danych
│
└── uploads/
    └── ddd/                → Przesłane pliki DDD (chronione .htaccess)
```

## Moduły i licencje

System podzielony jest na moduły:

| Moduł | Opis | Klucz licencji |
|-------|------|---------------|
| **Core** | Dashboard, Kierowcy, Pojazdy, Raporty | `mod_core` |
| **Analiza kierowcy** | Wykresy aktywności, naruszenia z pliku DDD | `mod_driver_analysis` |
| **Analiza pojazdu** | Przebieg, aktywność z pliku DDD | `mod_vehicle_analysis` |
| **Delegacje** | Obliczanie diet, rozliczenia tras | `mod_delegation` |

Licencje generowane są na podstawie **unikalnego kodu firmy** (SHA-256, generowany jednorazowo, niemodyfikowalny).

## Bezpieczeństwo

- **Hasła**: bcrypt (cost=12) z automatycznym rehashowaniem
- **SQL Injection**: wyłącznie przygotowane zapytania PDO
- **XSS**: `htmlspecialchars()` na wszystkich wyjściach
- **CSRF**: token losowy w każdym formularzu i żądaniu AJAX
- **Rate-limiting**: blokada konta po 5 nieudanych próbach (15 minut)
- **Session fixation**: `session_regenerate_id(true)` po zalogowaniu
- **Secure cookies**: HttpOnly, SameSite=Strict, opcjonalnie Secure (HTTPS)
- **Pliki DDD**: przechowywane poza webroot (`.htaccess: Deny from all`)
- **Nagłówki HTTP**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection

## Role użytkowników

| Rola | Uprawnienia |
|------|-------------|
| `superadmin` | Pełny dostęp, zarządzanie wszystkimi firmami i licencjami |
| `admin` | Zarządzanie firmą, użytkownikami, grupami |
| `manager` | Dodawanie/edycja kierowców, pojazdów, delegacji |
| `viewer` | Tylko odczyt |

## Obsługiwane formaty DDD

- `.ddd` – EU tachograph driver card
- `.c1b` – format C1B
- `.tgd` – format TGD

## Technologie

- **Backend**: PHP 8.1+ (bez frameworka)
- **Baza danych**: MySQL 5.7+ / MariaDB 10.3+
- **Frontend**: Bootstrap 5.3, Bootstrap Icons, Chart.js 4.4
- **Styl**: CSS custom properties (dark sidebar, light content)
