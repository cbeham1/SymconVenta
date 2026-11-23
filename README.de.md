# Venta Lokal für IP-Symcon

Lokale Einbindung von **Venta-Luftbefeuchtern, -Luftwäschern und -Luftreinigern** in [IP-Symcon](https://www.symcon.de)
– ohne Cloud, ohne Konto, ohne Zusatzhardware – dazu **beliebige Luftentfeuchter** an einer schaltbaren Steckdose.
Eine Saisonautomatik lässt die Befeuchter nur im Winterbetrieb laufen (wenn die Wohnraumlüftung trockene Luft ins Haus
bringt) und die Entfeuchter, wenn es zu feucht ist. Eine **Hauptkachel** zeigt alles auf einen Blick.

## Module

| Modul | Zweck |
|---|---|
| **Venta Humidifier** | ein Venta-Gerät (Befeuchter, Luftwäscher oder Luftreiniger) im lokalen Netz |
| **Generic Dehumidifier** | beliebiger Luftentfeuchter über eine Schaltvariable (Zwischenstecker, Relais, KNX), optional Leistungsmessung und Feuchtesensor |
| **Venta Automation** | Saisonautomatik für alle Geräte und **Hauptkachel** |

## Funktionen

* **Venta Humidifier**
  * Raumfeuchte, Feuchte und Temperatur am Gerät, Wasserstand, Lüfterstufe/-drehzahl, Feinstaub PM2.5 (LP60, LPH60,
    LW60, AP902, AH902), Warnungen im Klartext (Wasser nachfüllen, Tür offen, Filter, Disc, Reinigung, Wasserzulauf, Service)
  * Modus *Aus / Automatik / Schlaf / Stufe 1–3*, Ziel-Luftfeuchte, LED-Leuchtstreifen – aus der Visualisierung schaltbar
  * die Instanz ist selbst eine **Kachel**: Knöpfe für Modus, Ziel 40/45/50/55 %, Automatik, LED, Reset Reinigung/Disc
  * Wartung: Hygienedisk und Reinigung mit Resttagen und Reset (zweimal tippen); Erstwerte aus den Zählern des Geräts
  * Plausibilitätsprüfung: Fehlwerte (255, 65535, 4294967295) werden verworfen; 3 Fehlversuche = offline;
    Geräte ohne Verbindung nur alle 10 Minuten gefragt
  * Geräteart automatisch aus dem Gerätetyp (Befeuchter / Luftwäscher / Luftreiniger); Luftreiniger werden von der
    Saisonautomatik nicht geschaltet
* **Generic Dehumidifier**: Ein/Aus, Raumfeuchte, Betrieb und **Tank-voll-Erkennung** über die Leistung, Kachel
* **Venta Automation**
  * Winterbetrieb = Heizung auf Winter **und** Außentemperatur im 24-h-Mittel unter 10 °C (endet über 14 °C) **und**
    Lüftung läuft – jede Bedingung optional
  * Befeuchter: Tag → Automatik 45 %, Nacht → Schlafmodus 50 %, niemand zu Hause → aus; Schimmelschutz ab 60 % aus
  * Entfeuchter: ein über 60 % Raumfeuchte (aus 5 % darunter), standardmäßig nur außerhalb des Winterbetriebs
  * manuelle Bedienung hat 4 Stunden Vorrang; höchstens ein Befehl je Gerät alle 10 Minuten
  * Zielwerte und Grenzen sind Variablen (in der Visualisierung änderbar)
  * **Hauptkachel**: Saisonbedingungen, Hauptschalter und alle Geräte mit Feuchte, Zustand, Warnungen, Wartung und
    Schnellknöpfen (Aus/Auto/Schlaf, Entfeuchter Ein/Aus)
* Benachrichtigungen (Push an Kachel-Visualisierung und/oder eigenes Skript): Wassermangel, Tank voll, Reinigung/Disc
  fällig, offline im Winterbetrieb – jeweils einmal, Entfernen bei Behebung

## Unterstützte Venta-Geräte

| Protokoll | Übertragung | Geräte | Getestet |
|---|---|---|---|
| v0 | TCP, Port 48000 | LP60, **LPH60**, LW60/LW60T, LW62/LW62T, AP902/AH902/AW902 | LPH60 |
| v2 | HTTP `POST /datastructure` | **LW73**/LW74, LP73/LP74 | LW73 |

Venta hat keine Luftentfeuchter mit lokaler Schnittstelle – dafür gibt es den *Generic Dehumidifier* für jede Marke.
Venta-Geräte ab Ende 2024 nutzen teils nur die Tuya-Cloud und werden **nicht** unterstützt; Protokoll v3 (AH5xx, AS1xx) noch nicht.

## Einrichtung

Module Store: nach **Venta** suchen, oder Module Control: `https://github.com/cbeham1/SymconVenta`.

1. Je Gerät eine **Venta Humidifier**: IP-Adresse (feste IP / DHCP-Reservierung empfohlen), Protokoll *Automatik*.
2. Optional je Entfeuchter eine **Generic Dehumidifier**: Schaltvariable, Feuchtesensor, Leistungsmessung.
3. Eine **Venta Automation**: Geräte in die Liste eintragen, Bedingungsvariablen wählen, Instanz auf eine Seite der
   Kachel-Visualisierung legen (Hauptkachel). Automatik in der Kachel einschalten (zweimal tippen).

**Freigabe bei v0-Geräten (z. B. LPH60):** Ein neuer Client muss einmal erlaubt werden. Solange antwortet das Gerät mit
`NAUTH`/`PAUTH` (Status *Freigabe am Gerät nötig*) – während die Instanz abfragt, am Gerät bestätigen (WLAN-Symbol/-Taste
nur kurz antippen; langes Drücken setzt das WLAN zurück).

## Benachrichtigungsskript

Aufruf mit `$_IPS['ACTION']` = `add` bzw. `remove`, `EVENT` (`water`, `tank`, `disc`, `cleaning`, `offline`, `auth`),
`TITLE`, `TEXT`, `INSTANCE`. Der Rückgabewert von `add` kommt bei `remove` als `RESULT` zurück (z. B. Nummer einer Meldung).

## Hinweise

Die lokalen Schnittstellen sind von Venta nicht dokumentiert; Grundlage sind die Home-Assistant-Integration
[ha-venta](https://github.com/Michsior14/ha-venta) und eigene Tests. v0-Geräte übernehmen **nur ein Feld je Befehl** –
das Modul sendet zusammengesetzte Befehle Feld für Feld.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Venta ist eine Marke der Venta-Luftwäscher GmbH; dieses Projekt steht in keiner
Verbindung zu Venta.
