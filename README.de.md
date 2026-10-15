# Haushaltsbudget

`clesson-de/silverstripe-household-budget` ist eine **persönliche
Haushaltsplanungsanwendung im Silverstripe CMS**, kein Frontend-Modul. Jeder
Benutzer verwaltet in einem eigenen CMS-Bereich wiederkehrende Einnahmen,
Ausgaben und Umbuchungen auf seinen verknüpften Bankkonten.

Das Modul zeigt, welche Kosten im nächsten Monat anstehen, wie sich Einnahmen
und Ausgaben über das Jahr verteilen und wie hoch der durchschnittliche
monatliche Haushaltsbedarf ist.

Es stellt keine öffentlichen Seiten bereit und führt weder Bankimporte noch
Zahlungen aus. Kontostände und tatsächliche Kontobewegungen werden nicht
erfasst. Alle Ansichten, PDFs und Prognosen beruhen auf **aktuell gespeicherten
Planbuchungen**. Auch vergangene Zeiträume werden aus dem heutigen Datenstand
berechnet; eine Buchungshistorie existiert nicht.

[English documentation](README.md)

## Funktionen und Navigation

| Ansicht | Zweck |
| --- | --- |
| Dashboard | Monatssummen, gestapeltes Jahresdiagramm, Jahres-Liniendiagramm, monatlicher Kategorienvergleich und jährliche Rangliste. |
| Monatsdetails | Einnahmen und Ausgaben mit Positionen und Summen, separate Umbuchungen und Saldo für einen wählbaren Monat. |
| Bericht / PDF | Privater PDF-Download für einen Monatszeitraum und ausgewählte eigene Konten. |
| Administration → Buchungen | Buchungen anlegen/bearbeiten, Buchungsarten filtern, Beträge vergleichen und Wiederholungsmonate sowie Zeitgrenzen als Badge sehen. |
| Administration → Einstellungen | Persönlicher E-Mail-Versand, Einzelkonto-/Gesamtprognosen und lesbarer Versandstatus. |

Jahresauswahlen enthalten das aktuelle Jahr, **das Folgejahr** und standardmäßig
fünf Vorjahre. Monatsauswahlen starten im aktuellen Monat. Kontoschalter wählen
anfangs alle eigenen Konten aus; das Abwählen des letzten aktiviert wieder alle.
Neue Dashboardlayouts enthalten die verfügbaren Panels; bestehende Layouts
können neue Panels über die Panelauswahl ergänzen.

Der monatliche Kategorienvergleich zeigt Einnahmen, Fixkosten, variable Kosten,
Umbuchungen und Saldo als separate Balken. Periodische und außergewöhnliche
Ausgaben haben dort keine eigenen Balken, **fließen aber in den Saldo ein**.
Die jährliche Rangliste verwendet die effektive Jahressumme jeder Buchung;
gleiche Bezeichnungen werden nicht zusammengefasst.

## Abhängigkeiten

Direkte Laufzeitabhängigkeiten aus `composer.json`:

| Paket | Version | Verwendung |
| --- | --- | --- |
| `silverstripe/framework` | `^6` | ORM, Formulare, Controller, Berechtigungen, Tasks und E-Mail. |
| `silverstripe/admin` | `^3` | Eigener CMS-Bereich über `SingleRecordAdmin`. |
| `clesson-de/silverstripe-contacts` | `^1` | Benutzerverknüpfte Kontakte und optionale Gegenparteien. |
| `clesson-de/silverstripe-payment-accounts` | `^1` | Eigene Bankkonten (`BankAccount`) und deren Alias/Kontoanzeige. |
| `clesson-de/silverstripe-dashboard` | `^1` | Persönliche Dashboards und Panels. |
| `clesson-de/silverstripe-graphing` | `^1` | Balken- und Liniendiagramme mit Chart.js im CMS. |
| `clesson-de/silverstripe-hints` | `^1` | Erklärende HintFields. |
| `tecnickcom/tcpdf` | `^6` | Lokale PDF-Erzeugung im Arbeitsspeicher. |

