<?php

declare(strict_types=1);

/**
 * Hauskachel – IP-Symcon-Modul: das ganze Haus als Kachel (Etagen, Räume, Treppenhaus, Außenbereiche)
 *
 * Die Struktur kommt aus dem Objektbaum: Unter einer Hauskategorie liegen die Bereiche (Etagen, Keller,
 * Treppenhaus, Garten, Garage, Carport …), unter den Etagen die Räume. In jedem Raum erkennt das Modul
 * Temperatur, Luftfeuchte, Solltemperatur, Licht, Fenster/Türen und Präsenz an Namen, Profilen und Symbolen.
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */
class Hauskachel extends IPSModuleStrict
{
    // Art eines Bereichs unter der Hauskategorie
    private const KIND_FLOOR = 0;     // Etage mit Räumen
    private const KIND_BASEMENT = 1;  // Keller (immer unten)
    private const KIND_AXIS = 2;      // durchgehend, z. B. Treppenhaus
    private const KIND_OUTSIDE = 3;   // Außenbereich, z. B. Garten
    private const KIND_BUILDING = 4;  // Nebengebäude, z. B. Garage, Carport
    private const KIND_HIDDEN = 5;    // nicht anzeigen

    // Funktionen in einem Raum: t Temperatur, h Feuchte, s Soll, l Licht, w Fenster/Tür, p Präsenz, x ignorieren
    private const FUNCTIONS = ['t', 'h', 's', 'l', 'w', 'p'];
    private const MULTI = ['l', 'w', 'p'];

