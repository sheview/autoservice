<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Models\PmChecklist;

/**
 * The standard PM templates a company starts with (AddStandardChecklists): the customer forms of
 * docs/pm-forms (data center equipment, network switch, PC) and, for the other standard categories,
 * the older standard checklists (StandardChecklists). Each is tied to standard categories by code
 * prefix (Asset\Support\StandardCategories); each company then changes its own copy.
 *
 * Taken from the forms with their mistakes put right: Wiring (not "Wring"), BATTERY, Vdc (not
 * "Vcd"), Subnet Mask (not "Mark"), Connector, the UPS AC output line voltage range 370–410, and the
 * Cisco switch form under a network heading (it was printed under CCTV). Who signs, with phone and
 * e-mail, is never part of a template: it comes from the contract and the users. The wording stays
 * in English as on the customer's forms.
 */
final class StandardTemplates
{
    private const DC = 'datacenter';

    private const NETWORK = 'network';

    private const PC = 'pc';

    /**
     * @return list<array{code: string, name: string, service_line: string|null, layout: string, blocks: array<string, bool>,
     *     prefixes: list<string>, needs: list<string>, items: list<array<string, mixed>>}>
     *     prefixes = categories it is tied to ('' = the default template); needs = categories of which the
     *     company must have one for the template to be added (default: its prefixes)
     */
    public static function all(): array
    {
        $fromForms = [
            self::template('DC-ELEC', 'Electrical PM (MDB / PDU / ESDB / UDB)', self::DC, ['EDB'], self::electrical()),
            self::template('DC-UPS', 'UPS PM', self::DC, ['UPS'], self::ups()),
            self::template('DC-BATT', 'Battery PM', self::DC, ['BAT'], self::battery()),
            self::template('DC-MON', 'Monitoring Packet Power PM', self::DC, ['EMS'], self::monitoring([
                ['temperature', 'Temperature Sensor'], ['humidity', 'Relative Humidity Sensor'], ['leak', 'Leak Sensor'], ['status', 'Status Sensor'],
            ])),
            self::template('DC-MON-EAGLEEYE', 'Monitoring PM – Eagle Eye', self::DC, [], self::monitoring([
                ['temperature', 'Temperature Sensor'], ['led_warning', 'LED warning'],
            ]), needs: ['EMS']),
            self::template('DC-MON-GW04', 'Monitoring PM – Packet Power GW04', self::DC, [], self::monitoring([
                ['temperature', 'Temperature Sensor'], ['humidity', 'Relative Humidity Sensor'], ['leak', 'Leak Sensor'], ['status', 'Status Sensor'],
            ]), needs: ['EMS']),
            self::template('DC-MON-GH04', 'Monitoring PM – Packet Power GH04', self::DC, [], self::monitoring(self::packetPowerSensors()), needs: ['EMS']),
            self::template('DC-MON-CYBERHUB', 'Monitoring PM – STULZ Cyber Hub', self::DC, [], self::monitoring(self::packetPowerSensors()), needs: ['EMS']),
            self::template('DC-PAC', 'Precision Air PM', self::DC, ['PAC'], self::precisionAir()),
            self::template('DC-WLD', 'Water Leak PM', self::DC, ['WLD'], self::waterLeak()),
            self::template('DC-FSS', 'Fire Suppression PM', self::DC, ['FSS'], self::fireSuppression()),
            self::template('DC-HSSD', 'HSSD PM', self::DC, ['SD'], self::hssd()),
            self::template('DC-CCTV', 'CCTV NVR PM', self::NETWORK, ['CCTV'], self::cctv()),
            self::template('NW-SWITCH', 'Network Switch PM', self::NETWORK, ['SW'], self::networkSwitch(), blocks: ['case_details', 'parts_usage', 'acknowledgement']),
            self::template('DC-ACS', 'Access Control PM', self::NETWORK, ['ACS'], self::accessControl()),
            self::template('DC-CTRL', 'Control Room PM', self::DC, ['CTR'], self::controlRoom()),
            self::template('PC-SET', 'PC PM (ตารางรวม)', self::PC, ['PC', 'NB', 'MON'], self::pc(),
                layout: PmChecklist::LAYOUT_GROUPED_TABLE, blocks: PmChecklist::BLOCKS),
            self::template('NW-GROUP', 'Network PM (ตารางรวม)', self::NETWORK, ['RT', 'FW', 'AP'], self::networkGroup(),
                layout: PmChecklist::LAYOUT_GROUPED_TABLE, blocks: ['customer_info', 'case_details', 'parts_usage', 'acknowledgement', 'satisfaction']),
        ];

        $covered = array_merge(...array_column($fromForms, 'prefixes'));
        $older = [];
        foreach (StandardChecklists::all() as $prefix => $checklist) {
            if (! in_array($prefix, $covered, true)) {
                $older[] = self::template('STD-'.($prefix === '' ? 'GENERAL' : $prefix), $checklist['name'], null, [$prefix], $checklist['items']);
            }
        }

        return [...$fromForms, ...$older];
    }

