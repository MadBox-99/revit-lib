# Revit elemtár WordPress plugin — tervezési specifikáció

**Dátum:** 2026-05-19
**Megrendelő:** Cegem360 (info@cegem360.hu)
**Plugin slug:** `cegem360-revit-library`
**Plugin verzió (tervezett):** 1.0.0

---

## 1. Áttekintés

A plugin egy WordPress weboldalon teszi lehetővé, hogy a Cegem360 Revit elemtárát látogatók űrlap kitöltése után emailben kapott, időkorlátos letöltési linkkel tölthessék le. A beküldések egy WordPress admin felületen listázhatók és exportálhatók.

### Felhasználói folyamat (golden path)

1. Látogató felmegy a `/revit-elemtar/` oldalra (vagy bármilyen oldalra, ahova a shortcode el lett helyezve)
2. Kitölti az űrlapot: cégnév, email, telefonszám, GDPR pipa
3. Beküldi → AJAX validáció → siker visszajelzés az oldalon
4. Háttérben kiküldésre kerül két email:
   - **Látogatónak:** időkorlátos letöltési link
   - **Adminnak:** értesítés az új beküldésről
5. A látogató a linkre kattintva letölti a Revit elemtárat tartalmazó ZIP fájlt
6. A link 7 napig (alapértelmezett, állítható) érvényes, többször is letölthető a token segítségével

### Admin folyamat

- Az admin az új beküldésekről emailben értesül
- A WP admin felületen megnézheti, kereshet, szűrhet, exportálhat
- A `source/` mappába új Revit fájlokat tölthet fel — a plugin automatikusan újragenerálja a letölthető ZIP-et

---

## 2. Plugin felépítése (fájlstruktúra)

```
cegem360-revit-library/
├── cegem360-revit-library.php       Fő plugin fájl (header, aktiváció, deaktiváció)
├── uninstall.php                    Plugin teljes törlésekor adattisztítás
├── readme.txt                       WP.org formátumú readme
├── includes/
│   ├── class-plugin.php             Bootstrap, hook-ok regisztrálása
│   ├── class-form.php               Shortcode, frontend form render + AJAX submit
│   ├── class-submissions.php        DB műveletek: beküldések CRUD
│   ├── class-tokens.php             Letöltési tokenek, lejárat-kezelés
│   ├── class-zip-manager.php        ZIP fájl regenerálás, méret-info
│   ├── class-mailer.php             Email küldés, sablon-renderelés
│   ├── class-admin.php              Admin menük, oldalak, beállítások mező-regisztráció
│   ├── class-submissions-list-table.php   WP_List_Table extension a beküldéseknek
│   ├── class-file-manager.php       Forrásfájlok feltöltése, törlése
│   ├── class-download-handler.php   `?crl_download=...` URL feldolgozása, streamelés
│   ├── class-rate-limiter.php       IP-alapú rate limit (transient API)
│   └── helpers.php                  Általános segédfüggvények (escape, sanitize, etc.)
├── assets/
│   ├── css/
│   │   ├── form.css                 Frontend form stílus
│   │   └── admin.css                Admin felület stílus
│   └── js/
│       ├── form.js                  Frontend AJAX submit + validáció
│       └── admin.js                 Admin: file upload, regenerate ZIP, AJAX
├── templates/
│   ├── form.php                     Frontend form HTML
│   ├── email-download-link.php      Látogatónak menő email (HTML)
│   └── email-admin-notification.php Adminnak menő értesítő (HTML)
└── languages/                       .po/.mo fájlok (i18n: text domain = "cegem360-revit-library")
```

**Külső függőség: nincs.** Csak WP core + PHP `ZipArchive` (PHP-vel együtt szállítva).

### Tervezési elv

Minden osztálynak egy felelőssége van, dependency injection a `class-plugin.php` bootstrap-ben. Az osztályok unit-szinten tesztelhetőek. Statikus függőség nincs (egy-egy osztály példányát kapja a többi).

