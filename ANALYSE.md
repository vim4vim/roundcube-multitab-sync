# Roundcube: warum die Nachrichtenliste nur in einem Tab aktualisiert

Analyse zum Verhalten, dass dasselbe Postfach in mehreren Browser-Tabs geöffnet
nur in *einem* Tab neue Mails anzeigt. Untersucht an Roundcube 1.7/1.8-git.

**Kurzantwort: Ja, das lässt sich zuverlässig beheben — ohne Patch am Core.**
Das Plugin in diesem Repository tut es.

---

## 1. Symptom

Zwei oder mehr Tabs, gleiches Konto, gleicher Ordner. Trifft eine neue Mail ein,
erscheint sie in genau einem Tab. Die übrigen zeigen weiter die alte Liste —
*obwohl* Ungelesen-Zähler, Ordnerbaum und Seitentitel sich überall aktualisieren.
Welcher Tab „gewinnt", wirkt zufällig und wechselt.

Diese Kombination — Zähler aktuell, Liste veraltet — ist der entscheidende
Hinweis. Sie schliesst ein Übertragungs- oder Netzwerkproblem aus: der Tab
*spricht* mit dem Server und bekommt eine Antwort, sie enthält nur die
Nachrichtenzeilen nicht.

## 2. Ursache

> Roundcube fragt beim Pollen nicht „was liegt im Ordner", sondern „hat sich seit
> dem letzten Mal etwas geändert". Der Referenzwert für *letztes Mal* liegt in der
> PHP-Session. Alle Tabs eines Browsers teilen sich eine Session. Der erste Tab,
> der pollt, **verbraucht** die Differenz und schreibt den Referenzwert fort — für
> alle anderen gibt es danach nichts mehr zu melden.

### 2.1 Der Poll

`program/js/app.js` startet je Tab einen Timer (`start_refresh()`), der alle
`refresh_interval` Sekunden `refresh()` aufruft. Dessen Request geht auf die
Aktion `refresh`, die `rcmail_action_mail_index::$aliases` auf `check-recent`
abbildet — beide landen also in `program/actions/mail/check_recent.php`.

### 2.2 Der destruktive Vergleich

`check_recent.php:87`:

```php
$status = $rcmail->storage->folder_status($mbox_name, $diff);
```

`rcube_imap::folder_status()` (`program/lib/Roundcube/rcube_imap.php:1327`):

```php
$old = $this->get_folder_stats($folder);                 // liest  $_SESSION['folders'][$folder]
$this->countmessages($folder, 'ALL', true, true, true);  // SCHREIBT cnt + maxuid dorthin
$result = 0;
if (empty($old)) { return $result; }
$new = $this->get_folder_stats($folder);                 // liest die soeben geschriebenen Werte

if ($new['maxuid'] > $old['maxuid']) { $result++;    }   // neue Mails
if ($new['cnt']    < $old['cnt'])    { $result += 2; }   // gelöschte Mails
```

Der Speicherort ist ausdrücklich die Session (Zeile 1374):

```php
protected function set_folder_stats($folder, $name, $data) {
    $_SESSION['folders'][$folder][$name] = $data;
}
```

Lesen und Schreiben passieren im selben Aufruf. Wer zuerst kommt, konsumiert.

### 2.3 Die Konsequenz

Zeilen gehen nur bei `$status != 0` an den Client (`check_recent.php:104`):

```php
if ($status && $is_current) {
    …
    $rcmail->output->command('message_list.clear', $all_count ? false : true);
    $a_headers = $rcmail->storage->list_messages($mbox_name, null, …);
    self::js_message_list($a_headers, false);
}
```

| Zeit | Ereignis | `$_SESSION['folders']['INBOX']['maxuid']` | Ergebnis |
|---|---|---|---|
| t₀ | Ausgangsstand | 100 | — |
| t₁ | neue Mail (UID 105) trifft ein | 100 | — |
| t₂ | **Tab A** pollt: `old=100`, `new=105` | → **105** | `$status=1` → Zeilen ✅ |
| t₃ | **Tab B** pollt: `old=105`, `new=105` | 105 | `$status=0` → **nichts** ❌ |

