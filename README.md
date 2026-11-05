# Hauskachel für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.2 (Build 3)](https://img.shields.io/badge/Modul--Version-1.2_(Build_3)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Hauskachel-Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Hauskachel-Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprache: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
![Datenquelle: Objektbaum](https://img.shields.io/badge/Datenquelle-Objektbaum-lightgrey.svg)
![Bereiche](https://img.shields.io/badge/Bereiche-Etagen_%7C_Keller_%7C_Treppenhaus_%7C_Garten_%7C_Garage_%7C_Carport-0f766e.svg)
![Ohne Cloud](https://img.shields.io/badge/Cloud-nicht_n%C3%B6tig-brightgreen.svg)

Zeigt das ganze Haus als eine Kachel: die Etagen im Schnitt übereinander, darin die Räume mit Temperatur, Licht, offenen Fenstern und Präsenz, dazu Treppenhaus, Garten und Nebengebäude wie Garage und Carport. Die Struktur kommt direkt aus dem Objektbaum – was dort angelegt ist, erscheint in der Kachel.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel](#5-kachel)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Geschwindigkeit](#8-sicherheit-und-geschwindigkeit)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Changelog](#10-changelog)
11. [Lizenz](#11-lizenz)

## 1. Funktionsumfang

- **Haus im Schnitt:** Etagen von oben nach unten, der Keller unter der Erdgleiche, durchgehende Bereiche wie das Treppenhaus als senkrechte Achse neben allen Etagen
- **Außen und Nebengebäude:** Garten, Hof, Terrasse sowie Garage, Carport, Schuppen oder Gartenhaus als eigene Felder unter dem Haus, mit passendem Symbol
- **Struktur aus dem Objektbaum:** Hauskategorie → Bereiche → Räume. Die Art jedes Bereichs wird am Namen vorgeschlagen und lässt sich im Formular ändern
- **Automatische Erkennung im Raum:** Temperatur, Luftfeuchte, Solltemperatur, Licht, Fenster/Türen und Präsenz – an Name, Ident, Profil und Symbol, auch über Links und innerhalb von Instanzen. Batterie-, Signal- und Zählerwerte werden ausgelassen
- **Eigene Zuordnungen** gehen vor; „Ignorieren“ entfernt eine falsch erkannte Variable
- **Etagenwerte:** Eine Kategorie „Messwerte“ (Name einstellbar) in der Etage liefert Temperatur und Feuchte für die ganze Etage
- **Bedienen aus der Kachel:** Licht eines Raums schalten (mehrere Lampen gemeinsam), Solltemperatur in Schritten verstellen
- **Zusammenfassung:** Lichter an, offene Fenster, Durchschnittstemperatur (ohne Keller, Terrasse, Balkon und Außenbereiche) – auch als Variablen für eigene Skripte
- **Bleibt aktuell:** Neue, entfernte oder umbenannte Räume werden automatisch eingelesen

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0 (PHP 8.3 und 8.5)
- Basisklasse `IPSModuleStrict`, Variablen mit Darstellungen statt Profilen
- Kachel-Visualisierung mit HTML-SDK, Farbschema Symcon-Design, Dunkel oder Hell
- Keine Cloud, keine Abrufe nach außen

## 3. Installation

1. In der Konsole unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/Hauskachel-Symcon` hinzufügen.
2. Eine Instanz **Hauskachel** anlegen.
3. In der Kachel-Visualisierung die Instanz als Kachel hinzufügen – am besten groß (z. B. 4 × 3).

## 4. Einrichtung

### Objektbaum

```
Haus Gerzen                 ← Hauskategorie
├── 2. OG                   ← Etage
│   ├── Badezimmer          ← Raum (Kategorie, darin Variablen, Links oder Instanzen)
│   └── …
├── 1. OG
├── EG
│   ├── Wohnzimmer
│   └── Messwerte           ← Etagenwerte
├── Keller                  ← Keller (unten, unter der Erdgleiche)
├── Treppenhaus             ← durchgehend
├── Garten                  ← Außenbereich
├── Garage                  ← Nebengebäude
└── Carport                 ← Nebengebäude
```

### Bereiche

Nach der Wahl der Hauskategorie und *Übernehmen* listet das Formular alle Bereiche mit vorgeschlagener Art:

| Art | Name enthält z. B. | Darstellung |
| :-- | :-- | :-- |
| Etage mit Räumen | EG, 1. OG, 2. OG, DG | Zeile im Haus; Reihenfolge über die *Ebene* |
| Keller | Keller, UG | immer unten, unter der Erdgleiche |
| Durchgehend | Treppenhaus, Aufgang | senkrechte Achse neben allen Etagen |
| Außenbereich | Garten, Hof, Einfahrt | Feld unter dem Haus |
| Nebengebäude | Garage, Carport, Schuppen | Feld unter dem Haus mit eigenem Symbol |
| Nicht anzeigen | Messwerte | ausgeblendet |

### Erkennung

| Funktion | erkannt an | Typ |
| :-- | :-- | :-- |
| Temperatur | Temperatur, temperature, °C | Float |
| Luftfeuchte | Feuchte, humidity | Zahl |
| Solltemperatur | Soll, setpoint, target | Float (mit Aktion zum Verstellen) |
| Licht | Licht, Lampe, Leuchte, Spot, Deko | Boolean (mit Aktion zum Schalten) |
| Fenster / Tür | Fenster, Tür, Kontakt, window, door | Boolean (`~Window`, `~Window.Reversed`), Integer (0 = zu) oder Text („Geschlossen“, „Offen“, „Gekippt“) |
| Präsenz | Präsenz, Bewegung, Anwesenheit, motion | Boolean oder Integer |

Was die Erkennung gefunden hat, zeigt die Tabelle *Erkannt* im Formular. Passt etwas nicht, unter *Eigene Zuordnungen* Raum, Funktion und Variable eintragen.

## 5. Kachel

- Kopfzeile mit Titel und Zusammenfassung
- Raumfelder: Name, Temperatur, Symbole für Licht (Feld leuchtet gelb), offenes Fenster und Präsenz
- Antippen öffnet das Raumdetail: Temperatur und Feuchte, Licht schalten, Solltemperatur −/+, Fenster und Präsenz. Bei schmaler Kachel erscheint das Detail als Blatt von unten
- Farbschema: Symcon-Design (Farben der Visualisierung), Dunkel oder Hell

## 6. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung |
| :-- | :-- | :-- | :-- |
| `LightsOn` | Lichter an | Integer | Wertanzeige |
| `WindowsOpen` | Fenster offen | Integer | Wertanzeige |
| `AverageTemperature` | Durchschnittstemperatur | Float | Wertanzeige, °C |

## 7. PHP-Befehle

```php
HAUS_Rebuild(int $InstanzID): bool   // Struktur neu einlesen und Kachel neu aufbauen
```

## 8. Sicherheit und Geschwindigkeit

- Die Kachel schickt nur Raumschlüssel, nie Variablen-IDs. Geschaltet werden ausschließlich Variablen, die das Modul selbst in diesem Raum gefunden hat, und nur über deren Aktion (`RequestAction`).
- Alle Texte in der Kachel per `textContent`; Startdaten mit `JSON_HEX_*` eingebettet.
- Bei einer Wertänderung wird nur der betroffene Raum neu berechnet und nur dessen Feld neu gezeichnet. Strukturänderungen werden gesammelt (2 s) und dann einmal eingelesen.
- Keine Abrufe nach außen, keine externen Dateien in der Kachel.

## 9. Entwicklung und Tests

Bei jedem Push prüft der Workflow PHP 8.3 und 8.5: Syntax, JSON, Strukturprüfung nach Hausstil und den Ladetest mit den offiziellen Symcon-Stubs.

```bash
php tests/structure.php
git clone --depth 1 https://github.com/symcon/SymconStubs.git ../SymconStubs
php tests/stubs.php ../SymconStubs
```

## 10. Changelog

| Version | Build | Datum | Beschreibung |
| :-- | --: | :-- | :-- |
| 1.2 | 3 | 05.11.2026 | Erkennt auch Links in Instanzen (z. B. Dummy-Instanz „Heizkörper“ mit Links auf die HCU) und Fensterstatus als Text („Geschlossen“, „Offen“, „Gekippt“) |
| 1.1 | 2 | 05.11.2026 | Kachel lässt oben Platz für Titel und Vergrößern-Symbol der Visualisierung; Struktur wird beim ersten Öffnen eingelesen; klarer Hinweis statt leerer Kachel, wenn die Hauskategorie fehlt |
| 1.0 | 1 | 05.11.2026 | Erste Version: Haus im Schnitt mit Etagen, Keller, Treppenhaus, Garten und Nebengebäuden (Garage, Carport), automatische Erkennung, Licht und Solltemperatur aus der Kachel |

## 11. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk.
