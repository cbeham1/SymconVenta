<?php

declare(strict_types=1);

/**
 * Venta Automation – runs the humidifiers only in the heating season (when ventilation brings dry air into the house).
 *
 * Heating season = heating in winter mode (optional) AND 24 h mean of the outdoor temperature below "start" (ends above
 * "end") AND ventilation running (optional). Then: day -> automatic mode with target day, night -> sleep mode with target
 * night; nobody at home (optional) or outside the season -> off. Each device decides itself about mould protection,
 * manual priority and its own automation switch (VENTA_ApplyAutomation).
 */
class VentaAutomation extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const HUMIDIFIER_GUID = '{441260F2-692F-42DC-8E58-635EAD4615C1}';
    private const DEHUMIDIFIER_GUID = '{4EB72E04-B9DD-4228-A16E-B42059465F51}';
    // Ident => [caption, profile, default, position]
    private const SETTINGS = [
        'TargetDay'        => ['Target humidity day', 'VENTAA.Target', 45, 10],
        'TargetNight'      => ['Target humidity night', 'VENTAA.Target', 50, 11],
        'SeasonStart'      => ['Heating season below (outdoor 24 h mean)', 'VENTAA.Temperature', 10, 12],
        'SeasonEnd'        => ['Heating season ends above', 'VENTAA.Temperature', 14, 13],
        'HumidityOff'      => ['Off from room humidity (mould protection)', 'VENTAA.HumidityOff', 60, 14],
        'DehumidifyTarget' => ['Dehumidifiers: on above', 'VENTAA.HumidityOff', 60, 15]
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('HeatingSeasonVariable', 0);
        $this->RegisterPropertyBoolean('HeatingWinterValue', false);
        $this->RegisterPropertyInteger('OutdoorTemperatureVariable', 0);
        $this->RegisterPropertyInteger('VentilationVariable', 0);
        $this->RegisterPropertyFloat('VentilationThreshold', 15.0);
        $this->RegisterPropertyInteger('PresenceVariable', 0);
        $this->RegisterPropertyInteger('DayNightVariable', 0);
        $this->RegisterPropertyBoolean('DayValue', true);
        $this->RegisterPropertyInteger('UpdateInterval', 120);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('DehumidifyInHeatingSeason', false);

        $this->RegisterAttributeBoolean('Season', false);

        $this->RegisterTimer('Update', 0, 'VENTAA_Update($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->RegisterVariableBoolean('Active', $this->Translate('Automation'), '~Switch', 1);
        $this->EnableAction('Active');
        $this->RegisterVariableBoolean('HeatingSeason', $this->Translate('Heating season'), 'VENTAA.Season', 2);
        $this->RegisterVariableFloat('Outdoor24h', $this->Translate('Outdoor temperature 24 h mean'), '~Temperature', 3);
        $this->RegisterVariableString('Reason', $this->Translate('Reason'), '', 4);
        foreach (self::SETTINGS as $ident => [$caption, $profile, $default, $position]) {
            $new = @$this->GetIDForIdent($ident) === false;
            $this->RegisterVariableInteger($ident, $this->Translate($caption), $profile, $position);
            $this->EnableAction($ident);
            if ($new) {
                $this->SetValue($ident, $default);
            }
        }
        $this->SetTimerInterval('Update', max(30, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        // main tile: follow the state variables of the devices
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        foreach ($this->DeviceIDs() as $id) {
            foreach (['Online', 'Mode', 'Status', 'RoomHumidity', 'Power', 'Humidity', 'TankFull'] as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $id);
                if ($vid !== false) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }
        $this->SetStatus(IS_ACTIVE);
        $this->UpdateTile($this->Conditions());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        // at most every 3 seconds
        if ($Message === VM_UPDATE && microtime(true) - (float) $this->GetBuffer('TileUpdate') >= 3) {
            $this->SetBuffer('TileUpdate', (string) microtime(true));
            $this->UpdateTile($this->Conditions());
        }
    }

    public function GetVisualizationTile(): string
    {
        $json = json_encode($this->TileData($this->Conditions()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('/*__DATA__*/null', $json, (string) file_get_contents(__DIR__ . '/tile.html'));
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'DeviceMode') {
            // quick operation from the main tile: {"id": instance, "mode": 0..5} (dehumidifier: 0 off, 1 on)
            $v = json_decode((string) $Value, true);
            $id = (int) ($v['id'] ?? 0);
            if (in_array($id, $this->DeviceIDs(), true)) {
                if ($this->IsDehumidifier($id)) {
                    VENTAD_SetPower($id, (int) ($v['mode'] ?? 0) > 0);
                } else {
                    VENTA_SetMode($id, (int) ($v['mode'] ?? 0));
                }
            }
            $this->UpdateTile($this->Conditions());
            return;
        }
        if ($Ident === 'Active') {
            $this->SetValue('Active', (bool) $Value);
        } elseif (isset(self::SETTINGS[$Ident])) {
            $this->SetValue($Ident, (int) $Value);
        } else {
            throw new Exception('Invalid Ident');
        }
        $this->Update();
    }

    /** Evaluates the conditions and passes the result to all devices. */
    public function Update(): bool
    {
        $c = $this->Conditions();
        $this->WriteAttributeBoolean('Season', $c['tempOk'] && $c['winter']);
        $this->SetValue('HeatingSeason', $c['season']);
        if ($c['outdoor'] !== null) {
            $this->SetValue('Outdoor24h', $c['outdoor']);
        }
        $mode = 0;
        if (!$c['season']) {
            $missing = array_filter([$c['winter'] ? '' : $this->Translate('heating in summer mode'), $c['tempOk'] ? '' : $this->Translate('too warm outside'),
                $c['ventilation'] ? '' : $this->Translate('ventilation off')]);
            $reason = $this->Translate('No heating season') . ($missing === [] ? '' : ' (' . implode(', ', $missing) . ')');
        } elseif (!$c['presence']) {
            $reason = $this->Translate('Nobody at home');
        } else {
            $mode = $c['day'] ? 1 : 2;
            $reason = sprintf($this->Translate($c['day'] ? 'Day: automatic mode %d %%' : 'Night: sleep mode %d %%'), $c['target']);
        }
        $this->SetValue('Reason', $this->GetValue('Active') ? $reason : $this->Translate('Automation off – devices are not switched'));
        $state = json_encode(['season' => $c['season'], 'mode' => $mode, 'target' => $c['target'], 'humidityOff' => $this->GetValue('HumidityOff'), 'reason' => $reason]);
        $dehumidify = $this->ReadPropertyBoolean('DehumidifyInHeatingSeason') || !$c['season'];
        $dehumState = json_encode(['season' => $c['season'], 'dehumidify' => $dehumidify, 'target' => (int) $this->GetValue('DehumidifyTarget'),
            'reason'                        => $dehumidify ? '' : $this->Translate('Heating season – dehumidifiers paused')]);
        foreach ($this->DeviceIDs() as $id) {
            $dehum = $this->IsDehumidifier($id);
            if (!$this->GetValue('Active')) {
                // only report the season (offline notifications), do not switch
                $off = json_encode(['season' => $c['season'], 'mode' => -1]);
                $dehum ? VENTAD_ApplyAutomation($id, $off) : VENTA_ApplyAutomation($id, $off);
            } else {
                $dehum ? VENTAD_ApplyAutomation($id, $dehumState) : VENTA_ApplyAutomation($id, $state);
            }
        }
        $this->UpdateTile($c);
        return true;
    }

    private function DeviceIDs(): array
    {
        $ids = [];
        foreach (json_decode($this->ReadPropertyString('Devices'), true) ?: [] as $row) {
            $id = (int) ($row['InstanceID'] ?? 0);
            if ($id > 0 && IPS_InstanceExists($id) && in_array(IPS_GetInstance($id)['ModuleInfo']['ModuleID'], [self::HUMIDIFIER_GUID, self::DEHUMIDIFIER_GUID], true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function IsDehumidifier(int $id): bool
    {
        return IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::DEHUMIDIFIER_GUID;
    }

    /** Short state of all devices for the main tile. */
    private function DeviceStates(): array
    {
        $list = [];
        foreach ($this->DeviceIDs() as $id) {
            $dehum = $this->IsDehumidifier($id);
            // devices may be reloading (module update, kernel start) -> skip their state instead of warnings
            if (IPS_GetKernelRunlevel() !== KR_READY || IPS_GetInstance($id)['InstanceStatus'] < IS_ACTIVE) {
                $s = [];
            } else {
                try {
                    $s = json_decode((string) @($dehum ? VENTAD_GetState($id) : VENTA_GetState($id)), true) ?: [];
                } catch (Throwable $e) {
                    $s = [];
                }
            }
            $s['id'] = $id;
            $s['dehum'] = $dehum;
            $s['name'] = IPS_GetName($id);
            if ($dehum) {
                $s['kindText'] = $this->Translate('Dehumidifier');
                $s['mode'] = !empty($s['on']) ? 1 : 0;
            }
            $list[] = $s;
        }
        return $list;
    }

    private function Conditions(): array
    {
        $winterId = $this->ReadPropertyInteger('HeatingSeasonVariable');
        $winter = $winterId <= 0 || !IPS_VariableExists($winterId) || (bool) GetValue($winterId) === $this->ReadPropertyBoolean('HeatingWinterValue');
        $outdoor = $this->Outdoor24h();
        $previous = $this->ReadAttributeBoolean('Season');
        $start = (float) $this->GetValueOr('SeasonStart', 10);
        $end = (float) $this->GetValueOr('SeasonEnd', 14);
        // hysteresis only on the temperature; short ventilation pauses do not reset it
        $tempOk = $outdoor === null || ($previous ? $outdoor <= $end : $outdoor < $start);
        $ventId = $this->ReadPropertyInteger('VentilationVariable');
        $ventValue = $ventId > 0 && IPS_VariableExists($ventId) ? GetValue($ventId) : null;
        $ventilation = $ventValue === null || (is_bool($ventValue) ? $ventValue : (float) $ventValue > $this->ReadPropertyFloat('VentilationThreshold'));
        $presenceId = $this->ReadPropertyInteger('PresenceVariable');
        $presence = $presenceId <= 0 || !IPS_VariableExists($presenceId) || (bool) GetValue($presenceId);
        $dayId = $this->ReadPropertyInteger('DayNightVariable');
        $day = $dayId > 0 && IPS_VariableExists($dayId)
            ? (bool) GetValue($dayId) === $this->ReadPropertyBoolean('DayValue')
            : ((int) date('G') >= 7 && (int) date('G') < 21);
        return [
            'winter'      => $winter, 'outdoor' => $outdoor, 'tempOk' => $tempOk, 'ventilation' => $ventilation,
            'ventValue'   => $ventValue, 'presence' => $presence, 'day' => $day,
            'season'      => $winter && $tempOk && $ventilation,
            'target'      => (int) $this->GetValueOr($day ? 'TargetDay' : 'TargetNight', $day ? 45 : 50),
            'start'       => $start, 'end' => $end
        ];
    }

    private function GetValueOr(string $ident, $default)
    {
        $id = @$this->GetIDForIdent($ident);
        return $id === false || $id === 0 ? $default : GetValue($id);
    }

    /** 24 h mean from the archive (hourly aggregation), otherwise the current value. */
    private function Outdoor24h(): ?float
    {
        $id = $this->ReadPropertyInteger('OutdoorTemperatureVariable');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if ($archives !== [] && @AC_GetLoggingStatus($archives[0], $id)) {
            $values = @AC_GetAggregatedValues($archives[0], $id, 0, time() - 86400, time(), 0);
            if (is_array($values) && count($values) >= 12) {
                return round(array_sum(array_column($values, 'Avg')) / count($values), 1);
            }
        }
        return round((float) GetValue($id), 1);
    }

    private function RegisterProfiles(): void
    {
        $profiles = [
            'VENTAA.Target'      => ['Drops', ' %rF', [30, 65, 5]],
            'VENTAA.HumidityOff' => ['Drops', ' %rF', [50, 75, 5]],
            'VENTAA.Temperature' => ['Temperature', ' °C', [0, 20, 1]]
        ];
        foreach ($profiles as $name => [$icon, $suffix, $range]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            IPS_SetVariableProfileIcon($name, $icon);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileValues($name, $range[0], $range[1], $range[2]);
        }
        if (!IPS_VariableProfileExists('VENTAA.Season')) {
            IPS_CreateVariableProfile('VENTAA.Season', VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileIcon('VENTAA.Season', 'Snowflake');
        IPS_SetVariableProfileAssociation('VENTAA.Season', 0, $this->Translate('No'), '', -1);
        IPS_SetVariableProfileAssociation('VENTAA.Season', 1, $this->Translate('Active'), '', 0x5ec8e5);
    }

    private function UpdateTile(array $c): void
    {
        $this->UpdateVisualizationValue(json_encode($this->TileData($c), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function TileData(array $c): array
    {
        $translations = [];
        foreach (['Automation', 'ON', 'OFF', 'Heating season', 'Pause', 'Heating', 'Winter', 'Summer', 'Outdoor 24 h mean', 'Ventilation running',
            'Somebody at home', 'yes', 'no', 'Day – automatic', 'Night – sleep mode', 'Target', 'Sure?', 'Off from', 'not configured',
            'Target day', 'Target night', 'Devices', 'Off', 'Auto', 'Sleep', 'On', 'Online', 'Offline', 'Refill water', 'Disc', 'Cleaning',
            'days', 'due', 'no devices – add them in the instance configuration', 'Dehumidifier', 'Tank full'] as $text) {
            $translations[$text] = $this->Translate($text);
        }
        $vent = $c['ventValue'];
        return [
            'active'           => (bool) $this->GetValueOr('Active', false), 'season' => $c['season'], 'winter' => $c['winter'],
            'winterConfigured' => $this->ReadPropertyInteger('HeatingSeasonVariable') > 0,
            'outdoor'          => $c['outdoor'], 'tempOk' => $c['tempOk'], 'start' => $c['start'],
            'ventilation'      => $c['ventilation'], 'ventText' => $vent === null ? null : (is_bool($vent) ? ($vent ? 'ON' : 'OFF') : round((float) $vent) . ' W'),
            'presence'         => $c['presence'], 'presenceConfigured' => $this->ReadPropertyInteger('PresenceVariable') > 0,
            'day'              => $c['day'], 'target' => $c['target'], 'targetDay' => (int) $this->GetValueOr('TargetDay', 45),
            'targetNight'      => (int) $this->GetValueOr('TargetNight', 50), 'humidityOff' => (int) $this->GetValueOr('HumidityOff', 60),
            'reason'           => (string) $this->GetValueOr('Reason', ''), 'theme' => $this->ReadPropertyInteger('TileTheme'), 't' => $translations,
            'devices'          => $this->DeviceStates(), 'dehumTarget' => (int) $this->GetValueOr('DehumidifyTarget', 60)
        ];
    }
}