Tab B bekommt keine zweite Chance: der Referenzwert steht bereits auf 105. Wer
gewinnt, entscheidet allein die Reihenfolge der Timer — daher die Zufälligkeit.

### 2.4 Warum die Zähler trotzdem stimmen

`rcmail_action_mail_index::send_unread_count()` wird bei *jedem* Ordner
aufgerufen (`check_recent.php:102`), also ausserhalb der `$status`-Abfrage, und
hat zusätzlich eine INBOX-Ausnahme:

```php
if ($unseen !== $old_unseen || ($mbox_name == 'INBOX')) {
    $rcmail->output->command('set_unread_count', …);
}
```

Für die INBOX wird bedingungslos gesendet. Genau daher das irritierende Bild:
Zähler ja, Liste nein.

### 2.5 Dasselbe Muster an zwei weiteren Stellen

* **`$_SESSION['list_mod_seq']`** (`check_recent.php:178/183`) hält den IMAP-Wert
  `HIGHESTMODSEQ` und steuert die Übertragung von **Flag-Änderungen**
  (gelesen/ungelesen, markiert). Ebenfalls session-global, ebenfalls verbraucht.
* **`$_SESSION['unseen_count']`** unterdrückt redundante Zähler-Updates. Ausserhalb
  der INBOX — Archiv, Unterordner — greift dort dieselbe Verbrauchslogik auch für
  die Zähler.

### 2.6 Was *nicht* die Ursache ist

* Kein IMAP-, Server- oder Cache-Problem. Serverseitig sind die Daten korrekt;
  ein Ordnerwechsel hin und zurück zeigt sofort den richtigen Stand.
