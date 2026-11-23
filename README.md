# Venta Lokal for IP-Symcon

Local integration of **Venta humidifiers, air washers and air purifiers** into [IP-Symcon](https://www.symcon.de) – no
cloud, no account, no extra hardware – plus **any dehumidifier** on a switchable plug. A season automation runs the
humidifiers only in the heating season (when the ventilation brings dry air into the house) and the dehumidifiers when
the room is too humid. One **main tile** shows everything.

**Deutsch:** siehe [README.de.md](README.de.md).

## Modules

| Module | Purpose |
|---|---|
| **Venta Humidifier** | one Venta device (humidifier, air washer or air purifier) on the local network |
| **Generic Dehumidifier** | any dehumidifier switched by a variable (smart plug, relay, KNX), optional power measurement and humidity sensor |
| **Venta Automation** | season automation for all devices and the **main tile** |

## Features

* **Venta Humidifier**
  * room humidity, humidity and temperature at the device, water level, fan level/speed, PM2.5 (LP60, LPH60, LW60, AP902,
    AH902), warnings in plain text (refill water, door open, clean filter, change disc, cleaning, water inlet, service)
  * mode *Off / Automatic / Sleep / Level 1–3*, target humidity, LED strip – switchable from the visualization
  * the instance itself is a **tile**: buttons for mode, target 40/45/50/55 %, automation, LED, reset cleaning/disc
  * maintenance: hygiene disc and cleaning with remaining days and reset (two taps); first values from the device counters
  * plausibility check: error values (255, 65535, 4294967295) are discarded; three failed requests = offline;
    offline devices are only asked every 10 minutes
  * device kind automatic from the device type (humidifier / air washer / air purifier); purifiers are not switched by
    the season automation
* **Generic Dehumidifier**: on/off, room humidity, running and **tank-full detection** by power measurement, tile
* **Venta Automation**
  * heating season = heating in winter mode **and** 24 h mean outdoor temperature below 10 °C (ends above 14 °C) **and**
    ventilation running – every condition optional
  * humidifiers: day → automatic mode 45 %, night → sleep mode 50 %, nobody at home → off; mould protection off from 60 %
  * dehumidifiers: on above 60 % room humidity (off 5 % below), by default only outside the heating season
  * manual operation has priority for 4 hours; at most one command per device every 10 minutes
  * targets and limits are variables (changeable in the visualization)
  * **main tile**: season conditions, main switch and all devices with humidity, state, warnings, maintenance and
    quick buttons (Off/Auto/Sleep, dehumidifier On/Off)
* Notifications (push to a tile visualization and/or own script): water shortage, tank full, cleaning/disc due, offline
  during the heating season – each once, removed when resolved

## Supported Venta devices

| Protocol | Transport | Devices | Tested |
|---|---|---|---|
| v0 | raw TCP, port 48000 | LP60, **LPH60**, LW60/LW60T, LW62/LW62T, AP902/AH902/AW902 | LPH60 |
| v2 | HTTP `POST /datastructure` | **LW73**/LW74, LP73/LP74 | LW73 |

Venta has no dehumidifiers with a local interface – use the *Generic Dehumidifier* for any brand. Venta devices built
since late 2024 partly use the Tuya cloud only; they are **not** supported. Protocol v3 (AH5xx, AS1xx) is not supported yet.

## Installation

Module Store: search for **Venta**, or Module Control: `https://github.com/cbeham1/SymconVenta`.

1. One **Venta Humidifier** per device: IP address (fixed IP / DHCP reservation recommended), protocol *automatic*.
2. Optional one **Generic Dehumidifier** per dehumidifier: switch variable, humidity sensor, power measurement.
3. One **Venta Automation**: add the devices to its list, select the condition variables, put the instance on a page of
   the tile visualization (main tile). Switch the automation on in the tile (two taps).

**Authorization of v0 devices (e.g. LPH60):** a new client must be allowed once. While the instance asks the device it
answers `NAUTH`/`PAUTH` (status *Authorization on the device required*) – confirm on the device (WLAN symbol/button,
short press only; a long press resets the WLAN).

## Notification script

Called with `$_IPS['ACTION']` = `add` or `remove`, `EVENT` (`water`, `tank`, `disc`, `cleaning`, `offline`, `auth`),
`TITLE`, `TEXT`, `INSTANCE`. The return value of `add` comes back as `RESULT` on `remove` (e.g. a message number).

## Functions

```php
VENTA_Update(int $id): bool;
VENTA_SetMode(int $id, int $mode);              // 0 off, 1 automatic, 2 sleep, 3..5 level 1..3
VENTA_SetTargetHumidity(int $id, int $humidity);
VENTA_SetLED(int $id, bool $on);
VENTA_ResetDisc(int $id);
VENTA_ResetCleaning(int $id);
VENTA_SendAction(int $id, string $json);        // e.g. '{"Power":true}'
VENTA_GetState(int $id): string;
VENTAD_SetPower(int $id, bool $on);
VENTAD_GetState(int $id): string;
VENTAA_Update(int $id): bool;
```

## Notes

The local interfaces are not documented by Venta; protocol details follow the Home Assistant integration
[ha-venta](https://github.com/Michsior14/ha-venta) and own tests. v0 devices accept only **one field per command**;
the module sends combined actions field by field. v0 answers end with a control character (0x1C) after the JSON.

## Changelog

* 1.2 – Venta Humidifier: reset buttons (hygiene disc, cleaning) and maintenance intervals as variables for own
  visualization pages; intervals changeable in the visualization (the properties are the initial values).
* 1.1 – library renamed to *Venta Lokal*; main tile in Venta Automation with all devices and quick buttons; Generic
  Dehumidifier; air purifiers (device kind, PM2.5); warnings in plain text; v0 water level as stage (yellow/red/OK).
* 1.0 – first version: Venta Humidifier (v0/v2, tile, maintenance, notifications) and Venta Automation.

## License

MIT, see [LICENSE](LICENSE). Venta is a trademark of Venta-Luftwäscher GmbH; this project is not affiliated with Venta.
