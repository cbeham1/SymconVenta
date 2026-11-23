<?php

declare(strict_types=1);

/**
 * Generic Dehumidifier – any dehumidifier that is switched by a variable (smart plug, relay, KNX actuator …).
 * Optional: power measurement (running / tank full detection) and a humidity sensor. Controlled by Venta Automation:
 * on above the target humidity, off below target − hysteresis; by default not during the heating season.
 */
class GenericDehumidifier extends IPSModule
{
    private const STATUS_NO_SWITCH = 201;
    private const MANUAL_OVERRIDE = 14400;
    private const COMMAND_PAUSE = 300;
    private const TANK_DELAY = 600;
    private const HYSTERESIS = 5.0;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SwitchVariable', 0);
        $this->RegisterPropertyInteger('HumidityVariable', 0);
        $this->RegisterPropertyInteger('PowerVariable', 0);
        $this->RegisterPropertyFloat('PowerThreshold', 20.0);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyInteger('NotifyVisualization', 0);
        $this->RegisterPropertyInteger('NotifyScript', 0);
        $this->RegisterPropertyBoolean('NotifyTankFull', true);

        $this->RegisterAttributeInteger('ManualUntil', 0);
        $this->RegisterAttributeInteger('LastCommand', 0);
        $this->RegisterAttributeInteger('LowPowerSince', 0);
        $this->RegisterAttributeString('Notified', '{}');