* Kein Cookie- oder Mehrkonten-Thema. Upstream-Issue
  [#5075](https://github.com/roundcube/roundcubemail/issues/5075) betrifft
  *verschiedene Konten* auf einer Domain und ist hier nicht einschlägig.
* Kein Konfigurationsfehler. `refresh_interval` und `check_all_folders` ändern
  Takt und Umfang des Pollings, nicht die Verbrauchslogik.

## 3. Lösung

Die Delta-Referenz muss **pro Tab** geführt werden statt pro Session. Dann
verhält sich jeder Tab wie der einzige. Roundcube stellt dafür alles Nötige
bereit, ein Core-Patch ist nicht erforderlich:

| Baustein | Fundstelle |
|---|---|
| Hook **vor** der Ordnerschleife | `exec_hook('check_recent', …)`, `check_recent.php:65` |
| Hook **nach** der Ordnerschleife | `exec_hook('refresh', [])`, `check_recent.php:206` |
| Parameter am periodischen Poll | `triggerEvent('request' + action, data)`, `app.js:9582` |

Die Reihenfolge trägt: `$_SESSION['list_mod_seq']` wird bei Zeile 183
geschrieben, der `refresh`-Hook feuert bei 206 — der Rückschreibpunkt erfasst
also alle drei Schlüssel.

Das Plugin tauscht im `check_recent`-Hook die Werte des jeweiligen Tabs an die
Stelle ein, die der Core liest, und sichert sie im `refresh`-Hook wieder. Als
Tab-Kennung dient ein Wert in `sessionStorage` — pro Tab eindeutig, übersteht
einen Reload, verschwindet beim Schliessen — der an jedem Poll mitgeschickt wird.

Zwei kleinere Bausteine senken zusätzlich die Latenz, ohne für die Korrektheit
nötig zu sein: Tabs melden eigene Lösch-, Verschiebe- und Flag-Aktionen über
einen `BroadcastChannel`, und ein Tab pollt einmal, sobald er wieder sichtbar
wird — Browser frieren Hintergrund-Tabs ein, ein eingefrorener Tab pollt gar nicht.

## 4. Belege aus dem Quellcode

Fünf Punkte waren für den Entwurf entscheidend und wurden am Quellcode geprüft:

1. **`$_SESSION['folders']` hat genau einen Leser.** `get_folder_stats()`, und der
   wird ausschliesslich von `folder_status()` aufgerufen. Der Tausch ist damit
   vollständig eingegrenzt und kann nichts anderes stören.
2. **`rcube_session::fixvars()` mischt pro Top-Level-Variable**
   (`array_merge($a_oldvars, …)`, `rcube_session.php:272`). Deshalb bekommt jeder
   Tab einen *eigenen* Top-Level-Schlüssel: zwei gleichzeitig pollende Tabs
   überleben beide, während ein gemeinsames verschachteltes Array einen der
   beiden Schreibvorgänge verlieren würde.
3. **Ein `unset()` auf `$_SESSION` allein hält nicht.** Wegen ebendieses Merges
   muss ein Schlüssel über `rcube_session::remove()` als gelöscht registriert
   werden, sonst kehrt er zurück. Die Bucket-Bereinigung nutzt daher `remove()`.
4. **`add_message_row()` verwirft doppelte UIDs** (`app.js:2374`), und der Core
   sendet vor dem Neuaufbau ohnehin `message_list.clear`. Ein Tab mit veraltetem
   Referenzwert bekommt also schlimmstenfalls seine Seite erneut — keine
   doppelten Zeilen.
5. **Der DB-Session-Handler sperrt nicht.** `rcube_session::get_cache()` liest
   allerdings erneut aus der Datenbank, sobald ein Request länger als 0,5 s
   dauert (`rcube_session.php:351`) — bei IMAP-Polls der Normalfall. Zusammen mit
   Punkt 2 und der Streuung der Broadcast-Refreshes bleibt das Restrisiko
   gutartig: ein Tab bekommt seine Seite beim nächsten Poll erneut.

## 5. Bekannte Einschränkungen

* **Keine punktgenaue Einfügung.** Der Core ersetzt bei einer Änderung die ganze
  Seite (`message_list.clear` gefolgt von `list_messages()`), er fügt nicht
  einzelne Zeilen ein. Die Auswahl bleibt erhalten (`clear(false)` plus
  `update_selection`), die Scrollposition nicht zwingend. Das ist unverändertes
  Core-Verhalten — genau das, was heute schon der „gewinnende" Tab erlebt. Das
  Plugin ändert daran nichts und verschlechtert nichts.
* **Startfenster eines frischen Tabs.** Ein neu geöffneter Tab hat serverseitig
  noch keine Referenzwerte; sein erster Poll legt sie nur an. Eine Mail, die
  zwischen Seitenaufbau und erstem Poll eintrifft und von einem anderen Tab
  konsumiert wird, erscheint in diesem Tab erst bei der nächsten Änderung. Der
  Sofort-Poll nach `init` drückt dieses Fenster auf etwa eine Sekunde.
* **Ordnerwechsel aktualisiert die Kopie nicht.** Ein `list`-Request schreibt die
  session-weite Ordnerstatistik fort, nicht die Kopie des jeweiligen Tabs. Wer zu
  einem Ordner zurückkehrt, in dem zwischenzeitlich Mail eintraf, zahlt beim
  nächsten Poll einen überflüssigen Listen-Refresh. Das korrigiert sich selbst.
* **Anderer session-globaler Ansichtszustand bleibt.** `page`, `sort_col`,
  `sort_order`, `search`, `search_request` und `list_attrib` liegen ebenfalls
  session-weit. Suchen oder Umsortieren in einem Tab kann daher weiterhin einen
  anderen beeinflussen. Das ist das allgemeine Mehr-Tab-Problem und bewusst nicht
  Gegenstand dieser Analyse.

## 6. Upstream

Die Ursache ist auf eine Datei und eine Methode eingrenzbar
(`rcube_imap::folder_status()`) und die Symptomatik gut reproduzierbar. Ein
Issue oder Patch für `roundcube/roundcubemail` wäre gerechtfertigt; eine saubere
Behebung im Core müsste die Ordnerstatistik ebenfalls pro Client statt pro
Session führen — der `@TODO: move to separate DB table (cache?)` über
`set_folder_stats()` deutet in dieselbe Richtung.