`clesson-de/silverstripe-scheduler` ist **optional**. Alternativ kann ein
Server-Cronjob den Prognosetask starten. `silverstripe-pdf-templates` wird nicht
benötigt; eine gespeicherte PDF-Vorlage muss nicht eingerichtet werden.
Die PHP-Umgebung einschließlich Extensions muss die Anforderungen von
Silverstripe 6 und den installierten Abhängigkeiten erfüllen.

## Installation und Einrichtung

```bash
composer require clesson-de/silverstripe-household-budget
composer vendor-expose
```

Anschließend `/dev/build?flush=all` aufrufen, ebenso nach Schemaänderungen.
Admin-Assets werden mitgeliefert; für die normale Installation ist kein
zusätzlicher Frontend-Build notwendig.

Pro Benutzer sind folgende Schritte erforderlich:

1. CMS-Zugriff und `CMS_ACCESS_HOUSEHOLD_BUDGET` („Zugriff auf Haushaltsbudget“) erteilen.
2. Das Benutzerkonto über das Kontaktmodul mit einem Kontakt verknüpfen.
3. Dem Kontakt mindestens ein Bankkonto zuordnen.
4. Den Haushaltsbereich öffnen; der private Arbeitsbereich entsteht beim ersten Zugriff.

Die Kontozuordnung erfolgt über `$member->Contact()->ID` und `BankAccount.ContactID`.
Ohne Kontaktverknüpfung dürfen keine unzugeordneten Konten angeboten werden.
Buchungen verwenden derzeit ausdrücklich
`Clesson\Silverstripe\PaymentAccount\Models\BankAccount`, nicht beliebige
Zahlungsarten wie Kreditkarten.

Beim Upgrade migriert dev/build alte `expense`-Buchungen zu `fixed` und
übernimmt alte `StartDate`-/`EndDate`-Werte in die Monatsgrenzen, ohne bereits
gesetzte Werte zu überschreiben. Die ursprünglichen Daten bleiben in
`_migrated_StartDate`/`_migrated_EndDate` erhalten. Absichtlich gelöschte
Monatsgrenzen werden bei späteren Builds nicht wiederhergestellt.

## Zugriffsrechte und Datenschutz

| Rolle / Datensatz | Berechtigung |
| --- | --- |
| Benutzer mit Haushalts-CMS-Zugriff | Eigener Arbeitsbereich, eigene Buchungen, verknüpfte Bankkonten und Berichte. |
| Eigentümer einer Buchung | Eigene Buchung ansehen, bearbeiten und löschen. |
| Eigentümer des Haushaltsplans | Eigene Prognoseeinstellungen ändern; der Arbeitsbereich selbst ist im Modul nicht löschbar. |
| Andere Benutzer, auch Administratoren | Keine Eigentümer-Übersteuerung zum Zugriff auf fremde Haushaltspläne, Buchungen oder persönliche Prognoseeinstellungen. |
| Eigentümer eines Versandnachweises | Eigenen technischen Status lesen; keine Anlage, Bearbeitung oder Löschung im CMS. |
| Konfigurierter technischer ADMIN-Benutzer | Zentralen Task starten, der aktivierte Empfänger getrennt verarbeitet und technische Versandnachweise aktualisiert. |
| Anonyme Besucher | Kein Zugriff auf Haushaltsansichten oder Berichte. |

`ADMIN` macht Haushaltsdaten nicht automatisch zu gemeinsam nutzbaren Daten.
Die zentrale Verarbeitung im Versandtask ist eine bewusste technische Ausnahme,
keine zusätzliche Oberfläche zum Durchsuchen fremder Haushalte.

Diese Regeln gelten **auf Anwendungsebene**. Sie verschlüsseln die Daten nicht
gegenüber Hostingbetreibern oder Personen mit Datenbank-, Code-, Dateisystem-,
Backup- oder Mailserverzugriff. Eigene Erweiterungen müssen Eigentümer- und
Kontofilter ausdrücklich beibehalten: Rohe ORM-Abfragen führen nicht automatisch
`canView()` aus.

PDF-Downloads erfordern Anmeldung, Haushaltsberechtigung, POST, gültiges
SecurityToken und eigene Konto-IDs. Sie werden privat mit No-store-Headern
zurückgegeben und nicht als öffentliche Assets gespeichert. Mailanhänge
entstehen ebenfalls im Speicher; Kopien liegen anschließend jedoch in der
Mailinfrastruktur und im Postfach des Empfängers. Mailtransport, Zugangsdaten
und Backups müssen entsprechend geschützt werden.