    private const STATUS_NO_ROOT = 201;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('RootCategory', 0);
        $this->RegisterPropertyString('Title', '');
        $this->RegisterPropertyString('Areas', '[]');
        $this->RegisterPropertyString('MeasureNames', 'Messwerte');
        $this->RegisterPropertyString('Overrides', '[]');
        $this->RegisterPropertyBoolean('AutoRebuild', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyFloat('SetpointStep', 0.5);
        $this->RegisterPropertyBoolean('ShowSummary', true);

        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeString('Index', '{}');
        $this->RegisterAttributeString('Watched', '{"vars":[],"objs":[]}');

        $this->RegisterTimer('Rebuild', 0, 'HAUS_Rebuild($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->MaintainVariable('LightsOn', $this->Translate('Lights on'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'lightbulb',
        ], 10, true);
        $this->MaintainVariable('WindowsOpen', $this->Translate('Windows open'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'window-frame-open',
        ], 20, true);
        $this->MaintainVariable('AverageTemperature', $this->Translate('Average temperature'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'temperature-half',
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1,
        ], 30, true);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Build();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->Build();
                break;
            case VM_UPDATE:
                $this->VariableChanged($SenderID);
                break;
            default:
                // Struktur geändert (Raum angelegt, entfernt oder umbenannt): kurz warten, dann neu einlesen
                if ($this->ReadPropertyBoolean('AutoRebuild')) {
                    $this->SetTimerInterval('Rebuild', 2000);
                }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Light':
                $this->ToggleLight((string) $Value);
                break;
            case 'Setpoint':
                $data = json_decode((string) $Value, true);
                if (is_array($data)) {
                    $this->ChangeSetpoint((string) ($data['k'] ?? ''), (int) ($data['d'] ?? 0));
                }
                break;
            case 'Rebuild':
                $this->Build();
                break;
            default:
                $this->SendDebug('RequestAction', 'Unbekannte Aktion: ' . $Ident, 0);
        }
    }

    /**
     * Struktur neu einlesen und die Kachel komplett neu aufbauen.
     */
    public function Rebuild(): bool
    {
        return $this->Build();
    }

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        if (!isset($data['floors']) && !isset($data['error'])) {
            // Noch nie aufgebaut (z. B. Kachel vor dem ersten Übernehmen geöffnet): jetzt einlesen
            $this->Build();
            $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        }
        $data['theme'] = $this->ReadPropertyInteger('TileTheme');
        // JSON_HEX_* verhindert, dass Namen wie "</script>" das Skript der Kachel beenden
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $areas = [];
        $detected = [];
        $root = $this->ReadPropertyInteger('RootCategory');
        if ($root > 0 && IPS_CategoryExists($root)) {
            foreach ($this->ResolvedAreas($root) as $a) {
                $areas[] = ['CategoryID' => $a['id'], 'Name' => $a['name'], 'Kind' => $a['kind'], 'Level' => $a['level']];
            }
            $index = json_decode($this->ReadAttributeString('Index'), true) ?: [];
            $labels = [
                't' => $this->Translate('Temperature'), 'h' => $this->Translate('Humidity'),
                's' => $this->Translate('Setpoint'), 'l' => $this->Translate('Light'),
                'w' => $this->Translate('Window / door'), 'p' => $this->Translate('Presence'),
            ];
            foreach ($index['rooms'] ?? [] as $room) {
                foreach (self::FUNCTIONS as $f) {
                    foreach ((array) ($room['ids'][$f] ?? []) as $vid) {
                        if (IPS_VariableExists((int) $vid)) {
                            $detected[] = [
                                'Room'     => $room['label'],
                                'Function' => $labels[$f],
                                'Variable' => IPS_GetLocation((int) $vid),
                            ];
                        }
                    }
                }
            }
        }
        $this->FillForm($form['elements'], $areas, $detected);
        return (string) json_encode($form);
    }

    // ===================================================================================================
    // Aufbau
    // ===================================================================================================

    private function Build(): bool
    {
        $this->SetTimerInterval('Rebuild', 0);
        $this->Unwatch();

        $root = $this->ReadPropertyInteger('RootCategory');
        if ($root <= 0 || !IPS_CategoryExists($root)) {
            $this->SetStatus($root <= 0 ? IS_INACTIVE : self::STATUS_NO_ROOT);
            $this->WriteAttributeString('Index', '{}');
            $this->StoreTile(['error' => $this->Translate('Please choose the house category in the instance settings.'), 'rooms' => new stdClass()]);
            return false;
        }
        $this->RegisterReference($root);

        $overrides = $this->Overrides();
        $rooms = [];
        $index = [];
        $floors = [];
        $axis = [];
        $outside = [];
        $objs = [$root];

        $areas = $this->ResolvedAreas($root);
        // Etagen von oben nach unten, Keller immer zuletzt
        usort($areas, static function (array $a, array $b): int {
            $ka = $a['kind'] === self::KIND_BASEMENT ? 1 : 0;
            $kb = $b['kind'] === self::KIND_BASEMENT ? 1 : 0;
            return [$ka, -$a['level'], $a['pos']] <=> [$kb, -$b['level'], $b['pos']];
        });

        foreach ($areas as $area) {
            $id = $area['id'];
            $objs[] = $id;
            switch ($area['kind']) {
                case self::KIND_FLOOR:
                case self::KIND_BASEMENT:
                    $floor = ['n' => $area['name'], 'b' => $area['kind'] === self::KIND_BASEMENT, 'm' => null, 'r' => []];
                    $loose = [];
                    foreach ($this->Children($id) as $child) {
                        $obj = IPS_GetObject($child);
                        if ($obj['ObjectType'] === OBJECTTYPE_CATEGORY && !$this->IsMeasure($obj['ObjectName'])) {
                            $objs[] = $child;
                            $key = 'r' . $child;
                            // Kellerräume zählen nicht zur Durchschnittstemperatur (Flag o wie außen)
                            [$rooms[$key], $index[$key]] = $this->BuildRoom($child, $obj['ObjectName'], $area['name'], $overrides, $area['kind'] === self::KIND_BASEMENT);
                            $floor['r'][] = $key;
                        } elseif ($obj['ObjectType'] === OBJECTTYPE_CATEGORY) {
                            $objs[] = $child;
                            $loose = array_merge($loose, $this->Collect($child, true));
                        } else {
                            $loose = array_merge($loose, $this->CollectObject($child, ''));
                        }
                    }
                    // Messwerte der Etage (Kategorie „Messwerte“ und Variablen direkt in der Etage)
                    if ($loose !== []) {
                        $key = 'm' . $id;
                        [$rooms[$key], $index[$key]] = $this->BuildRoomFrom($id, $loose, $area['name'], $area['name'], $overrides, false);
                        $floor['m'] = $key;
                    }
                    $floors[] = $floor;
                    break;
                case self::KIND_AXIS:
                    $key = 'r' . $id;
                    [$rooms[$key], $index[$key]] = $this->BuildRoom($id, $area['name'], '', $overrides, false);
                    $axis[] = $key;
                    break;
                case self::KIND_OUTSIDE:
                case self::KIND_BUILDING:
                    $key = 'r' . $id;
                    [$rooms[$key], $index[$key]] = $this->BuildRoom($id, $area['name'], '', $overrides, true);
                    $rooms[$key]['i'] = self::IconFor($area['name'], $area['kind']);
                    $outside[] = $key;
                    break;
            }
        }

        // Rückwärtsverzeichnis: Variable → Räume
        $vars = [];
        foreach ($index as $key => $entry) {
            foreach (self::FUNCTIONS as $f) {
                foreach ((array) ($entry['ids'][$f] ?? []) as $vid) {
                    $vars[(int) $vid][] = $key;
                }
            }
        }
        foreach (array_keys($vars) as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        if ($this->ReadPropertyBoolean('AutoRebuild')) {
            foreach (array_unique($objs) as $oid) {
                $this->RegisterMessage($oid, OM_CHILDADDED);
                $this->RegisterMessage($oid, OM_CHILDREMOVED);
            }
            foreach (array_keys($rooms) as $key) {
                if ($key[0] === 'r') {
                    $this->RegisterMessage((int) substr($key, 1), OM_CHANGENAME);
                }
            }
        }
        $this->WriteAttributeString('Watched', (string) json_encode(['vars' => array_keys($vars), 'objs' => array_values(array_unique($objs))]));
        $this->WriteAttributeString('Index', (string) json_encode(['rooms' => $index, 'vars' => $vars]));

        $title = trim($this->ReadPropertyString('Title'));
        $this->StoreTile([
            'title'   => $title !== '' ? $title : IPS_GetName($root),
            'summary' => $this->ReadPropertyBoolean('ShowSummary'),
            'step'    => $this->Step(),
            'floors'  => $floors,
            'axis'    => $axis,
            'out'     => $outside,
            'rooms'   => $rooms === [] ? new stdClass() : $rooms,
            'error'   => '',
        ]);
        $this->UpdateSummary($rooms);
        $this->SetStatus(IS_ACTIVE);
        $this->SendDebug('Build', count($floors) . ' Etagen, ' . count($rooms) . ' Bereiche/Räume, ' . count($vars) . ' Variablen', 0);
        return true;
    }

    /**
     * Bereiche unter der Hauskategorie mit Art und Ebene – gespeicherte Einstellungen vor Namensregeln.
     */
    private function ResolvedAreas(int $root): array
    {
        $saved = [];
        foreach (json_decode($this->ReadPropertyString('Areas'), true) ?: [] as $row) {
            if (isset($row['CategoryID'])) {
                $saved[(int) $row['CategoryID']] = $row;
            }
        }
        $list = [];
        $pos = 0;
        foreach ($this->Children($root) as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] !== OBJECTTYPE_CATEGORY) {
                continue;
            }
            $name = $obj['ObjectName'];
            [$kind, $level] = $this->GuessArea($name, $pos);
            if (isset($saved[$child])) {
                $kind = max(0, min(self::KIND_HIDDEN, (int) ($saved[$child]['Kind'] ?? $kind)));
                $level = (int) ($saved[$child]['Level'] ?? $level);
            }
            if ($kind !== self::KIND_HIDDEN) {
                $list[] = ['id' => $child, 'name' => $name, 'kind' => $kind, 'level' => $level, 'pos' => $pos];
            } else {
                $list[] = ['id' => $child, 'name' => $name, 'kind' => $kind, 'level' => $level, 'pos' => $pos, 'hidden' => true];
            }
            $pos++;
        }
        return $list;
    }

    private function GuessArea(string $name, int $pos): array
    {
        $n = mb_strtolower($name);
        if ($this->IsMeasure($name)) {
            return [self::KIND_HIDDEN, 0];
        }
        if (preg_match('/keller|untergeschoss|\bug\b|souterrain|basement|cellar/u', $n)) {
            return [self::KIND_BASEMENT, -1];
        }
        if (preg_match('/treppe|aufgang|stair/u', $n)) {
            return [self::KIND_AXIS, 0];
        }
        if (preg_match('/garage|carport|schuppen|gartenhaus|nebengebäude|stellplatz|shed/u', $n)) {
            return [self::KIND_BUILDING, 0];
        }
        if (preg_match('/garten|hof|außen|aussen|einfahrt|terrasse|balkon|garden|outdoor|yard/u', $n)) {
            return [self::KIND_OUTSIDE, 0];
        }
        if (preg_match('/^(eg|erdgeschoss|ground)/u', $n)) {
            return [self::KIND_FLOOR, 0];
        }
        if (preg_match('/(\d+)\s*\.?\s*(og|obergeschoss|stock|etage|floor)/u', $n, $m)) {
            return [self::KIND_FLOOR, (int) $m[1]];
        }
        if (preg_match('/dg|dachgeschoss|dachboden|spitzboden|attic/u', $n)) {
            return [self::KIND_FLOOR, 9];
        }
        if (preg_match('/og|obergeschoss/u', $n)) {
            return [self::KIND_FLOOR, 1];
        }
        return [self::KIND_FLOOR, 5 - $pos];
    }

    private function IsMeasure(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        foreach (explode(',', mb_strtolower($this->ReadPropertyString('MeasureNames'))) as $m) {
            if (trim($m) !== '' && $n === trim($m)) {
                return true;
            }
        }
        return false;
    }

    private function BuildRoom(int $catId, string $name, string $floor, array $overrides, bool $outdoor): array
    {
        return $this->BuildRoomFrom($catId, $this->Collect($catId, true), $name, $floor, $overrides, $outdoor);
    }

    /**
     * Ordnet die gefundenen Variablen eines Raums den Funktionen zu und liest die Werte.
     *
     * @param array<int, array{0:int,1:string}> $found Variable und Zusammenhang (Name der Instanz bzw. des Links)
     */
    private function BuildRoomFrom(int $catId, array $found, string $name, string $floor, array $overrides, bool $outdoor): array
    {
        $ids = ['t' => 0, 'h' => 0, 's' => 0, 'l' => [], 'w' => [], 'p' => []];
        $own = $overrides[$catId] ?? [];
        $ignored = array_flip($own['x'] ?? []);
        $seen = [];
        foreach ($found as [$vid, $context]) {
            if (isset($seen[$vid]) || isset($ignored[$vid])) {
                continue;
            }
            $seen[$vid] = true;
            $f = $this->Classify($vid, $context);
            if ($f === null) {
                continue;
            }
            if (in_array($f, self::MULTI, true)) {
                $ids[$f][] = $vid;
            } elseif ($ids[$f] === 0) {
                $ids[$f] = $vid;
            }
        }
        foreach (self::FUNCTIONS as $f) {
            foreach ($own[$f] ?? [] as $vid) {
                if (in_array($f, self::MULTI, true)) {
                    if (!in_array($vid, $ids[$f], true)) {
                        $ids[$f][] = $vid;
                    }
                } else {
                    $ids[$f] = $vid;
                }
            }
        }
        $label = $floor !== '' && $floor !== $name ? $floor . ' · ' . $name : $name;
        $entry = ['ids' => $ids, 'label' => $label];
        $room = ['n' => $name, 'o' => $outdoor || (bool) preg_match('/terrasse|balkon|garten|loggia/u', mb_strtolower($name))]
            + $this->RoomValues($ids);
        return [$room, $entry];
    }

    private function RoomValues(array $ids): array
    {
        $num = static function (int $vid, int $digits): ?float {
            return $vid > 0 && IPS_VariableExists($vid) ? round((float) GetValue($vid), $digits) : null;
        };
        $s = (int) $ids['s'];
        $values = [
            't'  => $num((int) $ids['t'], 1),
            'h'  => $num((int) $ids['h'], 0),
            's'  => $num($s, 1),
            'sa' => $s > 0 && IPS_VariableExists($s) && HasAction($s),
            'l'  => null,
            'la' => false,
            'w'  => null,
            'p'  => null,
        ];
        $lights = array_values(array_filter($ids['l'], 'IPS_VariableExists'));
        if ($lights !== []) {
            $values['l'] = count(array_filter($lights, static fn (int $v): bool => (bool) GetValue($v)));
            $values['ln'] = count($lights);
            $values['la'] = count(array_filter($lights, 'HasAction')) > 0;
        }
        $windows = array_values(array_filter($ids['w'], 'IPS_VariableExists'));
        if ($windows !== []) {
            $values['w'] = count(array_filter($windows, fn (int $v): bool => $this->IsOpen($v)));
            $values['wn'] = count($windows);
        }
        $presence = array_values(array_filter($ids['p'], 'IPS_VariableExists'));
        if ($presence !== []) {
            $values['p'] = count(array_filter($presence, static fn (int $v): bool => (float) GetValue($v) > 0)) > 0;
        }
        return $values;
    }

    private function IsOpen(int $vid): bool
    {
        $value = GetValue($vid);
        if (is_string($value)) {
            // Text wie bei der HCU: „Geschlossen“, „Offen“, „Gekippt“
            $v = mb_strtolower($value);
            if (preg_match('/geschlossen|closed|\bzu\b/u', $v)) {
                return false;
            }
            return (bool) preg_match('/offen|open|gekippt|tilt/u', $v);
        }
        if (is_bool($value)) {
            $var = IPS_GetVariable($vid);
            $profile = mb_strtolower($var['VariableCustomProfile'] ?: $var['VariableProfile']);
            // ~Window.Reversed u. Ä.: true = geschlossen
            return str_contains($profile, 'reversed') ? !$value : $value;
        }
        // Homematic IP und andere: 0 = zu, 1 = offen, 2 = gekippt
        return (float) $value > 0;
    }

    // ===================================================================================================
    // Variablen finden und erkennen
    // ===================================================================================================

    /**
     * Alle Variablen unterhalb einer Kategorie, mit dem Namen der Instanz oder des Links als Zusammenhang.
     */
    private function Collect(int $catId, bool $recursive, int $depth = 0): array
    {
        $found = [];
        if ($depth > 4) {
            return $found;
        }
        foreach ($this->Children($catId) as $child) {
            if (IPS_GetObject($child)['ObjectType'] === OBJECTTYPE_CATEGORY) {
                if ($recursive) {
                    $found = array_merge($found, $this->Collect($child, true, $depth + 1));
                }
                continue;
            }
            $found = array_merge($found, $this->CollectObject($child, '', $depth));
        }
        return $found;
    }

    private function CollectObject(int $id, string $context, int $depth = 0): array
    {
        if ($depth > 4 || !IPS_ObjectExists($id)) {
            return [];
        }
        $obj = IPS_GetObject($id);
        switch ($obj['ObjectType']) {
            case OBJECTTYPE_VARIABLE:
                return [[$id, trim($context)]];
            case OBJECTTYPE_INSTANCE:
                // Variablen und Links in der Instanz (z. B. Dummy-Instanz „Heizkörper“ mit Links auf die HCU)
                $found = [];
                foreach ($this->Children($id) as $child) {
                    $found = array_merge($found, $this->CollectObject($child, $context . ' ' . $obj['ObjectName'], $depth + 1));
                }
                return $found;
            case OBJECTTYPE_LINK:
                $target = IPS_GetLink($id)['TargetID'];
                if ($target <= 0 || !IPS_ObjectExists($target)) {
                    return [];
                }
                if (IPS_GetObject($target)['ObjectType'] === OBJECTTYPE_CATEGORY) {
                    return $this->Collect($target, true, $depth + 1);
                }
                return $this->CollectObject($target, $context . ' ' . $obj['ObjectName'], $depth + 1);
        }
        return [];
    }

    /**
     * Erkennt die Funktion einer Variable an Name, Ident, Profil, Symbol und Zusammenhang.
     */
    private function Classify(int $vid, string $context): ?string
    {
        $var = IPS_GetVariable($vid);
        $obj = IPS_GetObject($vid);
        $type = $var['VariableType'];
        $hints = [$context, $obj['ObjectName'], $obj['ObjectIdent'], $var['VariableCustomProfile'], $var['VariableProfile']];
        foreach ([$var['VariablePresentation'] ?? [], $var['VariableCustomPresentation'] ?? []] as $p) {
            if (is_array($p)) {
                $hints[] = (string) ($p['ICON'] ?? '');
                $hints[] = (string) ($p['SUFFIX'] ?? '');
            }
        }
        $text = mb_strtolower(implode(' ', $hints));
        $numeric = $type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT;
        $name = mb_strtolower($obj['ObjectName'] . ' ' . $obj['ObjectIdent']);

        // Technische Nebenwerte nie als Raumwert nehmen
        if (preg_match('/batter|rssi|unreach|signal|duty|low_?bat|sabotage|error|fehler|config|firmware|update|voltage|spannung|leistung|power|energ|verbrauch|zähler|counter/u', $name)) {
            return null;
        }
        if (preg_match('/fenster|window|tür|tuer|door|kontakt|contact|shutter|öffnung|oeffnung/u', $text)
            && !preg_match('/schloss|lock|riegel/u', $text) && $type !== VARIABLETYPE_FLOAT) {
            return 'w';
        }
        if (preg_match('/präsenz|praesenz|presence|bewegung|motion|anwesen|occupan|person/u', $text)
            && ($type === VARIABLETYPE_BOOLEAN || $type === VARIABLETYPE_INTEGER)) {
            return 'p';
        }
        if ($numeric && preg_match('/feuchte|humidity/u', $text)) {
            return 'h';
        }
        if ($type === VARIABLETYPE_FLOAT && preg_match('/soll|setpoint|set_point|target|solltemp/u', $text)) {
            return 's';
        }
        if ($type === VARIABLETYPE_FLOAT && preg_match('/temperatur|temperature|°c|temp\b|thermometer/u', $text)) {
            return 't';
        }
        if ($type === VARIABLETYPE_BOOLEAN && preg_match('/licht|light|lampe|lamp|leuchte|beleuchtung|spot|deko|lightbulb/u', $text)) {
            return 'l';
        }
        return null;
    }

    /**
     * Manuelle Zuordnungen: Raum-Kategorie → Funktion → Variablen (Funktion x = ignorieren).
     */
    private function Overrides(): array
    {
        $map = [];
        foreach (json_decode($this->ReadPropertyString('Overrides'), true) ?: [] as $row) {
            $room = (int) ($row['Room'] ?? 0);
            $f = (string) ($row['Function'] ?? '');
            $vid = (int) ($row['Variable'] ?? 0);
            if ($room > 0 && $vid > 0 && IPS_VariableExists($vid) && (in_array($f, self::FUNCTIONS, true) || $f === 'x')) {
                $map[$room][$f][] = $vid;
            }
        }
        return $map;
    }

    // ===================================================================================================
    // Laufender Betrieb
    // ===================================================================================================

    private function VariableChanged(int $vid): void
    {
        $index = json_decode($this->ReadAttributeString('Index'), true) ?: [];
        $keys = $index['vars'][(string) $vid] ?? [];
        if ($keys === []) {
            return;
        }
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $changed = [];
        foreach ($keys as $key) {
            if (!isset($index['rooms'][$key], $data['rooms'][$key])) {
                continue;
            }
            $values = $this->RoomValues($index['rooms'][$key]['ids']);
            $room = array_merge($data['rooms'][$key], $values);
            // Beide Seiten gleich durch JSON schicken, damit 22 und 22.0 nicht als Änderung gelten
            $norm = static fn (array $r): string => (string) json_encode(json_decode((string) json_encode($r), true));
            if ($norm($room) !== $norm($data['rooms'][$key])) {
                $data['rooms'][$key] = $room;
                $changed[$key] = $room;
            }
        }
        if ($changed === []) {
            return;
        }
        $this->WriteAttributeString('TileData', (string) json_encode($data));
        // Nur die geänderten Räume schicken – die Kachel rechnet die Zusammenfassung selbst
        $this->UpdateVisualizationValue((string) json_encode(['rooms' => $changed], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->UpdateSummary($data['rooms']);
    }

    private function ToggleLight(string $key): void
    {
        $ids = $this->RoomIds($key);
        if ($ids === null) {
            return;
        }
        $lights = array_values(array_filter($ids['l'], static fn (int $v): bool => IPS_VariableExists($v) && HasAction($v)));
        if ($lights === []) {
            return;
        }
        $anyOn = count(array_filter($lights, static fn (int $v): bool => (bool) GetValue($v))) > 0;
        foreach ($lights as $vid) {
            if ((bool) GetValue($vid) === $anyOn) {
                RequestAction($vid, !$anyOn);
            }
        }
    }

    private function ChangeSetpoint(string $key, int $direction): void
    {
        $ids = $this->RoomIds($key);
        $sid = (int) ($ids['s'] ?? 0);
        if ($ids === null || $direction === 0 || $sid <= 0 || !IPS_VariableExists($sid) || !HasAction($sid)) {
            return;
        }
        $step = $this->Step();
        $value = (float) GetValue($sid) + ($direction > 0 ? $step : -$step);
        $value = round(max(5.0, min(30.0, $value)) / $step) * $step;
        RequestAction($sid, round($value, 1));
    }

    /**
     * Nur Räume, die das Modul selbst kennt – die Kachel schickt nie Variablen-IDs.
     */
    private function RoomIds(string $key): ?array
    {
        if (!preg_match('/^[rm]\d+$/', $key)) {
            return null;
        }
        $index = json_decode($this->ReadAttributeString('Index'), true) ?: [];
        $ids = $index['rooms'][$key]['ids'] ?? null;
        return is_array($ids) ? $ids : null;
    }

    private function UpdateSummary(array $rooms): void
    {
        $lights = 0;
        $windows = 0;
        $temps = [];
        foreach ($rooms as $key => $room) {
            if ($key[0] === 'm') {
                continue;
            }
            $lights += (int) ($room['l'] ?? 0);
            $windows += (int) ($room['w'] ?? 0);
            if (($room['t'] ?? null) !== null && empty($room['o'])) {
                $temps[] = (float) $room['t'];
            }
        }
        $this->SetIfChanged('LightsOn', $lights);
        $this->SetIfChanged('WindowsOpen', $windows);
        $this->SetIfChanged('AverageTemperature', $temps === [] ? 0.0 : round(array_sum($temps) / count($temps), 1));
    }

    private function SetIfChanged(string $ident, mixed $value): void
    {
        if (@$this->GetIDForIdent($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function StoreTile(array $data): void
    {
        // Immer senden: auch ein geändertes Farbschema muss in einer offenen Kachel ankommen
        $this->WriteAttributeString('TileData', (string) json_encode($data));
        $data['theme'] = $this->ReadPropertyInteger('TileTheme');
        $data['full'] = true;
        $this->UpdateVisualizationValue((string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    private function Unwatch(): void
    {
        $watched = json_decode($this->ReadAttributeString('Watched'), true) ?: [];
        foreach ($watched['vars'] ?? [] as $vid) {
            $this->UnregisterMessage((int) $vid, VM_UPDATE);
        }
        foreach ($watched['objs'] ?? [] as $oid) {
            $this->UnregisterMessage((int) $oid, OM_CHILDADDED);
            $this->UnregisterMessage((int) $oid, OM_CHILDREMOVED);
        }
        $index = json_decode($this->ReadAttributeString('Index'), true) ?: [];
        foreach (array_keys($index['rooms'] ?? []) as $key) {
            if ($key[0] === 'r') {
                $this->UnregisterMessage((int) substr($key, 1), OM_CHANGENAME);
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $this->WriteAttributeString('Watched', '{"vars":[],"objs":[]}');
    }

    // ===================================================================================================
    // Hilfen
    // ===================================================================================================

    private function Children(int $id): array
    {
        $children = IPS_GetChildrenIDs($id);
        usort($children, static function (int $a, int $b): int {
            $oa = IPS_GetObject($a);
            $ob = IPS_GetObject($b);
            return [$oa['ObjectPosition'], $oa['ObjectName']] <=> [$ob['ObjectPosition'], $ob['ObjectName']];
        });
        return array_values(array_filter($children, static fn (int $c): bool => !IPS_GetObject($c)['ObjectIsHidden']));
    }

    private function Step(): float
    {
        $step = $this->ReadPropertyFloat('SetpointStep');
        return $step >= 0.1 && $step <= 2.0 ? $step : 0.5;
    }

    private static function IconFor(string $name, int $kind): string
    {
        $n = mb_strtolower($name);
        if (str_contains($n, 'carport')) {
            return 'carport';
        }
        if (str_contains($n, 'garage')) {
            return 'garage';
        }
        if ($kind === self::KIND_BUILDING) {
            return 'building';
        }
        return 'garden';
    }

    private function FillForm(array &$elements, array $areas, array $detected): void
    {
        foreach ($elements as &$el) {
            if (($el['name'] ?? '') === 'Areas') {
                $el['values'] = $areas;
            } elseif (($el['name'] ?? '') === 'Detected') {
                $el['values'] = $detected;
            }
            if (isset($el['items']) && is_array($el['items'])) {
                $this->FillForm($el['items'], $areas, $detected);
            }
        }
    }
}