        $this->RegisterTimer('Update', 0, 'VENTAD_Update($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Power', $this->Translate('Power'), '~Switch', 1);
        $this->EnableAction('Power');
        $this->RegisterVariableString('Status', $this->Translate('Status'), '', 2);
        $new = @$this->GetIDForIdent('Automatic') === false;
        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automation'), '~Switch', 3);
        $this->EnableAction('Automatic');
        if ($new) {
            $this->SetValue('Automatic', true);
        }
        $this->RegisterVariableString('Reason', $this->Translate('Automation reason'), '', 4);
        $this->RegisterVariableFloat('Humidity', $this->Translate('Room humidity'), '~Humidity.F', 10);
        $this->RegisterVariableInteger('Target', $this->Translate('Target humidity'), '', 11);
        $this->RegisterVariableBoolean('TankFull', $this->Translate('Tank full'), '~Alert', 12);

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        foreach (['SwitchVariable', 'HumidityVariable', 'PowerVariable'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $switch = $this->ReadPropertyInteger('SwitchVariable');
        if ($switch <= 0 || !IPS_VariableExists($switch)) {
            $this->SetStatus(self::STATUS_NO_SWITCH);
            $this->SetTimerInterval('Update', 0);
        } else {
            $this->SetStatus(IS_ACTIVE);
            $this->SetTimerInterval('Update', max(30, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        }
        $this->Update();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->Update();
        }
    }

    public function GetVisualizationTile(): string
    {
        $json = json_encode($this->TileData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return str_replace('/*__DATA__*/null', $json, (string) file_get_contents(__DIR__ . '/tile.html'));
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'Automatic':
                $this->SetValue('Automatic', (bool) $Value);
                if ($Value) {
                    $this->WriteAttributeInteger('ManualUntil', 0);
                }
                $this->UpdateTile();
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    /** Reads switch, humidity and power; detects a full tank (switched on, but no power for 10 minutes). */
    public function Update(): bool
    {
        $switch = $this->ReadPropertyInteger('SwitchVariable');
        $on = $switch > 0 && IPS_VariableExists($switch) && (bool) GetValue($switch);
        $this->SetValueIfChanged('Power', $on);
        $humidityId = $this->ReadPropertyInteger('HumidityVariable');
        if ($humidityId > 0 && IPS_VariableExists($humidityId)) {
            $this->SetValueIfChanged('Humidity', round((float) GetValue($humidityId), 1));
        }
        $powerId = $this->ReadPropertyInteger('PowerVariable');
        $tankFull = false;
        if ($on && $powerId > 0 && IPS_VariableExists($powerId) && (float) GetValue($powerId) < $this->ReadPropertyFloat('PowerThreshold')) {
            if ($this->ReadAttributeInteger('LowPowerSince') === 0) {
                $this->WriteAttributeInteger('LowPowerSince', time());
            }
            $tankFull = time() - $this->ReadAttributeInteger('LowPowerSince') >= self::TANK_DELAY;
        } else {
            $this->WriteAttributeInteger('LowPowerSince', 0);
        }
        $this->SetValueIfChanged('TankFull', $tankFull);
        if ($tankFull && $this->ReadPropertyBoolean('NotifyTankFull')) {
            $this->Notify('tank', $this->Translate('Tank full or device in standby – please empty'));
        } elseif (!$tankFull) {
            $this->ClearNotification('tank');
        }
        $this->SetValueIfChanged('Status', $this->Translate($tankFull ? 'Tank full' : ($on ? 'Running' : 'Off')));
        $this->UpdateTile();
        return true;
    }

    public function SetPower(bool $On): bool
    {
        $this->WriteAttributeInteger('ManualUntil', time() + self::MANUAL_OVERRIDE);
        return $this->Switch($On);
    }

    /** Called by Venta Automation. $State: season (bool), dehumidify (bool: allowed), target (int), reason (string). */
    public function ApplyAutomation(string $State): string
    {
        $s = json_decode($State, true);
        if (!is_array($s)) {
            return '';
        }
        $target = (int) ($s['target'] ?? 60);
        $this->SetValueIfChanged('Target', $target);
        $reason = $this->Decide($s, $target);
        $this->SetValueIfChanged('Reason', $reason);
        $this->UpdateTile();
        return $reason;
    }

    public function GetState(): string
    {
        return json_encode(['online' => $this->GetStatus() === IS_ACTIVE, 'on' => $this->GetValue('Power'), 'humidity' => $this->GetValue('Humidity'),
            'target'                 => $this->GetValue('Target'), 'tankFull' => $this->GetValue('TankFull')]);
    }

    private function Decide(array $s, int $target): string
    {
        if ((int) ($s['mode'] ?? 0) < 0) {
            return $this->Translate('Automation off – device is not switched');
        }
        if (!$this->GetValue('Automatic')) {
            return $this->Translate('Automation off for this device');
        }
        if ($this->ReadAttributeInteger('ManualUntil') > time()) {
            return sprintf($this->Translate('Operated manually – automation again at %s'), date('H:i', $this->ReadAttributeInteger('ManualUntil')));
        }
        $humidity = $this->GetValue('Humidity');
        $on = $this->GetValue('Power');
        if (empty($s['dehumidify'])) {
            $want = false;
            $reason = (string) ($s['reason'] ?? $this->Translate('Not allowed now'));
        } elseif ($this->ReadPropertyInteger('HumidityVariable') <= 0) {
            return $this->Translate('No humidity sensor configured');
        } else {
            $want = $on ? $humidity > $target - self::HYSTERESIS : $humidity > $target;
            $reason = sprintf($this->Translate($want ? 'Room humidity %.0f %% above %d %%' : 'Room humidity %.0f %% ok (target %d %%)'), $humidity, $target);
        }
        if ($want !== $on && time() - $this->ReadAttributeInteger('LastCommand') >= self::COMMAND_PAUSE) {
            $this->WriteAttributeInteger('LastCommand', time());
            $this->Switch($want);
        }
        return $reason;
    }

    private function Switch(bool $on): bool
    {
        $switch = $this->ReadPropertyInteger('SwitchVariable');
        if ($switch <= 0 || !IPS_VariableExists($switch)) {
            return false;
        }
        $ok = @RequestAction($switch, $on);
        $this->SendDebug('Switch', ($on ? 'on' : 'off') . ' -> ' . ($ok === false ? 'FAILED' : 'OK'), 0);
        IPS_Sleep(500);
        $this->Update();
        return $ok !== false;
    }

    private function Notify(string $key, string $text): void
    {
        $notified = json_decode($this->ReadAttributeString('Notified'), true) ?: [];
        if (isset($notified[$key])) {
            return;
        }
        $title = IPS_GetName($this->InstanceID);
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

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && $id !== 0 && ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0)) {
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
        foreach (['On', 'Off', 'Automation', 'Room humidity', 'Target', 'Tank full', 'Running', 'Dehumidifier', 'not configured'] as $text) {
            $translations[$text] = $this->Translate($text);
        }
        return [
            'on'          => (bool) $get('Power'), 'auto' => (bool) $get('Automatic'), 'humidity' => $get('Humidity'),
            'hasHumidity' => $this->ReadPropertyInteger('HumidityVariable') > 0,
            'target'      => (int) $get('Target'), 'tankFull' => (bool) $get('TankFull'), 'status' => (string) $get('Status'),
            'reason'      => (string) $get('Reason'), 'theme' => $this->ReadPropertyInteger('TileTheme'), 't' => $translations
        ];
    }
}