## Buchungs- und Berechnungsregeln

Eine `BudgetEntry` enthält Eigentümer, Bezeichnung, positiven Betrag,
Buchungsart, Bankkonto, Wiederholungsmonate und optionale Grenzen im Format
`YYYY-MM`. Die Gegenpartei ist optional. Start- und Endmonat zählen mit.
Pro ausgewähltem Monat innerhalb der Grenzen wird der Betrag einmal
berücksichtigt; es gibt keine tageweise Aufteilung oder automatische Verteilung
von Jahresbelastungen.

| Buchungsart | Bedeutung | Kosten / saldomindernd |
| --- | --- | --- |
| `INCOME` | Einnahme | Nein; erhöht den Saldo. |
| `FIXED` | Regelmäßige Fixkosten | Ja. |
| `VARIABLE` | Beeinflussbare Ausgaben | Ja. |
| `PERIODIC` | Unregelmäßige/Jahreskosten | Ja. |
| `EXCEPTIONAL` | Außergewöhnliche Ausgabe | Ja. |
| `TRANSFER` | Umbuchung/Rücklage | Nein; separat und neutral. |

Saldo = Einnahmen minus alle vier Ausgabenarten. Der normale monatliche
Haushaltsbedarf ergibt sich aus `FIXED + VARIABLE + PERIODIC`, geteilt durch
**alle Kalendermonate** des Berichtszeitraums, einschließlich leerer Monate.
Die durchschnittlichen Gesamtkosten enthalten zusätzlich `EXCEPTIONAL`.
Umbuchungen bleiben neutral und werden blau (`#2093e5`) dargestellt.

Die Fachberechnung verwendet Integer-Cents. Durchschnitte werden zur Anzeige
kaufmännisch gerundet; separat gerundete Kostenanteile können geringfügig von
100 % abweichen. Eine Dezemberbelastung von 120 € ergibt 120 € im Dezember und
10 € Monatsdurchschnitt über ein vollständiges Jahr. Ein positiver Plansaldo
belegt kein verfügbares Guthaben, ein negativer keine tatsächliche Kontoüberziehung.

## PDF-Berichte

Das Formular startet mit Januar–Dezember des aktuellen Jahres und allen eigenen
verknüpften Konten. Mindestens ein Konto muss gewählt sein. Auch mehrjährige
Zeiträume sind möglich; standardmäßig sind maximal 120 inklusive Monate erlaubt.
Die Grenze ist unabhängig von der Jahresauswahl der Dashboardpanels.

Der Bericht beginnt mit Einnahmen, Kosten, Saldo und monatlichem Bedarf,
gefolgt von Monatsentwicklung, Kostenarten, regelmäßigen Belastungen,
außergewöhnlichen Ausgaben und separaten Umbuchungen. Mehrjährige Berichte
enthalten Jahressummen. TCPDF erzeugt das Dokument lokal, ohne externe
Diagramm- oder Renderdienste. Für den Download muss das CMS-Formular nicht
gespeichert werden.

## Prognose-E-Mails

### Persönliche Auswahl und Inhalt

Der Versand ist **standardmäßig deaktiviert**. Unter
**Administration → Einstellungen** aktiviert der Eigentümer den Hauptschalter
und die gewünschten Einzelkontoprognosen und/oder die zusätzliche Gesamtprognose.
Danach muss das CMS-Formular gespeichert werden. Beim Ausschalten des
Hauptschalters bleiben die Einzelauswahlen erhalten.

Die Gesamtprognose umfasst alle aktuell eigenen verknüpften Konten, unabhängig
von deren Einzelversand. Ausschließlicher Empfänger ist die aktuelle Adresse
des Benutzerkontos; freie Empfänger, CC oder BCC sind nicht vorgesehen.
Jede E-Mail enthält eine HTML- und Textversion sowie den passenden Monatsbericht
als PDF. Zahlen und PDF verwenden denselben Buchungssnapshot.

