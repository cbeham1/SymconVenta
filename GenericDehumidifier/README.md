# Generic Dehumidifier

Any dehumidifier that is switched by a variable (smart plug, relay, KNX actuator …), optionally with power measurement
(running / tank-full detection: switched on but below the power threshold for 10 minutes) and a room humidity sensor.

Added to a **Venta Automation** instance it is switched by room humidity: on above the dehumidify target, off below
target − 5 %; by default not during the heating season (so it does not work against the humidifiers). Manual operation
has priority for 4 hours. The instance is a tile (on/off, automation).

See the [main README](../README.md). Deutsch: siehe [README.de.md](../README.de.md).