    /**
     * @param  list<string>  $prefixes
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $blocks  shown on the sheet
     * @param  list<string>|null  $needs
     */
    private static function template(string $code, string $name, ?string $serviceLine, array $prefixes, array $items,
        string $layout = PmChecklist::LAYOUT_PER_ASSET, array $blocks = ['acknowledgement'], ?array $needs = null): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'service_line' => $serviceLine,
            'layout' => $layout,
            'blocks' => array_merge(array_fill_keys(PmChecklist::BLOCKS, false), array_fill_keys($blocks, true)),
            'prefixes' => $prefixes,
            'needs' => $needs ?? $prefixes,
            'items' => $items,
        ];
    }

    private static function electrical(): array
    {
        $checks = 'Check list details';

        return [
            self::check('main_board', 'Check Main Distribution Board Measurement', $checks),
            self::check('circuit_breaker', 'Check Circuit Breaker', $checks),
            self::check('fuse', 'Check Fuse', $checks),
            self::check('switch', 'Check Switch', $checks),
            self::check('busbar', 'Check Busbar', $checks),
            self::check('cable_wiring', 'Check Cable Wiring', $checks),
            self::lineVoltage('Electrical Measuring Instrument Voltage'),
        ];
    }

    private static function ups(): array
    {
        $info = 'Check list details';
        $phases = [['key' => 'l1', 'label' => 'L1'], ['key' => 'l2', 'label' => 'L2'], ['key' => 'l3', 'label' => 'L3']];

        return [
            self::number('capacity', 'Total UPS Capacity', $info, 'kVA'),
            self::number('modules', 'Module', $info),
            self::text('ups_number', 'UPS Number', $info),
            self::text('battery_brand', 'Battery Brand', $info),
            self::text('battery_model', 'Battery Model', $info),
            self::number('battery_volt', 'Battery Volt', $info, 'V'),
            self::number('battery_ah', 'Battery Ah', $info, 'Ah'),
            self::number('battery_strings', 'Battery String', $info),
            self::number('battery_blocks', 'Battery Block', $info),
            self::number('battery_cable', 'Battery cable', $info, 'sq.mm.'),
            self::text('ip_address', 'IP address', $info),
            self::number('environment_temp', 'Environment Temperature', $info, '°C', max: 32),
            ['key' => 'main_input', 'label' => 'Main input', 'type' => 'measure_table', 'section' => 'Main input', 'columns' => $phases, 'rows' => [
                self::row('phase_voltage', 'Phase voltage', 'V', 210, 240),
                self::row('line_voltage', 'Line voltage', 'V', 370, 410),
                self::row('phase_current', 'Phase current', 'A'),
                self::row('frequency', 'Frequency', 'Hz', 49, 51),
            ]],
            ['key' => 'ac_output', 'label' => 'AC output', 'type' => 'measure_table', 'section' => 'AC output', 'columns' => $phases, 'rows' => [
                self::row('phase_voltage', 'Phase voltage', 'V', 210, 240),
                self::row('line_voltage', 'Line voltage', 'V', 370, 410),
                self::row('phase_current', 'Phase current', 'A'),
                self::row('load', 'Load', '%'),
            ]],
            ['key' => 'battery_voltage', 'label' => 'Battery', 'type' => 'measure_table', 'section' => 'AC output',
                'rows' => [self::row('battery', 'Battery', 'V')],
                'columns' => [['key' => 'positive', 'label' => '+'], ['key' => 'negative', 'label' => '-'], ['key' => 'total', 'label' => 'Total']]],
            self::number('backup_time', 'Test Backup time', 'Test', 'Minute'),
        ];
    }

    private static function battery(): array
    {
        $checks = 'Check list details';

        return [
            self::number('voltage', 'Voltage', $checks, 'V'),
            self::number('capacity', 'Capacity', $checks, 'Ah'),
            self::number('strings', 'String Number', $checks),
            self::number('environment_temp', 'Environment Temperature', $checks, '°C', max: 32),
            self::number('years', 'Battery Worked', $checks, 'year'),
            self::check('cable', 'Check Battery Cable', $checks),
            self::check('cabinet', 'Check Battery Cabinet', $checks),
            self::check('connector', 'Check Battery Connector', $checks),
            self::check('body', 'Check Battery Body', $checks),
            self::number('battery_total', 'Battery total', $checks, 'block'),
            ['key' => 'block_voltage', 'label' => 'Voltage per battery block', 'type' => 'repeat_table', 'section' => 'Battery block voltage',
                'rows' => ['from' => 'battery_total', 'max' => 100],
                'columns' => [['key' => 'volt', 'label' => 'Volt', 'type' => 'number', 'unit' => 'V']]],
            self::number('backup_time', 'Test Backup time', 'Test', 'Minute'),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $sensors  key, label
     */
    private static function monitoring(array $sensors): array
    {
        $unit = 'Processing Unit';
        $power = 'Power supply';
        $sensorSection = 'Monitoring system sensor';
        $sms = 'SMS system';

        return [
            self::text('ip_address', 'IP Address', $unit),
            self::text('subnet_mask', 'Subnet Mask', $unit),
            self::text('gateway', 'Gateway', $unit),
            self::check('led_state', 'LED State', $unit),
            self::check('buzzer', 'Buzzer (beep)', $unit),
            self::check('time_zone', 'Time Zone (+7 Bangkok)', $unit),
            self::check('time', 'Time', $unit),
            self::check('date', 'Date', $unit),
            self::check('power_led_state', 'LED State', $power),
            self::number('power_voltage', 'Voltage', $power, 'V'),
            self::check('power_cable', 'Power Cable', $power),
            ...array_map(fn (array $sensor) => self::check('sensor_'.$sensor[0], $sensor[1], $sensorSection), $sensors),
            self::text('gsm_number', 'GSM Service Number (+66)', $sms),
            self::text('phone_number', 'Phone Number (+66)', $sms),
            self::number('signal_strength', 'Signal strength', $sms, '%', 10, 100),
            self::check('test_sms', 'Test send SMS', $sms),
            self::check('test_email', 'Test send Email', 'Test'),
        ];
    }

    /**
     * The sensors of a Packet Power GH04 gateway / STULZ Cyber Hub, with the units installed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function packetPowerSensors(): array
    {
        return [
            ['gh04', 'PACKET POWER Model GH04-S000 (1 unit)'],
            ['gw04', 'PACKET POWER Model GW04-S00SE (1 unit)'],
            ['e300', 'PACKET POWER Model E300-H000 (8 units)'],
            ['temperature', 'Temperature Sensor (10 units)'],
            ['humidity', 'Relative Humidity Sensor (10 units)'],
            ['status', 'Status Sensor'],
        ];
    }

    private static function precisionAir(): array
    {
        $indoor = 'Evaporator & Indoor unit';
        $refrigerant = 'Check Refrigerant System';
        $outdoor = 'Outdoor Condensing unit';
        $lines = [];
        foreach ([1, 2, 3] as $line) {
            $lines[] = ['key' => "line{$line}_volt", 'label' => "Line {$line} Volt"];
            $lines[] = ['key' => "line{$line}_amp", 'label' => "Line {$line} Amp"];
        }

        return [
            self::check('display_panel', 'Check and Clean Display Panel', $indoor),
            self::check('key_panel', 'Check Key Panel', $indoor),
            self::check('compressor', 'Check Compressor', $indoor),
            self::check('evaporator_coil', 'Check Evaporator Coil', $indoor),
            self::check('heater', 'Check Heater', $indoor),
            self::check('boiler', 'Check Boiler', $indoor),
            self::check('drainage', 'Check and Clean Drainage System', $indoor),
            self::check('controller', 'Check Controller', $indoor),
            self::check('filter', 'Check Filter', $indoor),
            self::check('flow_control_fan', 'Check Flow Control FAN', $indoor),
            self::check('sensor_temp_humidity', 'Check Sensor Temperature/Humidity', $indoor),
            self::check('high_pressure_switch', 'Check High Pressure Switch', $indoor),
            self::check('hot_gas_line', 'Check Hot gas discharge line', $indoor),
            self::check('liquid_line', 'Check Liquid refrigerant line', $indoor),
            self::check('electronics_controller', 'Check Electronics Controller', $indoor),
            self::check('magnetic_control', 'Check Magnetic Control', $indoor),
            ['key' => 'compressor_electrical', 'label' => 'Check Electrical Compressor', 'type' => 'measure_table', 'section' => 'Check Electrical Compressor',
                'rows' => [['key' => 'compressor_1', 'label' => 'Compressor 1'], ['key' => 'compressor_2', 'label' => 'Compressor 2']],
                'columns' => $lines],
            self::number('suction_pressure', 'Suction pressure', $refrigerant, 'Psi'),
            self::number('discharge_pressure', 'Discharge pressure', $refrigerant, 'Psi'),
            self::number('superheat_temp', 'Superheat temp', $refrigerant, '°C'),
            self::number('compressor_temp', 'Compressor temperature', $refrigerant, '°C'),
            self::check('condensing_fan', 'Check Condensing Fan', $outdoor),
            self::check('outdoor_hot_gas_line', 'Check Hot gas discharge line', $outdoor),
            self::check('outdoor_liquid_line', 'Check Liquid refrigerant line', $outdoor),
            self::check('condensing_coil', 'Check and Clean Condensing Coil', $outdoor),
        ];
    }

    private static function waterLeak(): array
    {
        $unit = 'Unit Check';
        $visual = 'Visual Check';
        $test = 'Test';

        return [
            self::number('battery', 'Battery', $unit, 'V', min: 10.5),
            self::text('ip_address', 'IP Address', $unit),
            self::text('subnet_mask', 'Subnet Mask', $unit),
            self::text('gateway', 'Gateway', $unit),
            self::number('cable_length', 'Total Length of Sensing Cable', $unit, 'm'),
            self::check('unit_installation', 'Unit Installation', $visual),
            self::check('leader_cable', 'Leader Cable Installation', $visual),
            self::check('sensing_cable', 'Sensing Cable Installation', $visual),
            self::check('lan_cable', 'Lan Cable Installation', $visual),
            self::check('tag_label', 'Tag Label Installation', $visual),
            self::check('end_terminal', 'End Terminal Installation', $visual),
            self::check('test_led', 'Test LED', $test),
            self::check('test_buzzer', 'Test Buzzer', $test),
            self::check('test_button', 'Test Button', $test),
            self::check('test_leak_zone', 'Test water leak at zone', $test),
            self::check('display_alarm_zone', 'Display show alarm at zone', $test),
        ];
    }

    private static function fireSuppression(): array
    {
        $power = 'Power supply';
        $panel = 'Control Panel Testing';

        return [
            self::number('main_power', 'Main Power Supply', $power, 'Vac', 210, 240),
            self::number('transformer_power', 'Transformer Power Supply', $power, 'Vac', 24, 35),
            self::number('charger_voltage', 'Charger Voltage', $power, 'Vdc', 24, 27),
            self::number('battery_voltage', 'Battery Voltage', $power, 'Vdc', 24, 27),
            self::number('backup_test', 'Test Backup', $power, 'minutes'),
            self::check('lamp_test', 'Lamp Test', $panel),
            self::check('zone_fault_test', 'Zone Fault Test', $panel),
            self::check('sound_fault_test', 'Sound Fault Test', $panel),
            self::check('fire_alarm_test', 'Fire Alarm Test', $panel),
            self::check('manual_release_test', 'Manual Release Test', $panel),
            self::check('abort_station_test', 'Abort Station Test', $panel),
            self::check('alarm_bell_test', 'Alarm Bell Test', $panel),
            self::check('alarm_horn_test', 'Alarm Horn Test', $panel),
            self::check('strobe_test', 'Strobe/Flashing Lamp Test', $panel),
            self::check('extinguishing_tank', 'Extinguishing tank', $panel),
            self::check('pressure_switch', 'Pressure Switch', $panel),
        ];
    }

    private static function hssd(): array
    {
        $unit = 'Unit Check';
        $visual = 'Visual Check';
        $measure = 'Measurement';
        $test = 'Test';

        return [
            self::number('detectors', 'No. of Detector', $unit),
            self::number('line_pipes', 'No. of Line Pipe', $unit),
            self::number('pipes_under_ceiling', 'Line Pipe Under Ceiling', $unit),
            self::number('pipes_under_floor', 'Line Pipe Under raised floor', $unit),
            self::number('sampling_holes', 'No. of Sampling Hole', $unit),
            self::check('unit_installation', 'Check Unit Installation', $visual),
            self::check('electrical_installation', 'Check Electrical Installation', $visual),
            self::check('piping_installation', 'Check Piping Installation', $visual),
            self::check('sampling_hole_detail', 'Check Detail of Sampling Hole', $visual),
            self::check('end_cap', 'Check End Cap Installation', $visual),
            self::check('communication', 'Check Communication Installation', $visual),
            self::number('input_voltage', 'Input Voltage', $measure, 'Vac', 210, 240),
            self::number('charge_voltage', 'Charge Voltage', $measure, 'Vdc', 23, 28),
            self::number('battery_voltage', 'Battery Voltage', $measure, 'Vdc', 23, 28),
            self::number('sensitivity', 'Sensitivity', $measure, '%obs/ft'),
            ['key' => 'air_flow', 'label' => 'Air flow rate', 'type' => 'measure_table', 'section' => $measure,
                'rows' => array_map(fn (int $pipe) => ['key' => "pipe_{$pipe}", 'label' => "Pipe {$pipe}"], [1, 2, 3, 4]),
                'columns' => [['key' => 'flow', 'label' => 'Air flow rate']]],
            self::number('alarm_1', 'Alarm 1', $measure, '%'),
            self::number('alarm_2', 'Alarm 2', $measure, '%'),
            self::number('alarm_3', 'Alarm 3', $measure, '%'),
            self::number('alarm_4', 'Alarm 4', $measure, '%'),
            self::check('fast_learn', 'Fast Learn', $measure),
            self::number('detector_output', 'Detector output', $measure, '%'),
            self::number('aspirator_speed', 'Aspirator speed', $measure, 'ft²'),
            self::check('main_fail_test', 'Main Fail Test', $test),
            self::check('smoke_detector_test', 'Smoke Detector Test', $test),
        ];
    }

    private static function cctv(): array
    {
        $recorder = 'Recorder Information';
        $cameras = 'The Camera features Active';

        return [
            self::text('ip_address', 'IP Address', $recorder),
            self::text('subnet_mask', 'Subnet Mask', $recorder),
            self::text('gateway', 'Default Gateway', $recorder),
            self::text('ntp_address', 'NTP Address', $recorder),
            self::number('hdd_capacity', 'Hard Disk Capacity', $recorder, 'TB'),
            self::text('firmware', 'Firmware', $recorder),
            self::check('time_zone', 'Time/Zone (+7 Bangkok)', $recorder),
            self::number('backup_days', 'Backup Time', $recorder, 'Day'),
            self::number('camera_count', 'Number of cameras', $cameras),
            ['key' => 'cameras', 'label' => 'Cameras', 'type' => 'repeat_table', 'section' => $cameras,
                'rows' => ['from' => 'camera_count', 'max' => 100],
                'columns' => [
                    ['key' => 'camera_type', 'label' => 'Camera Type', 'type' => 'text'],
                    ['key' => 'serial', 'label' => 'S/N', 'type' => 'text'],
                    ['key' => 'ip_address', 'label' => 'IP Address', 'type' => 'text'],
                    ['key' => 'check', 'label' => 'Check', 'type' => 'pass_fail'],
                    ['key' => 'comment', 'label' => 'Comment', 'type' => 'text'],
                ]],
        ];
    }

    private static function networkSwitch(): array
    {
        $details = 'Details';
        $checks = 'Preventive Maintenance Check List';

        return [
            self::fromAsset('ip_address', 'IP Address', $details, 'ip_address'),
            self::fromAsset('location', 'Location', $details, 'location'),
            ['key' => 'details', 'label' => 'รายละเอียดการตรวจเช็ค', 'type' => 'textarea', 'section' => $details],
            self::check('system_status', 'Check System Status', $checks),
            self::check('machine_cable', 'Check Cable of Machine', $checks),
            self::check('cpu', 'CPU', $checks),
            self::check('memory', 'Memory', $checks),
            self::check('port_status', 'Port Status', $checks),
            self::check('main_power', 'Main Power', $checks),
            self::check('power_supply', 'Power Supply', $checks),
            self::check('status_led', 'Status LED', $checks),
            self::check('cleaning', 'Cleaning', $checks),
        ];
    }

    private static function accessControl(): array
    {
        $checks = 'Check list details';

        return [
            ['key' => 'reader_type', 'label' => 'Reader Type', 'type' => 'select', 'section' => $checks,
                'options' => ['Face scan', 'Fingerprint', 'Card', 'PIN']],
            self::check('reader_installation', 'Check Reader Unit Installation', $checks),
            self::check('cable_connector', 'Check Cable Connector', $checks),
            self::check('network_cable', 'Check Network Cable', $checks),
            self::check('network_communication', 'Check Network Communication', $checks),
            self::check('break_glass', 'Check Break Glass', $checks),
            self::check('exit_door', 'Check Exit Door', $checks),
            self::check('electronic_lock', 'Check Electronic Lock', $checks),
            self::number('power_supply_voltage', 'Power Supply Voltage', $checks, 'Vdc', 12, 16),
            self::number('battery_voltage', 'Battery Voltage', $checks, 'Vdc', min: 10.5),
        ];
    }

    private static function controlRoom(): array
    {
        $electrical = 'Electrical';
        $display = 'Display system';
        $communication = 'Communication system';

        return [
            self::check('main_board', 'Check Main Distribution Board Measurement', $electrical),
            self::check('circuit_breaker', 'Check Circuit Breaker', $electrical),
            self::lineVoltage($electrical),
            self::check('image_clarity', 'Image clarity and brightness', $display),
            self::check('image_distortion', 'Image distortion', $display),
            self::check('image_sharpness', 'Image sharpness and brightness', $display),
            self::check('image_transmission', 'Normality of image transmission to the display', $display),
            self::check('webcam', 'Webcam functionality (video/audio)', $communication),
            self::check('video_conference', 'Video conference linkage with external agencies', $communication),
        ];
    }

    /**
     * A row per machine (a PC with its monitor): the register numbers and what was done.
     */
    private static function pc(): array
    {
        $checks = 'Preventive Maintenance Check list';

        return [
            self::fromAsset('property_no', 'หมายเลขครุภัณฑ์', $checks, 'property_no'),
            self::fromAsset('serial_number', 'S/N Number', $checks, 'serial_number'),
            self::check('cleaning', 'Cleaning', $checks),
            self::check('task_completed', 'Task Completed', $checks),
            self::text('detail', 'Detail', $checks),
        ];
    }

    /**
     * A row per network device, with the checks of the network switch form.
     */
    private static function networkGroup(): array
    {
        $checks = 'Preventive Maintenance Check List';

        return [
            self::fromAsset('ip_address', 'IP Address', $checks, 'ip_address'),
            self::fromAsset('location', 'Location', $checks, 'location'),
            self::check('system_status', 'Check System Status', $checks),
            self::check('machine_cable', 'Check Cable of Machine', $checks),
            self::check('cpu', 'CPU', $checks),
            self::check('memory', 'Memory', $checks),
            self::check('port_status', 'Port Status', $checks),
            self::check('power_supply', 'Power Supply', $checks),
            self::check('status_led', 'Status LED', $checks),
            self::check('cleaning', 'Cleaning', $checks),
            self::text('detail', 'Detail', $checks),
        ];
    }

    /**
     * Line and phase voltages of a three-phase board (R / S / T / N / G) with their ranges.
     */
    private static function lineVoltage(string $section): array
    {
        $line = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'min' => 371, 'max' => 410];
        $phase = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'min' => 210, 'max' => 240];

        return ['key' => 'voltage', 'label' => 'Electrical Measuring Instrument Voltage', 'type' => 'measure_table', 'section' => $section,
            'rows' => [self::row('reading', 'Voltage', 'V')],
            'columns' => [
                $line('r_s', 'R - S'), $line('s_t', 'S - T'), $line('t_r', 'T - R'),
                $phase('r_n', 'R - N'), $phase('s_n', 'S - N'), $phase('t_n', 'T - N'),
                ['key' => 'n_g', 'label' => 'N - G', 'min' => 0, 'max' => 0.9],
            ]];
    }

    private static function check(string $key, string $label, string $section): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'pass_fail', 'section' => $section];
    }

    private static function text(string $key, string $label, string $section): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text', 'section' => $section];
    }

    private static function number(string $key, string $label, string $section, ?string $unit = null, ?float $min = null, ?float $max = null): array
    {
        return array_filter(
            ['key' => $key, 'label' => $label, 'type' => 'number', 'section' => $section, 'unit' => $unit, 'min' => $min, 'max' => $max],
            fn ($value) => $value !== null,
        );
    }

    private static function fromAsset(string $key, string $label, string $section, string $source): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'readonly_from_asset', 'section' => $section, 'source' => $source];
    }

    private static function row(string $key, string $label, ?string $unit = null, ?float $min = null, ?float $max = null): array
    {
        return array_filter(['key' => $key, 'label' => $label, 'unit' => $unit, 'min' => $min, 'max' => $max], fn ($value) => $value !== null);
    }
}
