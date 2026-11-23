<?php

declare(strict_types=1);

/**
 * Venta Humidifier – one Venta humidifier / airwasher on the local network (no cloud).
 *
 * Protocols (local, undocumented, see README):
 *  - v0: raw TCP port 48000 ("GET /Complete", "POST /Action"), header with Hash/DeviceName/DeviceType/MacAddress.
 *        Devices: LP60/LPH60, LW60(T), LW62(T), AH902/AW902. A new client must be authorized on the device once
 *        (answer NAUTH/PAUTH until then). Only ONE field per POST /Action is accepted.
 *  - v2: HTTP POST /datastructure (empty body = read, {"Action":{...}} = set). Devices: LW73/LW74, LP73/LP74.
 */
class VentaHumidifier extends IPSModule
{
    private const STATUS_NO_HOST = 201;
    private const STATUS_OFFLINE = 202;
    private const STATUS_NOT_AUTHORIZED = 203;
    private const STATUS_UNKNOWN_PROTOCOL = 204;

    private const PROTOCOL_AUTO = 0;
    private const PROTOCOL_V0 = 1;
    private const PROTOCOL_V2 = 2;

    private const SENTINELS = [255.0, 65535.0, 4294967295.0];
    private const FAILURES_OFFLINE = 3;
    private const OFFLINE_RETRY = 600;
    private const MANUAL_OVERRIDE = 14400;
    private const COMMAND_PAUSE = 600;
    private const WATER_DELAY = 600;
    private const OFFLINE_NOTIFY = 7200;
    private const HUMIDITY_HYSTERESIS = 5.0;
    private const MODES = [0 => 'Off', 1 => 'Automatic', 2 => 'Sleep mode', 3 => 'Level 1', 4 => 'Level 2', 5 => 'Level 3'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Protocol', self::PROTOCOL_AUTO);
        $this->RegisterPropertyInteger('UpdateInterval', 120);
        $this->RegisterPropertyInteger('RoomHumidityVariable', 0);
        $this->RegisterPropertyFloat('TankVolume', 7.0);
        $this->RegisterPropertyInteger('DiscInterval', 120);
        $this->RegisterPropertyInteger('CleaningInterval', 14);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyInteger('NotifyVisualization', 0);
        $this->RegisterPropertyInteger('NotifyScript', 0);
        $this->RegisterPropertyBoolean('NotifyWater', true);
        $this->RegisterPropertyBoolean('NotifyMaintenance', true);
        $this->RegisterPropertyBoolean('NotifyOffline', true);

        $this->RegisterAttributeInteger('DetectedProtocol', 0);
        $this->RegisterAttributeString('Mac', '');
        $this->RegisterAttributeInteger('DeviceType', 0);
        $this->RegisterAttributeBoolean('HasLED', false);
        $this->RegisterAttributeInteger('Failures', 0);
        $this->RegisterAttributeInteger('LastTry', 0);
        $this->RegisterAttributeInteger('OfflineSince', 0);
        $this->RegisterAttributeInteger('ManualUntil', 0);
        $this->RegisterAttributeInteger('LastCommand', 0);
        $this->RegisterAttributeInteger('WaterSince', 0);
        $this->RegisterAttributeBoolean('TooHumid', false);
        $this->RegisterAttributeBoolean('SeasonActive', false);
        $this->RegisterAttributeString('Estimate', '{"Disc":true,"Cleaning":true}');
        $this->RegisterAttributeString('Notified', '{}');

        $this->RegisterTimer('Update', 0, 'VENTA_Update($_IPS[\'TARGET\']);');

        // The instance itself is a tile of the tile visualization (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->MaintainVariables();
        if ($this->ReadPropertyInteger('Protocol') !== self::PROTOCOL_AUTO) {
            $this->WriteAttributeInteger('DetectedProtocol', $this->ReadPropertyInteger('Protocol'));
        }
        if ($this->ReadPropertyString('Host') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(self::STATUS_NO_HOST);
            $this->UpdateTile();
            return;
        }
        $this->WriteAttributeInteger('LastTry', 0);
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('Update', 5000);
        $this->UpdateTile();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $protocol = $this->ReadAttributeInteger('DetectedProtocol');
        $text = $protocol === self::PROTOCOL_V0 ? 'v0 (TCP 48000)' : ($protocol === self::PROTOCOL_V2 ? 'v2 (HTTP)' : '–');
        $form['actions'][] = ['type' => 'Label', 'caption' => $this->Translate('Detected protocol') . ': ' . $text
            . ($this->ReadAttributeString('Mac') !== '' ? ' · MAC ' . $this->ReadAttributeString('Mac') : '')];
        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        $json = json_encode($this->TileData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('/*__DATA__*/null', $json, (string) file_get_contents(__DIR__ . '/tile.html'));
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Mode':
                $this->SetMode((int) $Value);
                break;
            case 'TargetHumidity':
                $this->SetTargetHumidity((int) $Value);
                break;
            case 'LED':
                $this->SetLED((bool) $Value);
                break;
            case 'Automatic':
                $this->SetValue('Automatic', (bool) $Value);
                if ($Value) {
                    $this->WriteAttributeInteger('ManualUntil', 0);
                }
                $this->UpdateTile();
                break;
            case 'ResetDisc':
                $this->ResetDisc();
                break;
            case 'ResetCleaning':
                $this->ResetCleaning();
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    /** Reads the device and updates all variables; returns false if the device did not answer. */
    public function Update(): bool
    {
        $this->SetTimerInterval('Update', max(30, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        if ($this->ReadPropertyString('Host') === '') {
            return false;
        }
        $online = $this->GetValue('Online');
        $now = time();
        // Offline devices (e.g. stored away in summer) are only asked every 10 minutes
        $sender = $_IPS['SENDER'] ?? '';
        if (!$online && $sender === 'TimerEvent' && $now - $this->ReadAttributeInteger('LastTry') < self::OFFLINE_RETRY) {
            $this->UpdateMaintenance();
            return false;
        }
        $this->WriteAttributeInteger('LastTry', $now);
        $result = $this->Read();
        if (!$result['ok']) {
            $failures = $this->ReadAttributeInteger('Failures') + 1;
            $this->WriteAttributeInteger('Failures', $failures);
            $this->SendDebug('Update', 'Failure ' . $failures . ': ' . $result['error'], 0);
            if (!empty($result['auth'])) {
                $this->SetValueIfChanged('Status', $this->Translate('Authorization on the device required'));
                $this->SetStatus(self::STATUS_NOT_AUTHORIZED);
                $this->Notify('auth', $this->Translate('Authorization on the device required') . ' (' . $result['error'] . ')');
            }
            if ($failures >= self::FAILURES_OFFLINE) {
                if ($online || $this->ReadAttributeInteger('OfflineSince') === 0) {
                    $this->WriteAttributeInteger('OfflineSince', $now);
                    $this->LogMessage($this->Translate('Device offline') . ': ' . $result['error'], KL_WARNING);
                }
                $this->SetValueIfChanged('Online', false);
                if (empty($result['auth'])) {
                    $this->SetValueIfChanged('Status', $this->Translate('Offline'));
                    $this->SetStatus(self::STATUS_OFFLINE);
                }
                $this->CheckOfflineNotification();
            }
            $this->UpdateMaintenance();
            $this->UpdateTile();
            return false;
        }
        $this->ClearNotification('auth');
        $this->ClearNotification('offline');
        $this->WriteAttributeInteger('Failures', 0);
        $this->WriteAttributeInteger('OfflineSince', 0);
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->ApplyData($this->Normalize($result['data']));
        $this->UpdateMaintenance();
        $this->UpdateTile();
        return true;
    }

    /** 0 Off, 1 Automatic, 2 Sleep mode, 3..5 fan level 1..3. Manual operation has priority over the automation for 4 h. */
    public function SetMode(int $Mode): bool
    {
        $Mode = max(0, min(5, $Mode));
        $target = $this->GetValue('TargetHumidity');
        $ok = $this->Send($this->ModeAction($Mode, $target > 0 ? $target : null), 'manual');
        $this->WriteAttributeInteger('ManualUntil', time() + self::MANUAL_OVERRIDE);
        if ($ok) {
            $this->SetValue('Mode', $Mode);
        }
        $this->Update();
        return $ok;
    }

    public function SetTargetHumidity(int $Humidity): bool
    {
        $Humidity = max(30, min(70, $Humidity));
        $ok = $this->Send(['TargetHum' => $Humidity], 'manual');
        $this->WriteAttributeInteger('ManualUntil', time() + self::MANUAL_OVERRIDE);
        if ($ok) {
            $this->SetValue('TargetHumidity', $Humidity);
        }
        $this->Update();
        return $ok;
    }

    public function SetLED(bool $On): bool
    {
        $ok = $this->Send(['LEDStripActive' => $On], 'manual');
        if ($ok && @$this->GetIDForIdent('LED')) {
            $this->SetValue('LED', $On);
        }
        $this->Update();
        return $ok;
    }

    /** Hygiene disc changed: the remaining time starts again. */
    public function ResetDisc(): void
    {
        $this->ResetMaintenance('Disc', 'DiscChanged');
    }

    /** Device cleaned: the remaining time starts again. */
    public function ResetCleaning(): void
    {
        $this->ResetMaintenance('Cleaning', 'Cleaned');
    }

    /** Sends a raw action, e.g. VENTA_SendAction($id, '{"Power":true}'). */
    public function SendAction(string $Action): bool
    {
        $action = json_decode($Action, true);
        if (!is_array($action)) {
            return false;
        }
        $ok = $this->Send($action, 'script');
        $this->Update();
        return $ok;
    }

    /**
     * Called by the Venta Automation instance. $State (JSON): season (bool), mode (0 off, 1 automatic, 2 sleep), target (int),
     * humidityOff (float), reason (string). Returns the reason shown at the device.
     */
    public function ApplyAutomation(string $State): string
    {
        $s = json_decode($State, true);
        if (!is_array($s)) {
            return '';
        }
        $this->WriteAttributeBoolean('SeasonActive', (bool) ($s['season'] ?? false));
        // mode -1: the automation instance is switched off and only reports the season
        $reason = (int) ($s['mode'] ?? 0) < 0 ? $this->Translate('Season automation off – device is not switched') : $this->DecideAutomation($s);
        $this->SetValueIfChanged('Reason', $reason);
        $this->CheckOfflineNotification();
        $this->UpdateTile();
        return $reason;
    }

    /** Compact state for the automation and scripts. */
    public function GetState(): string
    {
        return json_encode([
            'online'   => $this->GetValue('Online'), 'mode' => $this->GetValue('Mode'), 'target' => $this->GetValue('TargetHumidity'),
            'humidity' => $this->GetValue('RoomHumidity'), 'water' => $this->GetValue('WaterShortage'),
            'disc'     => $this->GetValue('DiscRemaining'), 'cleaning' => $this->GetValue('CleaningRemaining')
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Automation
    // ---------------------------------------------------------------------------------------------------------------

    private function DecideAutomation(array $s): string
    {
        if (!$this->GetValue('Automatic')) {
            return $this->Translate('Automation off for this device');
        }
        if (!$this->GetValue('Online')) {
            return $this->Translate('Device offline');
        }
        $now = time();
        if ($this->ReadAttributeInteger('ManualUntil') > $now) {
            return sprintf($this->Translate('Operated manually – automation again at %s'), date('H:i', $this->ReadAttributeInteger('ManualUntil')));
        }
        $limit = (float) ($s['humidityOff'] ?? 60);
        $humidity = $this->GetValue('RoomHumidity');
        $tooHumid = $this->ReadAttributeBoolean('TooHumid');
        if ($humidity > 0) {
            $tooHumid = $tooHumid ? $humidity >= $limit - self::HUMIDITY_HYSTERESIS : $humidity >= $limit;
            $this->WriteAttributeBoolean('TooHumid', $tooHumid);
        }
        $mode = (int) ($s['mode'] ?? 0);
        $reason = (string) ($s['reason'] ?? '');
        if ($mode > 0 && $tooHumid) {
            $mode = 0;
            $reason = sprintf($this->Translate('Room humidity ≥ %d %% (mould protection)'), (int) $limit);
        }
        $target = $mode > 0 ? (int) ($s['target'] ?? 45) : null;
        $current = $this->GetValue('Mode');
        $fits = $mode === 0 ? $current === 0 : ($current === $mode && $this->GetValue('TargetHumidity') === $target);
        if ($fits) {
            return $reason;
        }
        if ($now - $this->ReadAttributeInteger('LastCommand') < self::COMMAND_PAUSE) {
            return $reason . ' (' . $this->Translate('command pending') . ')';
        }
        $this->WriteAttributeInteger('LastCommand', $now);
        $this->Send($this->ModeAction($mode, $target), 'automation: ' . $reason);
        $this->Update();
        return $reason;
    }

    private function ModeAction(int $mode, ?int $target): array
    {
        if ($mode === 0) {
            return ['Power' => false];
        }
        $action = ['Power' => true];
        if ($mode === 1) {
            $action += ['Automatic' => true, 'SleepMode' => false];
        } elseif ($mode === 2) {
            $action += ['Automatic' => true, 'SleepMode' => true];
        } else {
            $action += ['Automatic' => false, 'SleepMode' => false, 'FanSpeed' => $mode - 2];
        }
        if ($target !== null && $mode <= 2) {
            $action['TargetHum'] = $target;
        }
        return $action;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Communication
    // ---------------------------------------------------------------------------------------------------------------

    private function Read(): array
    {
        $protocol = $this->ReadAttributeInteger('DetectedProtocol');
        if ($protocol === self::PROTOCOL_V0) {
            return $this->Tcp(null);
        }
        if ($protocol === self::PROTOCOL_V2) {
            return $this->Http(null);
        }
        // Automatic detection: HTTP (v2) first, then TCP 48000 (v0)
        $http = $this->Http(null);
        if ($http['ok']) {
            $this->WriteAttributeInteger('DetectedProtocol', self::PROTOCOL_V2);
            return $http;
        }
        $tcp = $this->Tcp(null);
        if ($tcp['ok'] || !empty($tcp['auth'])) {
            $this->WriteAttributeInteger('DetectedProtocol', self::PROTOCOL_V0);
            return $tcp;
        }
        return ['ok' => false, 'error' => 'HTTP: ' . $http['error'] . ' / TCP 48000: ' . $tcp['error']];
    }

    private function Send(array $action, string $source): bool
    {
        $protocol = $this->ReadAttributeInteger('DetectedProtocol');
        if ($protocol === self::PROTOCOL_V0) {
            // v0 devices accept only ONE field per command – several fields in one command are silently ignored
            $ok = true;
            foreach (['Power', 'Automatic', 'SleepMode', 'FanSpeed', 'TargetHum', 'LEDStripActive'] as $field) {
                if (!array_key_exists($field, $action) || ($field !== 'Power' && ($action['Power'] ?? true) === false)) {
                    continue;
                }
                $ok = $this->Tcp([$field => $action[$field]])['ok'] && $ok;
                IPS_Sleep(800);
            }
            foreach (array_diff_key($action, array_flip(['Power', 'Automatic', 'SleepMode', 'FanSpeed', 'TargetHum', 'LEDStripActive'])) as $field => $value) {
                $ok = $this->Tcp([$field => $value])['ok'] && $ok;
                IPS_Sleep(800);
            }
        } elseif ($protocol === self::PROTOCOL_V2) {
            $ok = $this->Http($action)['ok'];
        } else {
            $ok = false;
        }
        $this->SendDebug('Send', json_encode($action) . ' (' . $source . ') -> ' . ($ok ? 'OK' : 'FAILED'), 0);
        if (!$ok) {
            $this->LogMessage($this->Translate('Command failed') . ': ' . json_encode($action), KL_WARNING);
        }
        return $ok;
    }

    /** v2: HTTP POST /datastructure. */
    private function Http(?array $action): array
    {
        $ch = curl_init('http://' . $this->ReadPropertyString('Host') . '/datastructure');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $action === null ? '' : json_encode(['Action' => $action])
        ]);
        $answer = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $this->SendDebug('HTTP', ($action === null ? 'read' : json_encode($action)) . ' -> ' . $code . ' ' . (is_string($answer) ? $answer : $error), 0);
        if (!is_string($answer) || $code !== 200) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP ' . $code];
        }
        $data = json_decode($answer, true);
        if (!is_array($data) || !isset($data['Measure'], $data['Action'])) {
            return ['ok' => false, 'error' => 'no measurements in answer'];
        }
        return ['ok' => true, 'data' => $data];
    }

    /** v0: raw TCP port 48000. $action = null -> "GET /Complete", otherwise "POST /Action". */
    private function Tcp(?array $action): array
    {
        $header = ['Hash' => '-42', 'DeviceName' => 'Symcon'];
        if ($this->ReadAttributeString('Mac') !== '') {
            $header += ['DeviceType' => $this->ReadAttributeInteger('DeviceType'), 'MacAddress' => $this->ReadAttributeString('Mac')];
        }
        $body = json_encode(['Header' => $header] + ($action === null ? [] : ['Action' => $action]), JSON_UNESCAPED_SLASHES);
        $message = ($action === null ? 'GET /Complete' : 'POST /Action') . "\nContent-Length: " . strlen($body) . "\n" . $body;
        $last = '';
        for ($try = 1; $try <= 3; $try++) {
            $socket = @fsockopen($this->ReadPropertyString('Host'), 48000, $errno, $errstr, 3);
            if ($socket === false) {
                return ['ok' => false, 'error' => 'TCP 48000: ' . $errstr];
            }
            stream_set_timeout($socket, 5);
            fwrite($socket, $message);
            $buffer = '';
            $start = microtime(true);
            while (!feof($socket) && microtime(true) - $start < 6 && strlen($buffer) < 20000) {
                $part = fread($socket, 4096);
                if ($part === false || ($part === '' && stream_get_meta_data($socket)['timed_out'])) {
                    break;
                }
                $buffer .= $part;
                // complete as soon as the body has reached the announced Content-Length
                if (preg_match('/Content-Length:\s*(\d+)\r?\n\r?\n?/', $buffer, $m, PREG_OFFSET_CAPTURE)
                    && strlen($buffer) - ($m[0][1] + strlen($m[0][0])) >= (int) $m[1][0]) {
                    break;
                }
            }
            fclose($socket);
            $last = $buffer;
            if ($buffer === '') {
                // the devices answer empty now and then -> retry
                IPS_Sleep(2000);
                continue;
            }
            $this->SendDebug('TCP', ($action === null ? 'read' : json_encode($action)) . ' -> ' . $buffer, 0);
            $first = (string) strtok($buffer, "\n");
            $data = $this->ExtractJson($buffer);
            // Remember MAC and device type from the header: needed for the authorization
            if (is_array($data['Header'] ?? null) && ($data['Header']['MacAdress'] ?? '') !== '' && $this->ReadAttributeString('Mac') === '') {
                $this->WriteAttributeString('Mac', (string) $data['Header']['MacAdress']);
                $this->WriteAttributeInteger('DeviceType', (int) ($data['Header']['DeviceType'] ?? 0));
                if ($try < 3) {
                    // ask again immediately with MAC and device type (otherwise the device answers NAUTH)
                    return $this->Tcp($action);
                }
            }
            if (preg_match('/^(NAUTH|PAUTH)/', $first, $m)) {
                return ['ok' => false, 'auth' => true, 'error' => $m[1]];
            }
            if ($action !== null) {
                return ['ok' => is_array($data), 'data' => $data, 'error' => is_array($data) ? '' : 'no confirmation'];
            }
            if (!is_array($data) || !isset($data['Measure'], $data['Action'])) {
                return ['ok' => false, 'error' => 'no measurements in answer'];
            }
            return ['ok' => true, 'data' => $data];
        }
        return ['ok' => false, 'error' => $last === '' ? 'no answer' : 'incomplete answer'];
    }

    /** JSON object of the answer; the devices append a control character (0x1C) after the JSON. */
    private function ExtractJson(string $buffer): ?array
    {
        $start = strpos($buffer, '{');
        $end = strrpos($buffer, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($buffer, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------------------------------------------------

    private function Valid($value): bool
    {
        return is_numeric($value) && !in_array((float) $value, self::SENTINELS, true);
    }

    private function Normalize(array $d): array
    {
        $a = $d['Action'] ?? [];
        $m = $d['Measure'] ?? [];
        $i = $d['Info'] ?? [];
        $v0 = $this->ReadAttributeInteger('DetectedProtocol') === self::PROTOCOL_V0;
        $h = $m['Humidity'] ?? null;
        $humidity = $this->Valid($h) && $h > 0 && $h < 100 ? (float) $h : null;
        $temperature = null;
        if ($this->Valid($m['Temperature'] ?? null)) {
            $t = (float) $m['Temperature'];
            if ((int) ($a['TempUnit'] ?? 0) === 1) {
                $t = ($t - 32) * 5 / 9;
            }
            $temperature = $t >= -5 && $t <= 50 ? round($t, 1) : null;
        }
        $power = (bool) ($a['Power'] ?? false);
        $sleep = (bool) ($a['SleepMode'] ?? false);
        $auto = (bool) ($a['Automatic'] ?? false);
        $fan = (int) ($a['FanSpeed'] ?? 0);
        $target = $a['TargetHum'] ?? null;
        $water = $m['WaterLevel'] ?? null;
        $rpm = $m['FanRpm'] ?? null;
        return [
            'power'      => $power,
            'mode'       => !$power ? 0 : ($sleep ? 2 : ($auto ? 1 : 2 + max(1, min(3, $fan)))),
            'target'     => $this->Valid($target) && $target > 0 && $target < 100 ? (int) $target : null,
            'humidity'   => $humidity,
            'temperature'=> $temperature,
            'water'      => !$v0 && $this->Valid($water) ? round((float) $water / 10000, 1) : null,
            'waterStage' => $v0 && $this->Valid($water) ? (int) $water : null,
            'fan'        => $this->Valid($fan) ? $fan : null,
            'rpm'        => $this->Valid($rpm) && $rpm < 10000 ? (int) $rpm : null,
            'warnings'   => (int) ($i['Warnings'] ?? 0),
            'led'        => isset($a['LEDStripActive']) ? (bool) $a['LEDStripActive'] : null,
            // operating time since the last disc change in 5-minute steps
            'discMinutes'=> $this->Valid($i['DiscIonT'] ?? null) ? (int) $i['DiscIonT'] * 5 : null,
            // v2: operating time since the last cleaning in 10-minute steps (service indicator at 2016 = 14 days)
            'serviceMinutes' => !$v0 && $this->Valid($i['ServiceT'] ?? null) ? (int) $i['ServiceT'] * 10 : null,
            'invalid'        => $humidity === null || $temperature === null
        ];
    }

    private function ApplyData(array $w): void
    {
        $now = time();
        if ($w['led'] !== null && !$this->ReadAttributeBoolean('HasLED')) {
            $this->WriteAttributeBoolean('HasLED', true);
            $this->MaintainVariables();
        }
        $this->MaintainWaterVariables();
        $this->SetValueIfChanged('Online', true);
        $this->SetValue('LastResponse', $now);
        $this->SetValueIfChanged('Mode', $w['mode']);
        if ($w['target'] !== null) {
            $this->SetValueIfChanged('TargetHumidity', $w['target']);
        }
        if ($w['led'] !== null) {
            $this->SetValueIfChanged('LED', $w['led']);
        }
        foreach (['humidity'   => 'DeviceHumidity', 'temperature' => 'Temperature', 'water' => 'WaterLevel', 'waterStage' => 'WaterStage',
            'fan'              => 'FanSpeed', 'rpm' => 'FanRpm'] as $key => $ident) {
            if ($w[$key] !== null) {
                $this->SetValueIfChanged($ident, $w[$key]);
            }
        }
        $this->SetValueIfChanged('Warnings', $w['warnings']);
        $room = $this->RoomHumidity($w['humidity']);
        if ($room !== null) {
            $this->SetValueIfChanged('RoomHumidity', round($room, 1));
        }

        // First contact: estimate the last disc change / cleaning from the counters of the device
        $estimate = json_decode($this->ReadAttributeString('Estimate'), true) ?: [];
        if (!empty($estimate['Disc']) && $w['discMinutes'] !== null) {
            $this->SetValue('DiscChanged', $now - $w['discMinutes'] * 60);
            unset($estimate['Disc']);
        }
        if (!empty($estimate['Cleaning'])) {
            if ($w['serviceMinutes'] !== null) {
                $this->SetValue('Cleaned', $now - $w['serviceMinutes'] * 60);
            } elseif ($this->GetValue('Cleaned') === 0) {
                $this->SetValue('Cleaned', $now);
            }
            unset($estimate['Cleaning']);
        }
        $this->WriteAttributeString('Estimate', json_encode($estimate));

        // Water shortage: warning bit for at least 10 minutes while switched on
        $shortage = $w['power'] && $w['warnings'] !== 0;
        if ($shortage && $this->ReadAttributeInteger('WaterSince') === 0) {
            $this->WriteAttributeInteger('WaterSince', $now);
        } elseif (!$shortage) {
            $this->WriteAttributeInteger('WaterSince', 0);
        }
        $this->SetValueIfChanged('WaterShortage', $shortage);
        if ($shortage && $now - $this->ReadAttributeInteger('WaterSince') >= self::WATER_DELAY) {
            if ($this->ReadPropertyBoolean('NotifyWater')) {
                $this->Notify('water', sprintf($this->Translate('Refill water (warning code %d)'), $w['warnings']));
            }
        } elseif (!$shortage) {
            $this->ClearNotification('water');
        }
        $status = $w['invalid'] ? 'Invalid values' : ($shortage ? 'Refill water' : ($w['power'] ? self::MODES[$w['mode']] : 'Off'));
        $this->SetValueIfChanged('Status', $this->Translate($status));
    }

    /** Room humidity: room sensor if it changed within 24 h, otherwise the sensor of the device. */
    private function RoomHumidity(?float $device): ?float
    {
        $id = $this->ReadPropertyInteger('RoomHumidityVariable');
        if ($id > 0 && IPS_VariableExists($id) && time() - IPS_GetVariable($id)['VariableChanged'] < 86400) {
            return (float) GetValue($id);
        }
        return $device;
    }

    private function UpdateMaintenance(): void
    {
        $estimate = json_decode($this->ReadAttributeString('Estimate'), true) ?: [];
        foreach (['Disc'  => ['DiscChanged', 'DiscRemaining', 'DiscInterval', 7, 'Change the hygiene disc'],
            'Cleaning'    => ['Cleaned', 'CleaningRemaining', 'CleaningInterval', 1, 'Clean the device']] as $key => [$date, $remaining, $interval, $warn, $text]) {
            $days = max(1, $this->ReadPropertyInteger($interval));
            $changed = $this->GetValue($date);
            $rest = $changed > 0 ? $days - (int) floor((time() - $changed) / 86400) : $days;
            $this->SetValueIfChanged($remaining, $rest);
            if ($rest <= $warn && empty($estimate[$key]) && $this->ReadPropertyBoolean('NotifyMaintenance')) {
                $this->Notify(strtolower($key), $this->Translate($text) . ': ' . ($rest <= 0
                    ? sprintf($this->Translate('due for %d days'), -$rest)
                    : sprintf($this->Translate('due in %d days'), $rest)));
            } else {
                $this->ClearNotification(strtolower($key));
            }
        }
    }

    private function ResetMaintenance(string $key, string $ident): void
    {
        $this->SetValue($ident, time());
        $estimate = json_decode($this->ReadAttributeString('Estimate'), true) ?: [];
        unset($estimate[$key]);
        $this->WriteAttributeString('Estimate', json_encode($estimate));
        $this->ClearNotification(strtolower($key));
        $this->UpdateMaintenance();
        $this->UpdateTile();
    }

    private function CheckOfflineNotification(): void
    {
        $since = $this->ReadAttributeInteger('OfflineSince');
        if ($since > 0 && $this->ReadAttributeBoolean('SeasonActive') && $this->GetValue('Automatic')
            && $this->ReadPropertyBoolean('NotifyOffline') && time() - $since >= self::OFFLINE_NOTIFY) {
            $this->Notify('offline', sprintf($this->Translate('Not reachable since %s – check power and WLAN'), date('d.m. H:i', $since)));
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Notifications: once per event, removed again when resolved
    // ---------------------------------------------------------------------------------------------------------------

    private function Notify(string $key, string $text): void
    {
        $notified = json_decode($this->ReadAttributeString('Notified'), true) ?: [];
        if (isset($notified[$key])) {
            return;
        }
        $title = IPS_GetName($this->InstanceID);
        $this->SendDebug('Notify', $key . ': ' . $text, 0);
        $visualization = $this->ReadPropertyInteger('NotifyVisualization');
        if ($visualization > 0 && IPS_InstanceExists($visualization) && function_exists('VISU_PostNotification')) {
            @VISU_PostNotification($visualization, $title, $text, 'Warning', $this->InstanceID);
        }
        $result = null;
        $script = $this->ReadPropertyInteger('NotifyScript');
        if ($script > 0 && IPS_ScriptExists($script)) {
            $result = IPS_RunScriptWaitEx($script, ['EVENT' => $key, 'ACTION' => 'add', 'TEXT' => $text, 'TITLE' => $title, 'INSTANCE' => $this->InstanceID]);
        }
        $notified[$key] = $result === null || $result === '' ? true : $result;
        $this->WriteAttributeString('Notified', json_encode($notified));
    }

    private function ClearNotification(string $key): void
    {
        $notified = json_decode($this->ReadAttributeString('Notified'), true) ?: [];
        if (!isset($notified[$key])) {
            return;
        }
        $script = $this->ReadPropertyInteger('NotifyScript');
        if ($script > 0 && IPS_ScriptExists($script)) {
            IPS_RunScriptWaitEx($script, ['EVENT' => $key, 'ACTION' => 'remove', 'RESULT' => $notified[$key], 'TITLE' => IPS_GetName($this->InstanceID), 'INSTANCE' => $this->InstanceID]);
        }
        unset($notified[$key]);
        $this->WriteAttributeString('Notified', json_encode($notified));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Variables, profiles, tile
    // ---------------------------------------------------------------------------------------------------------------

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('VENTA.Mode')) {
            IPS_CreateVariableProfile('VENTA.Mode', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon('VENTA.Mode', 'Drops');
        foreach (self::MODES as $value => $name) {
            IPS_SetVariableProfileAssociation('VENTA.Mode', $value, $this->Translate($name), '', $value === 1 ? 0x2ecc71 : ($value === 2 ? 0x5ec8e5 : -1));
        }
        // Suffix " %" with min/max would be shown as share of the range -> " %rF"
        $this->Profile('VENTA.Target', VARIABLETYPE_INTEGER, 'Drops', ' %rF', [30, 70, 5]);
        $this->Profile('VENTA.Humidity', VARIABLETYPE_FLOAT, 'Drops', ' %', null, 1);
        $this->Profile('VENTA.Liter', VARIABLETYPE_FLOAT, 'Drops', ' l', null, 1);
        $this->Profile('VENTA.Days', VARIABLETYPE_INTEGER, 'Calendar', ' ' . $this->Translate('days'));
        if (!IPS_VariableProfileExists('VENTA.Online')) {
            IPS_CreateVariableProfile('VENTA.Online', VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileIcon('VENTA.Online', 'Network');
        IPS_SetVariableProfileAssociation('VENTA.Online', 0, $this->Translate('Offline'), '', 0xe74c3c);
        IPS_SetVariableProfileAssociation('VENTA.Online', 1, $this->Translate('Online'), '', 0x2ecc71);
    }

    private function Profile(string $name, int $type, string $icon, string $suffix, ?array $range = null, int $digits = 0): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        if ($range !== null) {
            IPS_SetVariableProfileValues($name, $range[0], $range[1], $range[2]);
        }
    }

    private function MaintainVariables(): void
    {
        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), 'VENTA.Online', 1);
        $this->RegisterVariableString('Status', $this->Translate('Status'), '', 2);
        $this->RegisterVariableInteger('Mode', $this->Translate('Mode'), 'VENTA.Mode', 10);
        $this->EnableAction('Mode');
        $this->RegisterVariableInteger('TargetHumidity', $this->Translate('Target humidity'), 'VENTA.Target', 11);
        $this->EnableAction('TargetHumidity');
        $this->MaintainVariable('LED', $this->Translate('LED strip'), VARIABLETYPE_BOOLEAN, '~Switch', 12, $this->ReadAttributeBoolean('HasLED'));
        if ($this->ReadAttributeBoolean('HasLED')) {
            $this->EnableAction('LED');
        }
        $new = @$this->GetIDForIdent('Automatic') === false;
        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automation'), '~Switch', 13);
        $this->EnableAction('Automatic');
        if ($new) {
            $this->SetValue('Automatic', true);
        }
        $this->RegisterVariableString('Reason', $this->Translate('Automation reason'), '', 14);
        $this->RegisterVariableFloat('RoomHumidity', $this->Translate('Room humidity'), 'VENTA.Humidity', 20);
        $this->RegisterVariableFloat('DeviceHumidity', $this->Translate('Humidity at the device'), 'VENTA.Humidity', 21);
        $this->RegisterVariableFloat('Temperature', $this->Translate('Temperature'), '~Temperature', 22);
        $this->MaintainWaterVariables();
        $this->RegisterVariableBoolean('WaterShortage', $this->Translate('Water shortage'), '~Alert', 25);
        $this->RegisterVariableInteger('FanSpeed', $this->Translate('Fan level'), '', 26);
        $this->RegisterVariableInteger('FanRpm', $this->Translate('Fan speed'), '', 27);
        $this->RegisterVariableInteger('Warnings', $this->Translate('Warning code'), '', 28);
        $this->RegisterVariableInteger('LastResponse', $this->Translate('Last response'), '~UnixTimestamp', 29);
        $this->RegisterVariableInteger('DiscChanged', $this->Translate('Hygiene disc changed'), '~UnixTimestampDate', 30);
        $this->RegisterVariableInteger('DiscRemaining', $this->Translate('Hygiene disc remaining'), 'VENTA.Days', 31);
        $this->RegisterVariableInteger('Cleaned', $this->Translate('Last cleaning'), '~UnixTimestampDate', 32);
        $this->RegisterVariableInteger('CleaningRemaining', $this->Translate('Cleaning due in'), 'VENTA.Days', 33);
    }

    private function MaintainWaterVariables(): void
    {
        $protocol = $this->ReadAttributeInteger('DetectedProtocol');
        $this->MaintainVariable('WaterLevel', $this->Translate('Water level'), VARIABLETYPE_FLOAT, 'VENTA.Liter', 23, $protocol !== self::PROTOCOL_V0);
        $this->MaintainVariable('WaterStage', $this->Translate('Water level (stage)'), VARIABLETYPE_INTEGER, '', 24, $protocol === self::PROTOCOL_V0);
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false || $id === 0) {
            return;
        }
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->TileData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function TileData(): array
    {
        $get = function (string $ident)
        {
            $id = @$this->GetIDForIdent($ident);
            return $id === false || $id === 0 ? null : GetValue($id);
        };
        $translations = [];
        foreach (['Off', 'Auto', 'Sleep', 'Target', 'Automation', 'LED', 'Temperature', 'Humidity at the device', 'Fan', 'Level',
            'Water', 'refill', 'Hygiene disc', 'Cleaning', 'days', 'due', 'Cleaned', 'Disc changed', 'Sure?', 'Room humidity',
            'Online', 'Offline', 'Updated', 'last reached', 'never', 'Stage'] as $text) {
            $translations[$text] = $this->Translate($text);
        }
        return [
            'online'    => (bool) $get('Online'), 'status' => (string) $get('Status'), 'mode' => (int) $get('Mode'),
            'target'    => (int) $get('TargetHumidity'), 'auto' => (bool) $get('Automatic'), 'led' => $get('LED'),
            'room'      => $get('RoomHumidity'), 'device' => $get('DeviceHumidity'), 'temperature' => $get('Temperature'),
            'water'     => $get('WaterLevel'), 'waterStage' => $get('WaterStage'), 'tank' => $this->ReadPropertyFloat('TankVolume'),
            'shortage'  => (bool) $get('WaterShortage'), 'fan' => $get('FanSpeed'),
            'disc'      => (int) $get('DiscRemaining'), 'discInterval' => $this->ReadPropertyInteger('DiscInterval'),
            'cleaning'  => (int) $get('CleaningRemaining'), 'cleaningInterval' => $this->ReadPropertyInteger('CleaningInterval'),
            'last'      => (int) $get('LastResponse'), 'reason' => (string) $get('Reason'),
            'theme'     => $this->ReadPropertyInteger('TileTheme'), 't' => $translations
        ];
    }
}