---

## 3. Adatbázis-séma

Aktiváláskor két új tábla jön létre. WordPress `$wpdb->prefix` előtaggal (multisite-kompatibilis).

### `{prefix}_crl_submissions`

| oszlop | típus | leírás |
|---|---|---|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT, PK | |
| `company_name` | VARCHAR(255), NOT NULL | Cégnév |
| `email` | VARCHAR(255), NOT NULL | Email cím |
| `phone` | VARCHAR(50), NOT NULL | Telefonszám |
| `ip_address` | VARCHAR(45) | IPv4/IPv6, audit célból |
| `user_agent` | TEXT | Böngésző user agent, audit |
| `gdpr_accepted` | TINYINT(1), DEFAULT 0 | 0 / 1 |
| `gdpr_accepted_at` | DATETIME NULL | Mikor fogadta el |
| `email_status` | ENUM('pending','sent','failed'), DEFAULT 'pending' | Email kiküldés státusza |
| `created_at` | DATETIME, NOT NULL | Beküldés időpontja (UTC) |

**Indexek:**
- INDEX `email` (gyors keresés)
- INDEX `created_at` (rendezés / dátum-szűrés)

### `{prefix}_crl_tokens`

| oszlop | típus | leírás |
|---|---|---|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT, PK | |
| `submission_id` | BIGINT UNSIGNED, FK | A kapcsolódó beküldés |
| `token` | VARCHAR(64), UNIQUE | 64 karakteres hex token |
| `expires_at` | DATETIME, NOT NULL | Lejárat időpontja (UTC) |
| `download_count` | INT UNSIGNED, DEFAULT 0 | Hányszor töltötte le |
| `last_downloaded_at` | DATETIME NULL | Utolsó letöltés ideje |
| `created_at` | DATETIME, NOT NULL | Token létrehozás ideje |

**Indexek:**
- UNIQUE INDEX `token`
- INDEX `submission_id`
- INDEX `expires_at` (cleanup-hoz)

### Adatmegőrzés és törlés

- **Plugin deaktiválás:** adatok megmaradnak
- **Plugin törlés (`uninstall.php`):** a beállításokban lévő „Adatok törlése eltávolításkor" opció dönt — default: **NE töröljön**
- **Automatikus retenció:** a beállításokban opcionálisan bekapcsolható „Beküldések törlése X nap után" (default: kikapcsolva, ha be: 365 nap). Napi WP-cron taszk végzi a takarítást.

---

## 4. Fájltárolás és ZIP-kezelés

### Tárolási helyek

```
/wp-content/uploads/crl-private/
├── source/                  A feltöltött Revit fájlok ide kerülnek
├── zips/
│   └── revit-elemtar.zip    A legenerált, letölthető ZIP
├── .htaccess                "Deny from all" (Apache)
└── index.php                Üres (mappa listázás ellen)
```

### Hozzáférés-védelem

- A `.htaccess` Apache-on blokkolja a közvetlen elérést.
- Nginx esetén külön dokumentációs útmutatást ad a plugin a beállítások oldalán (location block javaslat).
- **A védelem fő pillére: a fájlokat soha nem a webszerver szolgálja ki közvetlenül, hanem mindig a PHP `readfile()` token-ellenőrzés után.**
- Ha valaki kitalálja a fájl URL-jét, `.htaccess` blokkolja Apache-on. Nginx-en a PHP elérés a kötelező út, mert az NGINX nem szolgál ki közvetlenül onnan, ha a webgyökér nem oda mutat (a `/wp-content/uploads/...` mappa kiszolgálva van — Nginx-en ezért külön location block szükséges).

### ZIP-generálás

