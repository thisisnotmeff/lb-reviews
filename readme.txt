=== LB Reviews – Google & Trustpilot ===
Version: 1.2.0
Requires at least: 6.0
Requires PHP: 7.4
License: GPL-2.0-or-later

Griglia di recensioni Google e Trustpilot per lucillabellini.net (shortcode + elemento WPBakery/Uncode).

== Installazione ==
Plugin → Aggiungi nuovo → Carica plugin → lb-reviews.zip → Attiva.
Menu "LB Reviews" → "Settings & import".

== Come arrivano le recensioni ==

1. Google live (Places API) – voto medio, totale e fino a 5 recensioni.
   Restano solo in una cache temporanea (mai salvate nel database), con attribuzione
   "Google Maps", avatar, nome e link alla recensione originale, come richiedono
   le policy di Google Maps Platform.
   Setup:
   - Google Cloud Console → crea progetto → abilita "Places API (New)" → crea API key
     (restringila a Places API; serve un account di fatturazione, c'è una quota mensile gratuita).
   - Trova il Place ID con il Place ID Finder (inizia con ChIJ…).
   - Incolla key e Place ID, spunta "Enable", salva, poi "Refresh Google reviews now".

2. Archivio Google completo (Takeout) – tutte le recensioni del profilo.
   takeout.google.com con l'account proprietario del Business Profile → seleziona solo
   "Google Business Profile" → carica direttamente lo ZIP (o i file reviews*.json) nella scheda Import.
   NON usare "Maps (i tuoi luoghi)": contiene le recensioni scritte dall'account, non quelle ricevute.
   Le recensioni live già presenti nell'archivio non vengono duplicate.

3. Trustpilot – import CSV (export di Trustpilot o un foglio compilato a mano con
   colonne author/name, rating/stars, text/content, title, date, url, language).
   Sul piano gratuito Trustpilot propone i widget TrustBox: in alternativa incolla
   il codice TrustBox nelle impostazioni e usa [lb_reviews_trustbox].

Reimportare lo stesso file è sicuro: aggiorna, non duplica. Una recensione in bozza è nascosta.
"Pin" mette una recensione sempre in testa.

== Uso ==
[lb_reviews]
[lb_reviews layout="slider" columns="3" autoplay="6"]
[lb_reviews list="family"]          (lista creata in LB Reviews → Lists)
[lb_reviews ids="812,790,775"]      (recensioni scelte, in quest'ordine)
[lb_reviews_button source="google"] (pulsante "Leave a review on Google")
[lb_reviews source="google" limit="9" per_page="6" columns="3"]
[lb_reviews source="trustpilot" summary="no"]
[lb_reviews lang="es"]   (per le future versioni ES / DE / IT)
In Uncode: Aggiungi elemento → "LB Reviews".
Tutti gli attributi: scheda "How to use".

== Changelog ==
1.2.0 – Aggiornamenti da GitHub: WordPress mostra le nuove versioni nella pagina Plugin (anche aggiornamento automatico)
        e il link "Check for updates".
1.1.2 – Fix blocco del browser nel Frontend Editor di Uncode/WPBakery: markup con radice <div> su una riga
        (l'editor lasciava aperto un suo contenitore e duplicava la pagina a ogni salvataggio).
        JS eseguito una sola volta anche quando l'editor lo ricarica; niente autoplay nell'editor;
        "Read more" calcolato in blocco (un solo ricalcolo del layout invece di uno per recensione).
1.1.1 – Elemento Uncode: etichette più chiare per "Show more" e "Read more"; campo "Show more" sempre visibile.
1.1.0 – Slider (layout="slider": frecce, pallini, autoplay, trascinamento, accessibile, senza librerie).
        Liste di recensioni (LB Reviews → Lists) e recensioni scelte per ID (list="…", ids="…").
        Link "lascia una recensione" di Google Business Profile, pulsante [lb_reviews_button]
        ed elemento "LB Review button"; "View on Google" sulle recensioni senza link proprio.
1.0.2 – Import JSON Trustpilot: fonte riconosciuta dal file, titolo, link alla recensione, profilo autore,
        risposte, TrustScore e totale letti dal file. Titolo non ripetuto nel testo.
        "Read more" ricalcolato quando cambia la larghezza della colonna.
1.0.1 – Import: accetta lo ZIP di Takeout, riconosce più formati JSON, segnala il file sbagliato di Maps,
        mostra quante recensioni trova per ogni file e il motivo di eventuali scarti.
1.0.0 – Prima versione.

== Note SEO / GEO ==
- Il testo completo delle recensioni è nell'HTML (il "Read more" è solo CSS), quindi è indicizzabile e leggibile dai motori AI.
- Schema aggregateRating disattivato di default: Google non mostra le stelle per le recensioni
  della propria attività sul proprio sito, e Rank Math può già generare lo schema dell'attività.
  Se lo attivi, imposta l'@id dell'entità Rank Math per unirlo a quella esistente.
- CSS/JS caricati solo nelle pagine che contengono lo shortcode.
