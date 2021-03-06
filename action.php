<?php

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $command = $_GET['command'];
    $minTemp = $_GET['min'] ?? 14;
    $maxTemp = $_GET['max'] ?? 26;

    $heatController = new HeatController();

    if ($action == 'set') {
        switch ($command) {
            case 'off':
                $heatController->turnOff();
                break;
            case 'on':
                $heatController->turnOn();
                break;
            case 'turbo':
                $heatController->turnOnTurbo();
                break;
            case 'auto':
                $return = $heatController->autoMode($minTemp, $maxTemp);
                break;
        }

        //header("location: /");

    } else {
        switch ($command) {
            case 'getTemp':
                $return = $heatController->getTemperature();
                break;
            case 'getHeatingMode':
                $return = $heatController->getHeatingMode();
                break;
            default:
                $return = $heatController->getAll();
        }

    }

    header('Content-type: application/json');
    exit(json_encode($return));

}

function io($mode, $pin, $value = '')
{
    $return = `gpio -g $mode $pin $value`;

    $return = trim($return);

    if ($mode == 'read') {
        if ($return === '0') {
            return 0;
        } elseif ($return === '1') {
            return 1;
        } else {
            exit("invalid reply: $return");
        }
    }
}

class HeatController
{
    const ON_PIN = 10;
    const TURBO_PIN = 9;

    protected function resetToDefault()
    {
        $on = self::ON_PIN;
        $turbo = self::TURBO_PIN;

        io('write', $on, '1');
        io('write', $turbo, '1');
    }

    public function turnOn()
    {
        $this->resetToDefault();

        $on = self::ON_PIN;

        io('mode', $on, 'out');
        io('write', $on, '0');
    }

    public function turnOnTurbo()
    {
        $on = self::ON_PIN;
        $turbo = self::TURBO_PIN;

        io('mode', $on, 'out');
        io('write', $on, '0');
        io('mode', $turbo, 'out');
        io('write', $turbo, '0');
    }

    public function turnOff()
    {
        $this->resetToDefault();
    }

    public function isOn()
    {
        $on = self::ON_PIN;
        $turbo = self::TURBO_PIN;

        return (io('read', $on) === 0 && io('read', $turbo) === 1);
    }

    public function isTurbo()
    {
        $on = self::ON_PIN;
        $turbo = self::TURBO_PIN;

        return (io('read', $on) === 0 && io('read', $turbo) === 0);
    }

    public function isOff()
    {
        $on = self::ON_PIN;
        $turbo = self::TURBO_PIN;


        return (io('read', $on) === 1 && io('read', $turbo) === 1);
    }


    public function getHeatingMode()
    {
        if ($this->isTurbo()) {
            return 'turbo';
        } elseif ($this->isOn()) {
            return 'on';
        } else {
            return 'off';
        }
    }

    public function getTemperature()
    {
        `modprobe w1-gpio`;
        `modprobe w1-therm`;

        $baseDir = '/sys/bus/w1/devices/';
        $deviceFolder = glob($baseDir . '28*')[0];
        $deviceFile = $deviceFolder . '/w1_slave';

        $data = file($deviceFile, FILE_IGNORE_NEW_LINES);

        $temperature = null;

        if (preg_match('/YES$/', $data[0])) {
            if (preg_match('/t=(\d+)$/', $data[1], $matches, PREG_OFFSET_CAPTURE)) {
                $temperature = (int)$matches[1][0] / 1000;
            }
        }

        return $temperature;
    }

    public function getAll()
    {
        return ['mode' => $this->getHeatingMode(), 'temp' => $this->getTemperature()];
    }

    public function autoMode($minTemp, $maxTemp, $turboThreshold = 2)
    {

        $currentTemp = $this->getTemperature();
	$status = '';

        if ($currentTemp > $maxTemp) {
	    $status = 'Current temp > max temp. ';
            if ($this->isOn()) {
		$status .= 'Heating was on. Switched off. ';
                $this->turnOff();
            } else if($this->isTurbo()) {
		$status .= 'Heating was Turbo. Switched off. ';
                $this->turnOff();
	    }
	    
        } else if ($currentTemp < $minTemp) {
	    $status = 'Current temp < min temp. ';
            if (($maxTemp - $currentTemp) > $turboThreshold) {
		$status .= 'Difference larger than treshold, going turbo. ';
                if (!$this->isTurbo()) {
		    $status .= 'Turbo was not on - turned on. ';
                    $this->turnOnTurbo();
                } else {
		    $status .= 'Turbo was already enabled. ';
		}
            } else {
		$status .= 'Difference was lower than treshold. Turning normal mode on. ';
                if (!$this->isOn()) {
		    $status .= 'Normal mode was not ON, so turned ON. ';
                    $this->turnOn();
                } else {
		    $status .= 'Normal mode was already enabled. ';
		}
            }

        } else {
	    $status .= "Temerature in range. Min: $minTemp Max: $maxTemp Current: $currentTemp";
	}

        return ['msg'=>$status];

    }
}