- **Trigger:** új fájl feltöltése / törlése / „Regenerálás most" gomb az adminban
- **Lépések:**
  1. `source/` mappa tartalmának begyűjtése
  2. Új ZIP készítése `zips/revit-elemtar.zip.tmp` néven (`ZipArchive`)
  3. Ha sikeres, átnevezés `zips/revit-elemtar.zip`-re (atomic swap, ne legyen részlegesen kész fájl)
  4. Méret elmentése `crl_zip_size` és `crl_zip_generated_at` options-be
- **Async-e?** Az MVP-ben szinkron, az admin felületen várakozik. Ha kiderül, hogy 30s-nél tovább tart, a következő iterációban WP-cron taszkba kerül.

### Letöltés streamelése

A `class-download-handler.php` a WordPress `init` hook-ra köt rá. Ha a query string-ben `crl_download=TOKEN` van:

1. Token keresése DB-ben
2. Validáció (létezik? nem járt-e le?)
3. Sikeres esetén:
   - `download_count++`, `last_downloaded_at = NOW()`
   - HTTP header-ek: `Content-Type: application/zip`, `Content-Disposition: attachment; filename="revit-elemtar.zip"`, `Content-Length: <bytes>`
   - `readfile()` chunkokban
   - `exit;`
4. Sikertelen esetén: WP `wp_die()` érthető hibaüzenettel (404 / 410), magyar nyelvű hibaszöveg

**Nagy fájlok (későbbi optimalizáció):**
A handler úgy van megírva, hogy később hozzáadható legyen `X-Sendfile` (Apache) / `X-Accel-Redirect` (Nginx) támogatás. Most az MVP `readfile()`-t használ, ami 500 MB-ig stabil.

---

## 5. Frontend űrlap és shortcode

### Shortcode

```
[revit_library_form]
```

Opcionális attribútumok:
- `title="Revit elemtár letöltése"` — felülírja a beállításokban megadott címet
- `button_text="Letöltés kérése"` — felülírja a gomb feliratot

### Form mezők

| mező | HTML típus | validáció | kötelező |
|---|---|---|---|
| Cégnév | `<input type="text" name="company_name">` | min. 2 karakter, max 255 | ✔ |
| Email | `<input type="email" name="email">` | `is_email()` | ✔ |
| Telefon | `<input type="tel" name="phone">` | regex: csak szám, `+`, szóköz, `-`, `(`, `)`; min. 6 számjegy | ✔ |
| GDPR | `<input type="checkbox" name="gdpr">` | csak `1` elfogadott | ✔ |
| Honeypot | `<input type="text" name="crl_website" style="display:none">` | ha kitöltve → silent reject | — |
| Nonce | rejtett, `wp_nonce_field('crl_submit')` | — | — |

### Submit folyamat

