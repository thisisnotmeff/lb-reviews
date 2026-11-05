# LB Reviews – contesto del progetto

Plugin WordPress custom che mostra le recensioni **Google** e **Trustpilot** su
[lucillabellini.net](https://www.lucillabellini.net/) (fotografa Lucilla Bellini, Tenerife).
Tema del sito: **Uncode** con page builder **WPBakery** (versione Uncode: `uncode-js_composer`). SEO: Rank Math.

- Repository: **github.com/thisisnotmeff/lb-reviews** (pubblico), branch `main`
- Versione attuale: vedi `lb-reviews.php` (`Version:` e `LBR_VERSION`)
- Requisiti: WordPress 6.0+, PHP 7.4+, nessuna dipendenza esterna (né JS né PHP)
- Lingua del codice e delle stringhe: inglese (text domain `lb-reviews`); documentazione e changelog in italiano

## Struttura

| File | Ruolo |
|---|---|
| `lb-reviews.php` | Bootstrap, costanti (`LBR_VERSION`, `LBR_GITHUB_REPO`), header `Update URI` |
| `includes/class-lbr-settings.php` | Opzione unica `lbr_settings` (default + sanitize) |
| `includes/class-lbr-post-type.php` | CPT `lbr_review` (titolo = autore, contenuto = testo, data = data recensione, bozza = nascosta) + tassonomia `lbr_list` (Liste) |
| `includes/class-lbr-repository.php` | Lettura/scrittura recensioni, upsert con deduplica, unione con le live Google, filtri (`sources`, `lists`, `ids`, `lang`, `order`), statistiche |
| `includes/class-lbr-google.php` | Places API (New) – Place Details: max 5 recensioni + voto + totale, solo in cache temporanea (le policy Google vietano di salvarle) |
| `includes/class-lbr-importer.php` | Import: Google Takeout (ZIP o `reviews*.json`), JSON Trustpilot, CSV generico |
| `includes/class-lbr-render.php` | Shortcode `[lb_reviews]`, `[lb_reviews_button]`, `[lb_reviews_trustbox]`; griglia e slider |
| `includes/class-lbr-wpbakery.php` | Elementi Uncode/WPBakery "LB Reviews" e "LB Review button" (`vc_map`) |
| `includes/class-lbr-schema.php` | JSON-LD `aggregateRating` opzionale (spento di default) |
| `includes/class-lbr-admin.php` | Pagina "Settings & import" (schede Settings, Import, How to use) |
| `includes/class-lbr-updater.php` | Aggiornamenti da GitHub Releases (filtro `update_plugins_github.com`, popup dettagli, "Check for updates") |
| `assets/js/lb-reviews.js` | "Read more", "Show more", slider (frecce, pallini/contatore, autoplay, drag) |
| `assets/css/lb-reviews.css` | Stili front-end (variabili `--lbr-*`, container query) |
| `.github/workflows/release.yml` | Crea la release `vX.Y.Z` con `lb-reviews.zip` quando la versione cambia su `main` |

## Regole da rispettare

- **Markup per l'editor Uncode/WPBakery**: l'output dello shortcode deve iniziare con un `<div>` scritto **su una sola riga** (oggi `<div class="lbr-wrap"><section …>`). Con un `<section>` su più righe il Frontend Editor lasciava aperto un suo contenitore, duplicava la pagina a ogni salvataggio e bloccava il browser (fix 1.1.2).
- **Lo script viene ricaricato dall'editor dopo ogni salvataggio**: l'inizializzazione deve restare idempotente (`window.LBReviews`, attributi `data-lbr-init` / `data-lbr-slider`). Nell'editor: niente autoplay, MutationObserver per gli elementi ri-renderizzati.
- **"Read more"**: misurare in blocco (`measureAll`: prima tutte le scritture, poi tutte le letture), mai una lettura di layout per card.
- **Google Places**: le recensioni live non si salvano nel database; attribuzione "Google Maps" e avatar obbligatori. Le liste e gli ID usano solo le recensioni salvate.
- **Trustpilot**: sul piano gratuito sono previsti i widget TrustBox; prima di pubblicare recensioni importate verificare le regole di Trustpilot.
- Ogni stringa in output va escapata (`esc_html`, `esc_attr`, `esc_url`); i form admin usano nonce e `current_user_can`.
- CSS/JS si caricano solo nelle pagine che contengono lo shortcode.

## Pubblicare una nuova versione

1. Aumentare il numero in `lb-reviews.php`: header `Version:` **e** `define( 'LBR_VERSION', … )`, uguali.
2. Aggiungere in cima al changelog di `readme.txt` una riga `X.Y.Z – …`.
3. Commit e push su `main`. La GitHub Action controlla la versione, verifica il PHP, crea `lb-reviews.zip` e pubblica la release `vX.Y.Z`. I push che non cambiano la versione vengono ignorati.
4. Sui siti WordPress l'aggiornamento compare nella pagina Plugin (entro 6 ore, o subito con "Check for updates").

Le sessioni Claude nel cloud possono fare push su `main` ma non creare tag o release: per questo la release parte dal cambio di versione, non dal tag.

## Test

- PHP: `php -l` su ogni file. JS: `node --check assets/js/lb-reviews.js`.
- Sito locale di prova: **Local** → sito `lucilla-art` (`http://lucilla-art.local`), con Uncode e WPBakery, pagina di prova "Test LB Reviews" (ID 758, in bozza).
- Prima di ogni release provare almeno: pagina pubblica (griglia, slider, "Read more", "Show more") e **Frontend Editor** di Uncode (salvare un elemento più volte di fila senza blocchi né duplicati).

## Sul sito live

- Percorsi rinominati da un plugin di sicurezza: plugins → `/niheeste/`, uploads → `/eedridoo/`.
- Cache: WP Fastest Cache (svuotarla dopo un aggiornamento).
- Liste in uso: `maternity` (ID 19), `couples` (ID 21), `family`.
- Il totale Google mostrato va impostato a mano ("Total override") se l'API non è collegata.

## Changelog

Vedi `readme.txt`.
