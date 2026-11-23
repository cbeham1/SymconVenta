# Venta for IP-Symcon

Local integration of **Venta humidifiers and airwashers** into [IP-Symcon](https://www.symcon.de) – no cloud, no account,
no extra hardware. The module talks directly to the device in your network and adds a season automation that runs the
humidifiers only in the heating season (when the ventilation brings dry air into the house).

**Deutsch:** siehe [README.de.md](README.de.md).

## Features

* **Venta Humidifier** (one instance per device)
  * room humidity, humidity and temperature at the device, water level, fan level/speed, warning code, online state
  * mode *Off / Automatic / Sleep / Level 1–3*, target humidity, LED strip – switchable from the visualization
  * the instance itself is a **tile** for the tile visualization: buttons for mode, target 40/45/50/55 %, automation, LED
  * **maintenance**: hygiene disc and cleaning with remaining days, reset buttons (two taps), first values estimated
    from the counters of the device
  * plausibility check: error values of the devices (255, 65535, 4294967295) are discarded, three failed requests in a
    row = offline (no stale values shown as current); offline devices are only asked every 10 minutes
  * notifications (push to a tile visualization and/or own script): water shortage, cleaning/disc due, offline during
    the heating season – each once, removed when resolved
* **Venta Automation** (one instance for all devices)
  * heating season = heating in winter mode **and** 24 h mean outdoor temperature below 10 °C (ends above 14 °C)
    **and** ventilation running – every condition optional
  * day → automatic mode, target 45 %; night → sleep mode, target 50 %; nobody at home → off
  * mould protection: off from 60 % room humidity, on again below 55 %
  * manual operation has priority for 4 hours; at most one command per device every 10 minutes
  * targets and limits are variables (changeable in the visualization); the instance is a tile with the main switch

## Supported devices

| Protocol | Transport | Devices | Tested |
|---|---|---|---|
| v0 | raw TCP, port 48000 | LP60, **LPH60**, LW60/LW60T, LW62/LW62T, AP902/AH902/AW902 | LPH60 |
| v2 | HTTP `POST /datastructure` | **LW73**/LW74, LP73/LP74 | LW73 |

Venta devices built since late 2024 partly use the Tuya cloud only – they have no local interface and are **not**
supported. Protocol v3 devices (AH5xx, AS1xx) are not supported yet.

## Installation

Module Control / Module Store: add `https://github.com/cbeham1/SymconVenta`. Create one **Venta Humidifier** per device
(IP address; protocol *automatic*) and optionally one **Venta Automation**, then add the humidifiers to its list.

**Authorization of v0 devices (e.g. LPH60):** a new client must be allowed once. While the instance asks the device it
answers `NAUTH`/`PAUTH` (status *Authorization on the device required*) – confirm on the device (WLAN symbol/button,
short press only; a long press resets the WLAN). After that the device answers normally.

Give the devices a fixed IP address (DHCP reservation).

## Notification script

`NotifyScript` is called with `$_IPS['ACTION']` = `add` or `remove`, `EVENT` (`water`, `disc`, `cleaning`, `offline`,
`auth`), `TITLE`, `TEXT`, `INSTANCE`. The return value of `add` is passed back as `RESULT` on `remove` (e.g. the number
of a message to delete).

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
VENTAA_Update(int $id): bool;
```

## Notes

The local interfaces are not documented by Venta; protocol details follow the Home Assistant integration
[ha-venta](https://github.com/Michsior14/ha-venta) and own tests. v0 devices accept only **one field per command**;
the module sends combined actions field by field.

## Changelog

* 1.0 – first version: Venta Humidifier (v0/v2, tile, maintenance, notifications) and Venta Automation.

## License

MIT, see [LICENSE](LICENSE). Venta is a trademark of Venta-Luftwäscher GmbH; this project is not affiliated with Venta.