1. Felhasználó kattint a „Letöltés kérése" gombra
2. JS:
   - Kliens-oldali validáció (HTML5 + saját ellenőrzés)
   - Loading state a gombon (spinner + „Küldés…")
   - `fetch()` POST a `/wp-admin/admin-ajax.php`-re: `action=crl_submit_form`
3. PHP (`class-form.php::handle_submit`):
   1. Nonce ellenőrzés (`check_ajax_referer`)
   2. Honeypot — ha kitöltve, csendes 200 OK (bot ne tudja, hogy lebukott)
   3. Mezők sanitizálása (`sanitize_text_field`, `sanitize_email`)
   4. Validáció (email, telefon-formátum, GDPR pipa)
   5. Rate limit ellenőrzés (`class-rate-limiter.php` — transient-alapú IP számláló)
   6. Submission rekord létrehozása, `email_status='pending'`
   7. Token generálás (`bin2hex(random_bytes(32))`), expires_at számítás
   8. Token rekord létrehozása
   9. `Mailer::send_visitor_email()` → siker esetén `email_status='sent'`, hiba esetén `'failed'`
  10. `Mailer::send_admin_notification()` (külön try/catch, ha ez bukik is, a látogatónak már elment)
  11. JSON válasz: `{ success: true, message: "..." }` vagy `{ success: false, errors: {...} }`
4. JS sikerre cseréli a form helyén megjelenő üzenetre

### Akadálymentesség / UX

- Minden mezőhöz `<label>`, `aria-describedby` a hibaüzenethez
- HTML5 + JS kettős validáció
- Hibaüzenet a mező alatt, piros keret
- Loading state a gombon
- Sikerüzenet az űrlap helyén jelenik meg (nem alert/popup)

### Stílusozás

- CSS változókra építve (`--crl-primary-color`, `--crl-border-radius`, `--crl-text-color`)
- Default: semleges, modern, szürke-fehér paletta
- Az elsődleges szín admin beállításból állítható (CSS változó inline `<style>`-ban renderelve)

### Aktiváláskor létrehozott oldal

- Slug: `revit-elemtar`
- Cím: „Revit elemtár letöltése"
- Tartalom: bevezető bekezdés + `[revit_library_form]` shortcode
- **Csak akkor jön létre, ha a slug még nem létezik** — meglévő oldalt nem ír felül
- A létrehozott oldal `post_meta`-jában elmenti, hogy a plugin hozta létre — uninstall-kor opcionálisan törölheti, ha a beállítás engedi

---

## 6. Email küldés és letöltési token

### Email a látogatónak

- **Címzett:** a beküldő email címe
- **Tárgy** (sablon, állítható): `Revit elemtár letöltése — {cegnev}`
- **Tartalom (HTML, plain text fallback automatikusan):**
  - Köszönő üzenet
  - Letöltési link (gomb + sima link is)
  - Lejárati információ („{N} napig érvényes")
  - Cégem360 lábléc
- **Placeholder-ek:** `{cegnev}`, `{download_link}`, `{expires_days}`, `{expires_date}`
- **Sablon-szerkesztés:** a beállítások oldalon WP rich text editor

### Email az adminnak

- **Címzett:** a beállításokban megadott email (default: `info@cegem360.hu`)
- **Tárgy:** `Új Revit elemtár igénylés — {cegnev}`
- **Tartalom:** beküldés adatai + link az admin felületre

### Email küldés technikailag

- `wp_mail()` használata
- HTML email header-rel (`Content-Type: text/html; charset=UTF-8`)
- Plain text fallback: `wp_strip_all_tags()` az HTML-en
- **Visszatérési érték kezelése:**
  - `wp_mail()` true / false-t ad — false esetén `email_status='failed'` a submission rekordon
  - A `wp_mail_failed` action hook-ra is rákötünk, hogy a hibaüzenetet logoljuk a PHP error log-ba

### Letöltési token

- **Generálás:** `bin2hex(random_bytes(32))` → 64 karakter, kriptográfiailag biztonságos
- **URL formátum:** `https://example.com/?crl_download=<TOKEN>`
- **Validáció:** lásd 4. szekció „Letöltés streamelése"
- **Token megújítás:** admin felületen egy gombbal generálható új token ugyanahhoz a beküldéshez, és újra kiküldhető emailben

### Hibakezelés

| eset | reakció |
|---|---|
| `wp_mail()` false | submission létrejön, `email_status='failed'`, admin felületen „Email újraküldés" gomb |
| ZIP nem létezik letöltéskor | `wp_die('A fájl jelenleg nem érhető el. Kérjük, vegye fel a kapcsolatot velünk.')` + admin notice |
| Token nem található / lejárt | `wp_die('A link érvénytelen vagy lejárt. Kérjük, igényeljen újat.')` |
| Rate limit | JSON hiba: „Túl sok kérés. Próbálja újra X perc múlva." |

---

## 7. Admin felület

### Menüstruktúra

```
Revit elemtár (főmenü)
├── Beküldések      (alapértelmezett)
├── Fájlkezelő
└── Beállítások
```

Az ikon: `dashicons-download` vagy `dashicons-portfolio` (egyértelmű, beépített WP ikon).

### 7.1 Beküldések oldal

WP `WP_List_Table` osztályra épülő tábla a következő oszlopokkal:

| Cégnév | Email | Telefon | Beküldés | Email | Letöltések | Műveletek |

- **Keresés:** cégnév / email
- **Szűrés:** email státusz (mind / sent / failed / pending), dátum-tartomány
- **Lapozás:** 20 / oldal (állítható)
- **Tömeges műveletek:** törlés (megerősítéssel)
- **Soronkénti műveletek:**
  - **Részletek:** modal vagy aloldal a teljes infóval (IP, user agent, GDPR pipa időpontja, kapcsolódó tokenek listája, letöltési időbélyegek)
  - **Token megújítás:** új tokent generál + emailt küld
  - **Email újraküldés:** csak ha `email_status='failed'`
  - **Törlés:** megerősítéssel

**CSV export:** a tábla feletti gomb, a jelenlegi szűrőket figyelembe véve. Oszlopok: cégnév, email, telefon, beküldés ideje, email státusz, letöltések száma.

### 7.2 Fájlkezelő oldal

- **Drag & drop upload area** (több fájl, böngésző-natív API)
- **Engedélyezett típusok:** `.rfa`, `.rvt`, `.rte`, `.rft`, `.zip`, `.pdf`, `.jpg`, `.png` (beállításokban állítható)
- **Fájllista táblázatban:** név, méret, feltöltés dátuma, [Törlés] gomb
- **ZIP státusz panel** felül:
  - „Legutóbb generálva: 2026-05-19 14:00"
  - „Méret: 124 MB"
  - „Forrásfájlok száma: 47"
  - [Regenerálás most] gomb
  - [Letöltés előnézethez] gomb (csak adminnak)
- **Auto-regenerálás:** új feltöltés / törlés után a ZIP automatikusan frissül, admin notice tájékoztat

### 7.3 Beállítások oldal

**Általános fül:**
- Értesítési email (default: `info@cegem360.hu`)
- Feladó név (default: site name)
- Feladó email (default: admin email)
- Letöltési link érvényesség napokban (default: 7, min: 1, max: 365)
- Rate limit: beküldés / IP / óra (default: 3)

**Form testreszabás fül:**
- Cím
- Bevezető szöveg (WP editor)
- GDPR szöveg + adatvédelmi tájékoztató oldal (oldal-választó dropdown)
- Sikerüzenet szövege
- Elsődleges szín (color picker)

**Email sablonok fül:**
- Látogatói email tárgy
- Látogatói email HTML (WP editor, placeholder lista)
- Admin email tárgy
- Admin email HTML

**Adatvédelem fül:**
- Beküldések automatikus törlése X nap után (default: kikapcsolva)
- Adatok törlése plugin eltávolításakor (default: kikapcsolva)
- Aktiváláskor létrehozott oldal törlése eltávolításkor (default: kikapcsolva)

**Diagnosztika panel** (mindegyik fül alján):
- PHP verzió ✔ / ✗
- `ZipArchive` elérhető ✔ / ✗
- Mappák írhatóak ✔ / ✗
- [Teszt email küldés] gomb (az értesítési címre)

---

## 8. Biztonsági szempontok

- **Nonce minden form-on és AJAX hívásnál**
- **Capability ellenőrzés** minden admin műveletnél: `manage_options` (alapból csak adminok)
- **SQL injection** elleni védelem: `$wpdb->prepare()` minden query-nél
- **XSS:** minden user input `esc_html()` / `esc_attr()` kimenetnél, `sanitize_*()` bemenetnél
- **CSRF:** nonce
- **Path traversal:** a fájlkezelő `realpath()` ellenőrzéssel biztosítja, hogy a feltöltött / törölt fájl a `source/` mappán belül van
- **Token entrópia:** 256 bit, `random_bytes()`
- **Rate limit:** IP-alapú, transient API
- **GDPR audit:** `gdpr_accepted_at` mező + IP cím rögzítése bizonyítja a hozzájárulást
- **Hozzájárulás visszavonás:** a beküldő emailben kérheti az adatai törlését (manuális, mert nincs felhasználói login). Az admin felületen 1 kattintással törölhető.

---

## 9. Internationalization (i18n)

- Text domain: `cegem360-revit-library`
- Minden felhasználónak megjelenő string `__()` / `_e()` / `esc_html__()` függvényekkel
- **Forrás-stringek nyelve a kódban: angol** (WP standard)
- `languages/` mappa, magyar (`hu_HU`) fordítás alapból mellékelve — a céloldal nyelvi beállítása szerint magyarul jelenik meg
- A beállításokban szerkeszthető szövegek (email sablonok, form címek, bevezetők) magyar alapszöveggel vannak feltöltve aktiváláskor

---

## 10. Tesztelési stratégia

Az implementációs tervben részletezve. Röviden:

- **Manuális teszt:** lokális WP környezet, golden path + edge case-ek
- **Unit teszt** (opcionális, ha az implementáció során egyértelmű szétválaszthatóság látszik): `class-tokens.php`, `class-zip-manager.php`, `class-rate-limiter.php` — ezek pure-PHP osztályok, WP-mock nélkül tesztelhetőek
- **Integrációs teszt:** a teljes folyamat (form submit → email kiküldés → letöltés) végigjátszva manuálisan
- **Biztonsági teszt:** path traversal próba, token-találgatás, nonce-mellőzés, capability-bypass

---

## 11. Mit NEM csinálunk (YAGNI)

- Több ZIP / kategóriánkénti letöltés (egy ZIP-ben mindenki ugyanazt kapja)
- Verzionált elemtár (Revit 2022/2023/2024 külön) — szükség esetén későbbi feature
- Felhasználói login / regisztráció
- Külső CRM / Mailchimp integráció
- Google reCAPTCHA — egyelőre csak honeypot
- Értesítés a tulajdonosnak, amikor a látogató letöltötte a fájlt
- Multi-language form (csak magyar + angol fallback fordítás az i18n-en keresztül)
- REST API endpoint-ok (csak admin-ajax)

Ezeket szándékosan kihagyjuk az MVP-ből. Mindegyik később hozzáadható az alkalmazott architektúrában.

---

## 12. Sikerkritériumok

A plugin akkor tekinthető késznek, ha:

1. A `/revit-elemtar/` oldalon az űrlap megjelenik, kitölthető, beküldhető
2. Sikeres beküldés után a látogató és az admin is megkapja az emailt
3. A letöltési link működik, ZIP letölthető, lejárat után érvénytelen
4. Az admin felületen a beküldések listázódnak, kereshetők, exportálhatók
5. A fájlkezelőben fájl feltölthető / törölhető, a ZIP automatikusan újragenerálódik
6. Minden beállítás állítható az admin felületen, és életbe lépnek
7. A honeypot kiszűri a látható botokat
8. A rate limit megakadályozza a tömeges beküldést
9. Biztonsági alapok (nonce, capability, SQL injection, XSS, path traversal védelem) működnek

---

## 13. Nyitott kérdések (a megrendelő felé)

Ezek nem blokkolják a tervet, de jó lenne tisztázni az implementáció előtt:

1. **Várható elemtár-méret?** (Ha > 500 MB, már az MVP-ben kell `X-Sendfile` támogatás.)
2. **Pontos szöveg-tartalmak** a látogatói és admin emailekhez, illetve az űrlap körüli bekezdéshez. (Default szövegek mehetnek be, de a végleges marketing-hangnemű szövegek megrendelői.)
3. **Adatvédelmi tájékoztató oldal URL-je** — már létezik, vagy létrehozandó?
4. **WordPress verzió és PHP verzió** a céloldalon? (Default minimum: WP 6.0+, PHP 7.4+.)
5. **Webszerver:** Apache vagy Nginx? (Nginx-en külön location block dokumentációt adunk.)
