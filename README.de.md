# Venta für IP-Symcon

Lokale Einbindung von **Venta-Luftbefeuchtern und -Luftwäschern** in [IP-Symcon](https://www.symcon.de) – ohne Cloud,
ohne Konto, ohne Zusatzhardware. Dazu eine Saisonautomatik, die die Befeuchter nur im Winterbetrieb laufen lässt
(wenn die Wohnraumlüftung trockene Luft ins Haus bringt).

## Funktionen

* **Venta Humidifier** (eine Instanz je Gerät)
  * Raumfeuchte, Feuchte und Temperatur am Gerät, Wasserstand, Lüfterstufe/-drehzahl, Warncode, Online-Status
  * Modus *Aus / Automatik / Schlaf / Stufe 1–3*, Ziel-Luftfeuchte, LED-Leuchtstreifen – aus der Visualisierung schaltbar
  * die Instanz ist selbst eine **Kachel** der Kachel-Visualisierung: Knöpfe für Modus, Ziel 40/45/50/55 %, Automatik, LED
  * **Wartung**: Hygienedisk und Reinigung mit Resttagen, Reset-Knöpfe (zweimal tippen); Erstwerte aus den Zählern des Geräts
  * Plausibilitätsprüfung: Fehlwerte der Geräte (255, 65535, 4294967295) werden verworfen, 3 Fehlversuche = offline;
    Geräte ohne Verbindung werden nur alle 10 Minuten gefragt
  * Benachrichtigungen (Push an Kachel-Visualisierung und/oder eigenes Skript): Wassermangel, Reinigung/Disc fällig,
    offline im Winterbetrieb – jeweils einmal, Entfernen bei Behebung
* **Venta Automation** (eine Instanz für alle Geräte)
  * Winterbetrieb = Heizung auf Winter **und** Außentemperatur im 24-h-Mittel unter 10 °C (endet über 14 °C)
    **und** Wohnraumlüftung läuft – jede Bedingung optional
  * Tag → Automatik mit Ziel 45 %, Nacht → Schlafmodus mit Ziel 50 %, niemand zu Hause → aus
  * Schimmelschutz: ab 60 % Raumfeuchte aus, unter 55 % wieder ein
  * manuelle Bedienung hat 4 Stunden Vorrang; höchstens ein Befehl je Gerät alle 10 Minuten
  * Zielwerte und Grenzen sind Variablen (in der Visualisierung änderbar); die Instanz ist eine Kachel mit Hauptschalter

## Unterstützte Geräte

| Protokoll | Übertragung | Geräte | Getestet |
|---|---|---|---|
| v0 | TCP, Port 48000 | LP60, **LPH60**, LW60/LW60T, LW62/LW62T, AP902/AH902/AW902 | LPH60 |
| v2 | HTTP `POST /datastructure` | **LW73**/LW74, LP73/LP74 | LW73 |

Venta-Geräte ab Ende 2024 nutzen teils nur noch die Tuya-Cloud – ohne lokale Schnittstelle, **nicht** unterstützt.
Protokoll v3 (AH5xx, AS1xx) wird noch nicht unterstützt.

## Einrichtung

Module Control / Module Store: `https://github.com/cbeham1/SymconVenta`. Je Gerät eine Instanz **Venta Humidifier**
(IP-Adresse, Protokoll *Automatik*), optional eine **Venta Automation** und dort die Geräte eintragen.

**Freigabe bei v0-Geräten (z. B. LPH60):** Ein neuer Client muss einmal erlaubt werden. Solange antwortet das Gerät
mit `NAUTH`/`PAUTH` (Status *Freigabe am Gerät nötig*) – während die Instanz abfragt, am Gerät bestätigen
(WLAN-Symbol/-Taste nur kurz antippen; langes Drücken setzt das WLAN zurück).

Den Geräten eine feste IP geben (DHCP-Reservierung).

## Benachrichtigungsskript

Aufruf mit `$_IPS['ACTION']` = `add` bzw. `remove`, `EVENT` (`water`, `disc`, `cleaning`, `offline`, `auth`), `TITLE`,
`TEXT`, `INSTANCE`. Der Rückgabewert von `add` kommt bei `remove` als `RESULT` zurück (z. B. Nummer einer Meldung).

## Hinweise

Die lokalen Schnittstellen sind von Venta nicht dokumentiert; Grundlage sind die Home-Assistant-Integration
[ha-venta](https://github.com/Michsior14/ha-venta) und eigene Tests. v0-Geräte übernehmen **nur ein Feld je Befehl** –
das Modul sendet zusammengesetzte Befehle Feld für Feld.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Venta ist eine Marke der Venta-Luftwäscher GmbH; dieses Projekt steht in keiner
Verbindung zu Venta.