Die Prognose beschreibt den nächsten Kalendermonat mit Einnahmen, Kosten,
Saldo, den fünf größten Ausgaben samt optionaler Gegenpartei, Hinweisen zu
variablen Kosten, periodischen/außergewöhnlichen Belastungen und separaten
Umbuchungen. Vergleichsbasis sind alle zwölf Monate des **Kalenderjahres des
Prognosemonats**, einschließlich außergewöhnlicher Kosten, ohne Umbuchungen.
Standardmäßig gelten Abweichungen **ab einschließlich ±10 %** als teuer/günstig;
darunter liegt der Monat im üblichen Bereich. Bei null Jahreskosten gibt es
keine künstliche Prozentbewertung.

Defizithinweise beziffern die Deckungslücke und benennen variable Positionen
zur Prüfung, ohne Einsparungen zu garantieren oder Buchungen zu ändern.
Reichen selbst sämtliche variablen Kosten nicht aus, wird die Restlücke genannt.
Bei leerem Datenbestand wird ausdrücklich auf fehlende Buchungen hingewiesen;
daraus wird keine tatsächliche Kostenfreiheit abgeleitet. Kontoguthaben und
konkrete Abbuchungstage sind nicht bekannt.

### Einrichtung durch Betreiber

Erforderlich sind ein gültiger Absender, ein funktionierender Mailtransport
und ein vorhandener technischer Benutzer mit `ADMIN`. Das Modul richtet keine
SMTP-Zugangsdaten und keinen technischen Benutzer ein.

```yaml
---
Name: app-household-budget-mail
After:
  - household-budget-config
---
Clesson\Silverstripe\HouseholdBudget\Tasks\MonthlyBudgetForecastTask:
  cli_user_email: 'vorhandener-admin-benutzer@example.org'
SilverStripe\Control\Email\Email:
  admin_email: 'haushalt@example.org'
```

Beispieladressen ersetzen und anschließend die Konfiguration flushen.
Die PHP-/Anwendungszeitzone des Taskprozesses prüfen: Sie bestimmt den
letzten Kalendertag.

```bash
vendor/bin/sake dev/tasks/monthly-household-budget-forecast
```

Beispielsweise stündlich über Server-Cron aufrufen:

```cron
0 * * * * cd /pfad/zum/projekt && vendor/bin/sake dev/tasks/monthly-household-budget-forecast
```

Alternativ im bestehenden Scheduler den `MonthlyBudgetForecastTask` auswählen,
stündlich planen und **das Überspringen an Feiertagen deaktivieren**.
Ein Schedulerjob wird nicht automatisch angelegt oder aktiviert.

Der tatsächliche Versand erfolgt **ausschließlich am letzten Kalendertag**.
An anderen Tagen wird keine Prognosemail verschickt. Nach Mitternacht gibt es
auch nach Ausfällen keinen Nachversand. Der Task erfordert ADMIN-Autorisierung;
der Empfänger benötigt weiterhin Haushaltszugriff, gültige Adresse, aktive
Einstellungen und eine gültige eigene Kontoauswahl. Berechtigung, Einstellungen,
Kontozuordnung und Tagesfenster werden vor der Mailübergabe erneut geprüft.

### Zuverlässigkeit und Fehler

Eindeutige Versandnachweise und atomare Claims mit Tokenprüfung verhindern
parallele Verarbeitung derselben Haushalt-/Monat-/Kontovariante.
Erfolgreiche Varianten werden bei erneutem Taskaufruf oder geänderten Buchungen
nicht nochmals gesendet.

„Vom Transport angenommen“ bedeutet nicht, dass die Nachricht zugestellt oder
gelesen wurde. Eindeutige Fehler vor der Mailübergabe dürfen am selben Monatsletzten
erneut versucht werden. Bei unklarem Transportausgang bleibt der automatische
Wiederholungsversand gesperrt; Status und Mailtransport müssen manuell geprüft
werden. SMTP und eine getrennte Datenbank können keine durchgehende
Exactly-once-Zustellung garantieren. Ein erzwungener Versand ist nicht vorgesehen.

Fehler einer Variante blockieren die übrigen nicht, führen aber zu einem
Fehler-Rückgabestatus des Tasks. Logs enthalten sichere Codes und technische
IDs, keine Mailtexte, PDF-Inhalte oder Finanzbeträge. Versandnachweise bleiben
zur Dublettenvermeidung erhalten und sollten nicht routinemäßig gelöscht oder
zurückgesetzt werden.

## Entwicklerkonfiguration

Optionen in der Anwendung überschreiben, nicht im Modul:

```yaml
---
Name: app-household-budget-options
After:
  - household-budget-config
---
Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel:
  years_before_current: 5
Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel:
  maximum_positions: 10
Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod:
  maximum_months: 120
Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastService:
  threshold_percent: 10
Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDispatchService:
  claim_lease_minutes: 15
```

| Option | Grenzen / Wirkung |
| --- | --- |
| `years_before_current` | Nichtnegative Ganzzahl kleiner als das aktuelle Jahr; `0` bietet weiterhin aktuelles Jahr und Folgejahr. Gemeinsam für Panels und Monatsdetails. |
| `maximum_positions` | Positive Ganzzahl für die Anzahl der jährlichen Ranglistenpositionen. |
| `maximum_months` | Positive Ganzzahl für die maximale inklusive Monatsanzahl des PDF-Downloads. |
| `threshold_percent` | Ganzzahl 0–100 für die Einstufung gegenüber dem exakten Jahresdurchschnitt. Bei `0` wird jede Abweichung ungleich null eingestuft. |
| `claim_lease_minutes` | Ganzzahl 1–1440 für Vorbereitungsclaims und die Erkennung unterbrochener Versandversuche. Abgelaufene Sending-Claims werden nicht automatisch neu versandt. |
| `cli_user_email` | Vorhandener technischer ADMIN-Benutzer für den zentralen Task erforderlich. |
| `Email.admin_email` | Gültiger Absender zusätzlich zum eingerichteten Mailtransport erforderlich. |

### Architektur und Erweiterung

Fachberechnungen gehören nicht in Controller, Diagramme, Mailtemplates oder
PDF-Layout:

- `BudgetEntrySchedule` und `BudgetMoney`: gemeinsame Wiederholungs- und Geldregeln.
- `MonthlyBudgetService`: eigentümerbezogene Dashboard-, Detail- und Diagrammdaten.
- `BudgetPeriodCalculator`: reine Zeitraum-, Typ-, Positions- und Durchschnittsberechnung.
- `BudgetReportService`: Kontoprüfung, ORM-Snapshots und Teilzeitraumberichte.
- `Reports/*`: unveränderliche, rendererunabhängige DTOs.
- `HouseholdBudgetPdfRenderer`: lokales Layout aus vorbereiteten Berichtsdaten.
- `BudgetForecastService` / `BudgetForecastInsightCalculator`: Jahressnapshot und nachvollziehbare Monatsanalyse.
- `BudgetForecastEmailService`: lokalisierte Mail-/PDF-Vorbereitung und Mailübergabe.
- `BudgetForecastDispatchService` / `BudgetForecastDeliveryRepository`: Versandberechtigung, Tagesfenster, atomare Claims und Statuswechsel.
- `MonthlyBudgetForecastTask`: ausschließlich technische Autorisierung und Ausführung.

`HouseholdBudget::getCMSFields()` bietet den Hook `updateHouseholdBudgetFields`,
die `GridFieldConfig_BudgetEntriesInHouseholdBudget` den Hook `updateConfig`.
Services mit Abhängigkeiten sind in `_config/config.yml` über Silverstripe
Injector verdrahtet. Bei Anpassungen Eigentümerfilter, Kontoprüfung und
Claim-Semantik erhalten; vom Browser gelieferte Eigentümer-/Konto-IDs niemals
ungeprüft übernehmen. Benutzersprache berücksichtigen und deutsche/englische
Übersetzungen pflegen.

Die Ressourcen unter `household-budget-api` und `household-budget-report` sind
keine öffentliche Frontend-API. Öffentliche Ansichten oder externe
Integrationen benötigen ein gesondertes Berechtigungskonzept.

Für die Entwicklung die vorhandene PHPUnit-Infrastruktur des Projekts nutzen:

```bash
vendor/bin/phpunit silverstripe-household-budget/tests
```

Mit isolierter Testdatenbank und Test-Mailtransport arbeiten, nicht mit echten
Empfängern. Keine Versandnachweise zur Einrichtungskontrolle zurücksetzen
und keine echten Prognosemails als Test verschicken.

## Lizenz

BSD-3-Clause; siehe [LICENSE](LICENSE).
