<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */

class openzwave extends eqLogic {
	/*     * *************************Attributs****************************** */

	private static $_curl = null;
	private static $_nbZwaveServer = 1;
	private static $_listZwaveServer = null;
	private static $_zwaveUpdatetime = array();

	/*     * ***********************Methode static*************************** */

	public static function sick() {
		foreach (self::listServerZwave() as $serverID => $server) {
			if (isset($server['name'])) {
				try {
					echo "Server name : " . $server['name'] . "\n";
					echo "Server addr : " . $server['addr'] . "\n";
					echo "Port : " . $server['port'] . "\n";
					echo "Test connection to zwave server...";
					self::callRazberry('/ZWaveAPI/Data/0', $serverID);
					echo "OK\n";
				} catch (Exception $e) {
					echo "NOK\n";
					echo "Description : " . $e->getMessage();
					echo "\n";
				}
			}
		}
	}

	public static function listServerZwave($_autofix = true) {
		if (self::$_listZwaveServer == null) {
			self::$_listZwaveServer = array();
			if (config::byKey('port', 'openzwave', 'none') != 'none') {
				self::$_listZwaveServer[0] = array(
					'id' => 0,
					'name' => 'Local',
					'addr' => '127.0.0.1',
					'port' => config::byKey('port_server', 'openzwave', 8083),
				);
				if ($_autofix & config::byKey('urlPath0', 'openzwave') == '') {
					self::updateNginxRedirection();
				}
				self::$_listZwaveServer[0]['path'] = '/' . config::byKey('urlPath0', 'openzwave');
			}
			if (config::byKey('jeeNetwork::mode') == 'master') {
				foreach (jeeNetwork::byPlugin('openzwave') as $jeeNetwork) {
					self::$_listZwaveServer[$jeeNetwork->getId()] = array(
						'id' => $jeeNetwork->getId(),
						'name' => $jeeNetwork->getName(),
						'addr' => $jeeNetwork->getRealIp(),
						'port' => $jeeNetwork->configByKey('port_server', 'openzwave', 8083),
					);
					if ($_autofix && config::byKey('urlPath' . $jeeNetwork->getId(), 'openzwave') == '') {
						self::updateNginxRedirection();
					}
					self::$_listZwaveServer[$jeeNetwork->getId()]['path'] = '/' . config::byKey('urlPath' . $jeeNetwork->getId(), 'openzwave');
				}
			}
		}
		return self::$_listZwaveServer;
	}

	public static function updateNginxRedirection() {
		foreach (self::listServerZwave(false) as $zwave) {
			$urlPath = config::byKey('urlPath' . $zwave['id'], 'openzwave');
			if ($urlPath == '') {
				$urlPath = 'openzwave_' . $zwave['id'] . '_' . config::genKey(30);
				config::save('urlPath' . $zwave['id'], $urlPath, 'openzwave');
			}
			$rules = array(
				"location /" . $urlPath . "/ {\n" .
				"proxy_pass http://" . $zwave['addr'] . ":" . $zwave['port'] . "/;\n" .
				"proxy_redirect off;\n" .
				"proxy_set_header Host \$host:\$server_port;\n" .
				"proxy_set_header X-Real-IP \$remote_addr;\n" .
				"}",
			);
			network::nginx_saveRule($rules);
		}
	}

	public static function removeNginxRedirection() {
		foreach (self::listServerZwave(false) as $zwave) {
			$urlPath = config::byKey('urlPath' . $zwave['id'], 'openzwave');
			$rules = array(
				"location /" . $urlPath . "/ {\n",
			);
			network::nginx_removeRule($rules);
			config::save('urlPath' . $zwave['id'], '', 'openzwave');
		}
	}

	public static function callRazberry($_url, $_serverId = 0) {
		if (self::$_curl == null) {
			self::$_curl = curl_init();
		}
		if (self::$_listZwaveServer == null) {
			self::listServerZwave();
		}
		$url = 'http://' . self::$_listZwaveServer[$_serverId]['addr'] . ':' . self::$_listZwaveServer[$_serverId]['port'] . $_url;
		$ch = self::$_curl;
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => true,
		));
		$result = curl_exec($ch);
		if (curl_errno($ch)) {
			$curl_error = curl_error($ch);
			throw new Exception(__('Echec de la requete http : ', __FILE__) . $url . ' Curl error : ' . $curl_error, 404);
		}
		if (is_json($result)) {
			return json_decode($result, true);
		} else {
			return $result;
		}
	}

	public static function getZwaveInfo($_path, $_serverId = 0) {
		$results = self::callRazberry('/ZWaveAPI/Data/0', $_serverId);
		if ($_path != '') {
			$paths = explode('::', $_path);
			foreach ($paths as $path) {
				if (isset($results[$path])) {
					$results = $results[$path];
				} else {
					return null;
				}
			}
		}
		return $results;
	}

	public static function backup($_path) {
		exec('cp /opt/python-openzwave/zwcfg_*.xml ' . $_path);
	}

	public static function pull() {
		$startLoadTime = getmicrotime();
		foreach (self::listServerZwave() as $serverID => $server) {
			$results = self::callRazberry('/ZWaveAPI/Data/1', $serverID);
			if (!is_array($results)) {
				continue;
			}
			foreach ($results as $key => $result) {
				if ($key == 'controller.data.lastExcludedDevice') {
					if ($result['value'] != null) {
						nodejs::pushUpdate('zwave::' . $key, array('name' => $server['name'], 'state' => 0, 'serverId' => $serverID));
						nodejs::pushUpdate('jeedom::alert', array(
							'level' => 'warning',
							'message' => __('Un périphérique Z-Wave est en cours d\'exclusion. Logical ID : ', __FILE__) . $result['value'],
						));
						sleep(5);
						self::syncEqLogicWithRazberry($serverID);
						nodejs::pushUpdate('jeedom::alert', array(
							'level' => 'warning',
							'message' => '',
						));
					}
				} else if ($key == 'controller') {
					if (isset($result['controllerState'])) {
						nodejs::pushUpdate('zwave::controller.data.controllerState', array('name' => $server['name'], 'state' => $result['controllerState']['value'], 'serverId' => $serverID));
					}
				} else if ($key == 'controller.data.lastIncludedDevice') {
					if ($result['value'] != null) {
						nodejs::pushUpdate('zwave::' . $key, array('name' => $server['name'], 'state' => 0, 'serverId' => $serverID));
						$eqLogic = self::getEqLogicByLogicalIdAndServerId($result['value'], $serverID);
						if (!is_object($eqLogic)) {
							nodejs::pushUpdate('jeedom::alert', array(
								'level' => 'warning',
								'message' => __('Nouveau module Z-Wave détecté. Début de l\'intégration', __FILE__),
							));
							sleep(5);
							for ($i = 0; $i < 30; $i++) {
								nodejs::pushUpdate('jeedom::alert', array(
									'level' => 'warning',
									'message' => __('Pause de ', __FILE__) . (30 - $i) . __(' pour synchronisation avec le module', __FILE__),
								));
								sleep(1);
							}
							nodejs::pushUpdate('jeedom::alert', array(
								'level' => 'warning',
								'message' => __('Inclusion en cours...', __FILE__),
							));
							self::syncEqLogicWithRazberry($serverID);
						}
					}
				} else {
					$explodeKey = explode('.', $key);
					if (!isset($explodeKey[1])) {
						continue;
					}
					$eqLogic = self::getEqLogicByLogicalIdAndServerId($explodeKey[1], $serverID);
					if (is_object($eqLogic)) {
						$attribut = implode('.', array_slice($explodeKey, 6));
						foreach ($eqLogic->getCmd('info', $explodeKey[3] . '.0x' . dechex($explodeKey[5]), null, true) as $cmd) {
							if ($cmd->getConfiguration('value') == '271.1.1_fibaro.fgs221.fil.pilote.json') {
								$cmd->event($cmd->getPilotWire());
							} else {
								if (strpos(str_replace(array(']', '['), array('', '.'), $cmd->getConfiguration('value')), $attribut) !== false) {
									$cmd->handleUpdateValue($result);
								}
							}
						}
					}
				}
			}
		}
	}

	public static function getEqLogicByLogicalIdAndServerId($_logical_id, $_serverId = 0) {
		foreach (self::byLogicalId($_logical_id, 'openzwave', true) as $eqLogic) {
			if ($eqLogic->getConfiguration('serverID', 0) == $_serverId) {
				return $eqLogic;
			}
		}
		return null;
	}

	public static function syncEqLogicWithRazberry($_serverId = 0) {
		$results = self::callRazberry('/ZWaveAPI/Data/0', $_serverId);
		$findDevice = array();
		$include_device = '';
		$controller_id = openzwave::getZwaveInfo('controller::data::nodeId::value', $_serverId);
		foreach ($results['devices'] as $nodeId => $result) {
			if ($nodeId == $controller_id) {
				continue;
			}
			$findDevice[$nodeId] = $nodeId;
			$eqLogic = self::getEqLogicByLogicalIdAndServerId($nodeId, $_serverId);
			if (!is_object($eqLogic)) {
				$eqLogic = new eqLogic();
				$eqLogic->setEqType_name('openzwave');
				$eqLogic->setIsEnable(1);
				if (isset($result['data']['product_name']['value']) && trim($result['data']['product_name']['value']) != '') {
					$eqLogic->setName('[' . $eqLogic->getLogicalId() . ']' . $result['data']['product_name']['value']);
				} else {
					$eqLogic->setName('Device ' . $nodeId);
				}
				$eqLogic->setConfiguration('product_name', $result['data']['product_name']['value']);
				$eqLogic->setConfiguration('manufacturer_id', $result['data']['manufacturerId']['value']);
				$eqLogic->setConfiguration('product_type', $result['data']['manufacturerProductType']['value']);
				$eqLogic->setConfiguration('product_id', $result['data']['manufacturerProductId']['value']);
				$eqLogic->setLogicalId($nodeId);
				$eqLogic->setConfiguration('serverID', $_serverId);
				$eqLogic->setIsVisible(1);
				$eqLogic->save();
				$eqLogic = openzwave::byId($eqLogic->getId());
				$include_device = $eqLogic->getId();
				$eqLogic->createCommand();
			} else {
				if (isset($result['data']['product_name']['value'])) {
					$eqLogic->setConfiguration('product_name', $result['data']['product_name']['value']);
					$eqLogic->setConfiguration('manufacturer_id', $result['data']['manufacturerId']['value']);
					$eqLogic->setConfiguration('product_type', $result['data']['manufacturerProductType']['value']);
					$eqLogic->setConfiguration('product_id', $result['data']['manufacturerProductId']['value']);
					$eqLogic->save();
				}
			}
		}
		if (config::byKey('autoRemoveExcludeDevice', 'openzwave') == 1) {
			foreach (self::byType('openzwave') as $eqLogic) {
				if (!isset($findDevice[$eqLogic->getLogicalId()]) && $eqLogic->getConfiguration('serverID') == $_serverId) {
					$eqLogic->remove();
				}
			}
		}
		nodejs::pushUpdate('zwave::includeDevice', $include_device);
		nodejs::pushUpdate('jeedom::alert', array(
			'level' => 'warning',
			'message' => '',
		));
	}

	public static function changeIncludeState($_mode, $_state, $_serverId = 0) {
		if ($_state == 0) {
			self::callRazberry('/ZWaveAPI/Run/CancelCommand()', $_serverId);
			return;
		}
		if ($_mode == 1) {
			self::callRazberry('/ZWaveAPI/Run/controller.AddNodeToNetwork(' . $_state . ')', $_serverId);
		} else {
			self::callRazberry('/ZWaveAPI/Run/controller.RemoveNodeFromNetwork(' . $_state . ')', $_serverId);
		}
	}

	public static function cronDaily() {
		foreach (self::byType('zwave') as $eqLogic) {
			if ($eqLogic->getConfiguration('noBatterieCheck', 0) != 1) {
				try {
					$info = $eqLogic->getInfo();
					if (isset($info['battery']) && $info['battery'] !== '') {
						$eqLogic->batteryStatus($info['battery']['value'], $info['battery']['datetime']);
					}
				} catch (Exception $exc) {

				}
			}
		}
	}

	public static function updateConf() {
		foreach (self::byType('openzwave') as $openzwave) {
			if (!is_file(dirname(__FILE__) . '/../config/devices/' . $openzwave->getConfFilePath())) {
				return;
			}
			$content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $openzwave->getConfFilePath());
			if (!is_json($content)) {
				return;
			}
			$device = json_decode($content, true);
			if (!isset($device['configuration']) || !isset($device['configuration']['conf_version'])) {
				continue;
			}
			if ($device['configuration']['conf_version'] <= $openzwave->getConfiguration('conf_version')) {
				continue;
			}
			$openzwave->createCommand(true);
		}
	}

/*     * ********************************************************************** */
/*     * ***********************OPENZWAVE MANAGEMENT*************************** */
	public static function updateOpenzwave() {
		log::remove('openzwave_update');
		$cmd = 'sudo /bin/bash ' . dirname(__FILE__) . '/../../ressources/install.sh';
		$cmd .= ' >> ' . log::getPathToLog('openzwave_update') . ' 2>&1 &';
		exec($cmd);
	}

	public static function cron() {
		$port = config::byKey('port', 'openzwave', 'none');
		if ($port != 'none') {
			if ($port == 'auto' || file_exists(jeedom::getUsbMapping($port))) {
				if (!self::deamonRunning()) {
					self::runDeamon();
				}
				message::removeAll('openzwave', 'noOpenzwaveComPort');
			} else {
				log::add('rfxcom', 'error', __('Le port du openZwave est vide ou n\'existe pas', __FILE__), 'noOpenzwaveComPort');
			}
		}
	}

	public static function runDeamon($_debug = false) {
		self::stopDeamon();
		log::add('openzwave', 'info', 'Lancement du démon openzwave');
		$port = config::byKey('port', 'openzwave');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
			if (@!file_exists($port)) {
				throw new Exception(__('Le port : ', __FILE__) . print_r($port, true) . __(' n\'existe pas', __FILE__));
			}
			exec('sudo chmod 777 ' . $port . ' > /dev/null 2>&1');
		}
		$port_server = config::byKey('port_server', 'openzwave', 8083);
		$openzwave_path = realpath(dirname(__FILE__) . '/../../ressources/zwaveserver');
		if ($_debug) {
			$cmd = 'nice -n 19 /usr/bin/python ' . $openzwave_path . '/rest-server.py --pidfile=/tmp/openzwave.pid --device=' . $port . ' --log=Debug --port=' . $port_server;
		} else {
			$cmd = 'nice -n 19 /usr/bin/python ' . $openzwave_path . '/rest-server.py --pidfile=/tmp/openzwave.pid --device=' . $port . ' --log=Info --port=' . $port_server;
		}
		log::add('openzwave', 'info', 'Lancement démon openzwave : ' . $cmd);
		$result = exec('nohup ' . $cmd . ' >> ' . log::getPathToLog('openzwave') . ' 2>&1 &');
		if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
			log::add('openzwave', 'error', $result);
			return false;
		}
		$i = 0;
		while ($i < 30) {
			if (self::deamonRunning()) {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('openzwave', 'error', 'Impossible de lancer le démon openzwave, vérifiez le port', 'unableStartDeamon');
			return false;
		}
		message::removeAll('openzwave', 'unableStartDeamon');
		log::add('openzwave', 'info', 'Démon openzwave lancé');
	}

	public static function deamonRunning() {
		$pid_file = '/tmp/openzwave.pid';
		if (!file_exists($pid_file)) {
			return false;
		}
		$pid = trim(file_get_contents($pid_file));
		if (posix_getsid($pid)) {
			return true;
		}
		unlink($pid_file);
		return false;
	}

	public static function stopDeamon() {
		$pid_file = '/tmp/openzwave.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			posix_kill($pid, 15);
			if (self::deamonRunning()) {
				sleep(1);
				posix_kill($pid, 9);
			}
			if (self::deamonRunning()) {
				sleep(1);
				exec('kill -9 ' . $pid . ' > /dev/null 2>&1');
			}
		}
		exec('fuser -k ' . config::byKey('port_server', 'openzwave', 8083) . '/tcp > /dev/null 2>&1');
		exec('sudo fuser -k ' . config::byKey('port_server', 'openzwave', 8083) . '/tcp > /dev/null 2>&1');
		return self::deamonRunning();
	}

	/*     * *********************Methode d'instance************************* */

	public function ping() {
		$info = $this->getInfo();
		if ($info['state']['value'] == __('Réveillé', __FILE__) || $info['state']['value'] == __('Actif', __FILE__)) {
			$cmds = $this->getCmd('info');
			if (strtotime($this->getStatus('lastCommunication', date('Y-m-d H:i:s'))) < (strtotime('now') - 120)) {
				sleep(5);
			}
			if (strtotime($this->getStatus('lastCommunication', date('Y-m-d H:i:s'))) < (strtotime('now') - 120)) {
				return false;
			}
		} else {
			if ($this->getStatus('lastCommunication', date('Y-m-d H:i:s')) < date('Y-m-d H:i:s', strtotime('-' . $this->getTimeout() . ' minutes' . date('Y-m-d H:i:s')))) {
				return false;
			}
		}
		return true;
	}

	public function getInfo() {
		$return = array();
		if (!is_numeric($this->getLogicalId())) {
			return $return;
		}
		$results = self::callRazberry('/ZWaveAPI/Run/devices[' . $this->getLogicalId() . ']', $this->getConfiguration('serverID', 1));
		if ($this->getConfiguration('noBatterieCheck') != 1 && isset($results['instances'][0]['commandClasses'][128]) && $results['instances'][0]['commandClasses'][128]['data']['supported']['value'] === true) {
			$return['battery'] = array(
				'value' => $results['instances'][0]['commandClasses'][128]['data'][0]['val'],
				'datetime' => date('Y-m-d H:i:s', $results['instances'][0]['commandClasses'][128]['data']['last']['updateTime']),
				'unite' => '%',
			);
		}

		if (isset($results['data'])) {
			if (isset($results['data']['isAwake'])) {
				$return['state'] = array(
					'value' => ($results['data']['isAwake']['value']) ? __('Réveillé', __FILE__) : __('Endormi', __FILE__),
					'datetime' => date('Y-m-d H:i:s', $results['data']['isAwake']['updateTime']),
				);
			}
			if (isset($results['data']['isFailed'])) {
				$return['state']['value'] = ($results['data']['isFailed']['value']) ? 'Dead' : $return['state']['value'];
			}
			if (isset($results['data']['vendorString'])) {
				$return['brand'] = array(
					'value' => $results['data']['vendorString']['value'],
					'datetime' => '',
				);
			}

			if (isset($results['data']['lastReceived'])) {
				$return['lastReceived'] = array(
					'value' => date('Y-m-d H:i', $results['data']['lastReceived']['updateTime']),
					'datetime' => date('Y-m-d H:i:s', $results['data']['lastReceived']['updateTime']),
				);
			}

			if ((isset($return['battery']) && $return['battery']['value'] != '') || (isset($return['state']) && $return['state']['value'] == __('Endormi', __FILE__))) {
				$return['powered'] = array(
					'value' => false,
					'datetime' => date('Y-m-d H:i:s'),
				);
			} else {
				$return['powered'] = array(
					'value' => true,
					'datetime' => date('Y-m-d H:i:s'),
				);
			}
		}
		return $return;
	}

	public function loadCmdFromConf($_update = false) {
		if (!is_file(dirname(__FILE__) . '/../config/devices/' . $this->getConfFilePath())) {
			return;
		}
		$content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $this->getConfFilePath());
		if (!is_json($content)) {
			return;
		}
		$this->setConfiguration('applyConfFile', $this->getConfFilePath());
		$device = json_decode($content, true);
		if (!is_array($device) || !isset($device['commands'])) {
			return true;
		}
		$cmd_order = 0;
		$link_cmds = array();
		if (isset($device['name']) && !$_update) {
			$this->setName('[' . $this->getLogicalId() . ']' . $device['name']);
		}
		if (isset($device['configuration'])) {
			foreach ($device['configuration'] as $key => $value) {
				try {
					$this->setConfiguration($key, $value);
				} catch (Exception $e) {

				}
			}
		}
		nodejs::pushUpdate('jeedom::alert', array(
			'level' => 'warning',
			'message' => __('Création des commandes à partir d\'une configuration', __FILE__),
		));

		$commands = $device['commands'];
		foreach ($commands as &$command) {
			if (!isset($command['configuration']['instanceId'])) {
				$command['configuration']['instanceId'] = 0;
			}
			if (!isset($command['configuration']['class'])) {
				$command['configuration']['class'] = '';
			}
			$cmd = null;
			foreach ($this->getCmd() as $liste_cmd) {
				if ($liste_cmd->getConfiguration('instanceId', 0) == $command['configuration']['instanceId'] &&
					$liste_cmd->getConfiguration('class') == $command['configuration']['class'] &&
					$liste_cmd->getConfiguration('value') == $command['configuration']['value']) {
					$cmd = $liste_cmd;
					break;
				}
				if ($liste_cmd->getName() == $command['name']) {
					$cmd = $liste_cmd;
					break;
				}
			}
			try {
				if ($cmd == null || !is_object($cmd)) {
					$cmd = new openzwaveCmd();
					$cmd->setOrder($cmd_order);
					$cmd->setEqLogic_id($this->getId());
				} else {
					$command['name'] = $cmd->getName();
					if (isset($command['display'])) {
						unset($command['display']);
					}
				}
				utils::a2o($cmd, $command);
				if (isset($command['value'])) {
					$cmd->setValue(null);
				}
				$cmd->save();
				if (isset($command['value'])) {
					$link_cmds[$cmd->getId()] = $command['value'];
				}
				$cmd_order++;
			} catch (Exception $exc) {

			}
		}

		if (count($link_cmds) > 0) {
			foreach ($this->getCmd() as $eqLogic_cmd) {
				foreach ($link_cmds as $cmd_id => $link_cmd) {
					if ($link_cmd == $eqLogic_cmd->getName()) {
						$cmd = cmd::byId($cmd_id);
						if (is_object($cmd)) {
							$cmd->setValue($eqLogic_cmd->getId());
							$cmd->save();
						}
					}
				}
			}
		}
		$this->save();

		nodejs::pushUpdate('jeedom::alert', array(
			'level' => 'warning',
			'message' => '',
		));
	}

	public function postSave() {
		try {
			$location = '';
			$objet = $this->getObject();
			if (is_object($objet)) {
				$location = $objet->getName();
			} else {
				$location = 'aucun';
			}
			$name = $this->getName();
			$humanLocation = urlencode(trim($location));
			$humanName = urlencode(trim($name));
			self::callRazberry('/ZWaveAPI/Run/devices[' . $this->getLogicalId() . '].SetDeviceName(' . $humanLocation . ',' . $humanName . ')', $this->getConfiguration('serverID', 1));
		} catch (Exception $e) {

		}
		if ($this->getConfiguration('applyConfFile') != $this->getConfiguration('fileconf') && $this->getConfiguration('fileconf') != '') {
			$this->loadCmdFromConf();
		}
	}

	public function getConfFilePath($_all = false) {
		if ($_all) {
			$id = $this->getConfiguration('manufacturer_id') . '.' . $this->getConfiguration('product_type') . '.' . $this->getConfiguration('product_id');
			return ls(dirname(__FILE__) . '/../config/devices', $id . '_*.json', false, array('files', 'quiet'));
		}
		if (file_exists($this->getConfiguration('fileconf'))) {
			return $this->getConfiguration('fileconf');
		}
		$id = $this->getConfiguration('manufacturer_id') . '.' . $this->getConfiguration('product_type') . '.' . $this->getConfiguration('product_id');
		$files = ls(dirname(__FILE__) . '/../config/devices', $id . '_*.json', false, array('files', 'quiet'));
		if (count($files) > 0) {
			return $files[0];
		}
		return false;
	}

	public function getImgFilePath() {
		$id = $this->getConfiguration('manufacturer_id') . '.' . $this->getConfiguration('product_type') . '.' . $this->getConfiguration('product_id');
		$files = ls(dirname(__FILE__) . '/../img/devices', $id . '_*.jpg', false, array('files', 'quiet'));
		if (count($files) > 0) {
			return $files[0];
		}
		return false;
	}

	public function getAssistantFilePath() {
		$id = $this->getConfiguration('manufacturer_id') . '.' . $this->getConfiguration('product_type') . '.' . $this->getConfiguration('product_id');
		$files = ls(dirname(__FILE__) . '/../config/devices', $id . '_*.php', false, array('files', 'quiet'));
		if (count($files) > 0) {
			return $files[0];
		}
		return false;
	}

	public function createCommand() {
		$return = array();
		if (!is_numeric($this->getLogicalId())) {
			return;
		}
		if (is_file(dirname(__FILE__) . '/../config/devices/' . $this->getConfFilePath())) {
			$this->loadCmdFromConf();
			return;
		}
		nodejs::pushUpdate('jeedom::alert', array(
			'level' => 'warning',
			'message' => __('Création des commandes en mode automatique', __FILE__),
		));
		$results = self::callRazberry('/ZWaveAPI/Run/devices[' . $this->getLogicalId() . ']', $this->getConfiguration('serverID', 1));
		if (isset($results['instances']) && is_array($results['instances'])) {
			foreach ($results['instances'] as $instanceID => $instance) {
				if (isset($instance['commandClasses']) && is_array($instance['commandClasses'])) {
					foreach ($instance['commandClasses'] as $ccId => $commandClasses) {
						if (isset($commandClasses['data']) && is_array($commandClasses['data'])) {
							foreach ($commandClasses['data'] as $index => $data) {
								if (isset($data['genre']) && $data['genre'] != 'Config' && $data['genre'] != 'System') {
									$cmd_info = null;
									$cmd = null;
									if (count($results['instances']) > 2) {
										$cmd_name_number = $instanceID + 1;
										$cmd_name = $data['name'] . ' ' . $index . ' ' . $cmd_name_number;
									} else {
										$cmd_name = $data['name'] . ' ' . $index;
									}
									if (!$data['write_only']) {
										$cmd_info = new openzwaveCmd();
										$cmd_info->setType('info');
										$cmd_info->setEqLogic_id($this->getId());
										$cmd_info->setUnite($data['units']);
										if ($data['read_only']) {
											$cmd_info->setName($cmd_name);
										} else {
											$cmd_info->setName('Info ' . $cmd_name);
										}
										$cmd_info->setConfiguration('instanceId', $instanceID);
										$cmd_info->setConfiguration('class', $ccId);
										$cmd_info->setConfiguration('value', 'data[' . $index . '].val');
										switch ($data['type']) {
											case 'bool':
												$cmd_info->setSubType('binary');
												break;
											case 'int':
												$cmd_info->setSubType('numeric');
												$cmd_info->setIsHistorized(1);
												break;
											case 'float':
												$cmd_info->setSubType('numeric');
												$cmd_info->setIsHistorized(1);
												break;
										}
										$cmd_info->save();
									}
									if (!$data['read_only']) {
										switch ($data['type']) {
											case 'bool':
												$cmd = new openzwaveCmd();
												$cmd->setSubType('other');
												$cmd->setType('action');
												$cmd->setEqLogic_id($this->getId());
												$cmd->setConfiguration('instanceId', $instanceID);
												$cmd->setConfiguration('class', $ccId);
												if ($data['typeZW'] == 'Button') {
													$cmd->setName($cmd_name);
													$cmd->setConfiguration('value', 'data[' . $index . '].PressButton()');
												} else {
													$cmd->setName($cmd_name . ' On');
													$cmd->setConfiguration('value', 'data[' . $index . '].Set(255)');
												}
												$cmd->save();

												$cmd = new openzwaveCmd();
												$cmd->setSubType('other');
												$cmd->setType('action');
												$cmd->setEqLogic_id($this->getId());
												$cmd->setConfiguration('instanceId', $instanceID);
												$cmd->setConfiguration('class', $ccId);
												if ($data['typeZW'] == 'Button') {
													$cmd->setName($cmd_name . ' Stop');
													$cmd->setIsVisible(0);
													$cmd->setConfiguration('value', 'data[' . $index . '].ReleaseButton()');
												} else {
													$cmd->setName($cmd_name . ' Off');
													$cmd->setConfiguration('value', 'data[' . $index . '].Set(0)');
												}
												$cmd->save();
												break;
											case 'int':
												$cmd = new openzwaveCmd();
												$cmd->setType('action');
												$cmd->setEqLogic_id($this->getId());
												$cmd->setName($cmd_name);
												$cmd->setConfiguration('instanceId', $instanceID);
												$cmd->setConfiguration('class', $ccId);
												$cmd->setConfiguration('value', 'data[' . $index . '].Set(#slider#)');
												$cmd->setSubType('slider');
												if (is_object($cmd_info)) {
													$cmd->setValue($cmd_info->getId());
												}
												$cmd->save();
												break;
											case 'float':
												$cmd = new openzwaveCmd();
												$cmd->setType('action');
												$cmd->setEqLogic_id($this->getId());
												$cmd->setName($cmd_name);
												$cmd->setConfiguration('instanceId', $instanceID);
												$cmd->setConfiguration('class', $ccId);
												$cmd->setConfiguration('value', 'data[' . $index . '].Set(#slider#)');
												$cmd->setSubType('slider');
												if (is_object($cmd_info)) {
													$cmd->setValue($cmd_info->getId());
												}
												$cmd->save();
												break;
											case 'List':
												foreach (explode(';', $data['data_items']) as $value) {
													$cmd = new openzwaveCmd();
													$cmd->setType('action');
													$cmd->setEqLogic_id($this->getId());
													$cmd->setName($value);
													$cmd->setConfiguration('instanceId', $instanceID);
													$cmd->setConfiguration('class', $ccId);
													$cmd->setConfiguration('value', 'data[' . $index . '].Set(' . $value . ')');
													$cmd->setSubType('other');
													$cmd->save();
												}
												break;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		nodejs::pushUpdate('jeedom::alert', array(
			'level' => 'warning',
			'message' => '',
		));
	}

}

class openzwaveCmd extends cmd {
	/*     * ***********************Methode static*************************** */

	public static function handleResult($_val) {
		if (!is_array($_val)) {
			return '';
		}
		if (!isset($_val['value'])) {
			return '';
		}
		$value = $_val['value'];
		switch ($_val['type']) {
			case 'float':
				$value = round(floatval($value), 2);
				break;
			case 'int':
				$value = intval($value);
				break;
			case 'bool':
				if ($value === true || $value == 'true') {
					$value = 1;
				} else {
					$value = 0;
				}
				break;
			case 'binary':
				if (is_array($_val['value'])) {
					$value = '';
					foreach ($_val['value'] as $ascii) {
						if ($ascii != 0) {
							$value .= $ascii;
						}
					}
				}
				break;
		}
		return $value;
	}

	/*     * *********************Methode d'instance************************* */

	public function handleUpdateValue($_result) {
		if (isset($_result['val'])) {
			$value = self::handleResult($_result['val']);
			if (isset($_result['val']['updateTime'])) {
				$this->setCollectDate(date('Y-m-d H:i:s', $_result['val']['updateTime']));
			}
		} else {
			$value = self::handleResult($_result);
			if (isset($_result['updateTime'])) {
				$this->setCollectDate(date('Y-m-d H:i:s', $_result['updateTime']));
			}
		}
		if ($value === '') {
			try {
				$value = $this->execute();
				$this->setCollectDate('');
			} catch (Exception $e) {
				return;
			}
		}
		$this->event($value, 0);
	}

	public function setRGBColor($_color) {
		if ($_color == '') {
			throw new Exception('Couleur non définie');
		}
		$eqLogic = $this->getEqLogic();
		$request = '/ZWaveAPI/Run/devices[' . $eqLogic->getLogicalId() . ']';

		$hex = str_replace("#", "", $_color);
		if (strlen($hex) == 3) {
			$r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
			$g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
			$b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
		} else {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		}

		//Convertion pour sur une echelle de 0-99
		$r = ($r / 255) * 99;
		$g = ($g / 255) * 99;
		$b = ($b / 255) * 99;

		if ($eqLogic->getConfiguration('manufacturer_id') == 271 && $eqLogic->getConfiguration('product_type') == 2304 && $eqLogic->getConfiguration('product_id') == 4096) {
			/* Set GREEN color */
			openzwave::callRazberry($request . '.instances[3].commandClasses[0x26].data[0].Set(' . str_replace(',', '%2C', $g) . ')', $eqLogic->getConfiguration('serverID', 1));
			/* Set BLUE color */
			openzwave::callRazberry($request . '.instances[4].commandClasses[0x26].data[0].Set(' . str_replace(',', '%2C', $b) . ')', $eqLogic->getConfiguration('serverID', 1));
			/* Set RED color */
			openzwave::callRazberry($request . '.instances[2].commandClasses[0x26].data[0].Set(' . str_replace(',', '%2C', $r) . ')', $eqLogic->getConfiguration('serverID', 1));
		} else {
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x33].data[0].Set(0)', $eqLogic->getConfiguration('serverID', 1));
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x33].data[1].Set(0)', $eqLogic->getConfiguration('serverID', 1));
			/* Set GREEN color */
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x33].data[5].Set' . str_replace(',', '%2C', $g) . ')', $eqLogic->getConfiguration('serverID', 1));
			/* Set BLUE color */
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x33].data[4].Set(' . str_replace(',', '%2C', $b) . ')', $eqLogic->getConfiguration('serverID', 1));
			/* Set RED color */
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x33].data[2].Set(' . str_replace(',', '%2C', $r) . ')', $eqLogic->getConfiguration('serverID', 1));
			openzwave::callRazberry($request . '.instances[0].commandClasses[0x26].data[0].Set(255)', $eqLogic->getConfiguration('serverID', 1));
		}
		return true;
	}

	public function getPilotWire() {
		$eqLogic = $this->getEqLogic();
		$request = '/ZWaveAPI/Run/devices[' . $this->getEqLogic()->getLogicalId() . ']';
		$instancesId = explode('&&', $this->getConfiguration('instanceId'));
		if (!isset($instancesId[0])) {
			$instancesId[0] = 0;
		}
		if (!isset($instancesId[1])) {
			$instancesId[1] = 1;
		}
		$info1 = self::handleResult(openzwave::callRazberry($request . '.instances[' . $instancesId[0] . '].commandClasses[0x25].data[0].val', $eqLogic->getConfiguration('serverID', 1)));
		$info2 = self::handleResult(openzwave::callRazberry($request . '.instances[' . $instancesId[1] . '].commandClasses[0x25].data[0].val', $eqLogic->getConfiguration('serverID', 1)));
		return intval($info1) * 2 + intval($info2);
	}

	public function preSave() {
		if ($this->getConfiguration('instanceId') === '') {
			$this->setConfiguration('instanceId', '0');
		}
		if (strpos($this->getConfiguration('class'), '0x') === false) {
			$this->setConfiguration('class', '0x' . dechex($this->getConfiguration('class')));
		}
		$this->setLogicalId($this->getConfiguration('instanceId') . '.' . $this->getConfiguration('class'));
		$this->setEventOnly(1);
	}

	public function sendZwaveResquest($_url) {
		$eqLogic = $this->getEqLogic();
		$result = openzwave::callRazberry($_url, $eqLogic->getConfiguration('serverID', 1));
		if ($this->getType() == 'action') {
			return;
		}
		if (is_array($result)) {
			$value = self::handleResult($result);
			if (isset($result['updateTime'])) {
				$this->setCollectDate(date('Y-m-d H:i:s', $result['updateTime']));
			}
		} else {
			$value = $result;
			if ($value === true || $value == 'true') {
				return 1;
			}
			if ($value === false || $value == 'false') {
				return 0;
			}
			if (is_numeric($value)) {
				return round($value, 1);
			}
		}
		return $value;
	}

	public function postInsert() {
		if ($this->getType() == 'info') {
			$this->event($this->execute());
		}
	}

	public function execute($_options = null) {
		if ($this->getLogicalId() == 'pilotWire' || $this->getConfiguration('value') == 'pilotWire') {
			return $this->getPilotWire();
		}
		$value = $this->getConfiguration('value');
		$request = '/ZWaveAPI/Run/devices[' . $this->getEqLogic()->getLogicalId() . ']';
		switch ($this->getType()) {
			case 'action':
				switch ($this->getSubType()) {
				case 'slider':
						$value = str_replace('#slider#', $_options['slider'], $value);
						break;
				case 'color':
						$value = str_replace('#color#', $_options['color'], $value);
						return $this->setRGBColor($value);
				}
				break;
		}
		if (strpos($this->getConfiguration('instanceId'), '&&') !== false || strpos($value, '&&') !== false) {
			$lastInstanceId = $this->getConfiguration('instanceId');
			$instancesId = explode('&&', $this->getConfiguration('instanceId'));
			$lastValue = $value;
			$values = explode('&&', $value);
			$totalRequest = max(count($values), count($instancesId));
			$result = '';
			for ($i = 0; $i < $totalRequest; $i++) {
				if (strpos($values[$i], 'sleep(') !== false) {
					$duration = str_replace(array('sleep(', ')'), '', $values[$i]);
					if ($duration != '' && is_numeric($duration)) {
						sleep($duration);
					}
				} else {
					$request_http = $request;
					$value = $lastValue;
					if (isset($values[$i]) && $values[$i] != '') {
						$value = $values[$i];
						$lastValue = $value;
					}

					$instanceId = $lastInstanceId;
					if (isset($instancesId[$i])) {
						$instanceId = $instancesId[$i];
						$lastInstanceId = $instanceId;
					}
					if ($instanceId != '') {
						$request_http .= '.instances[' . $instanceId . ']';
					} else {
						$request_http .= '.instances[0]';
					}
					$request_http .= '.commandClasses[' . $this->getConfiguration('class') . ']';
					$request_http .= '.' . $value;
					$result .= $this->sendZwaveResquest($request_http);
				}
			}
			return $result;
		}
		if ($this->getConfiguration('instanceId') != '' && ($this->getConfiguration('class') != '0x70')) {
			$request .= '.instances[' . $this->getConfiguration('instanceId') . ']';
		} else {
			$request .= '.instances[0]';
		}
		$request .= '.commandClasses[' . $this->getConfiguration('class') . ']';
		$request .= '.' . str_replace(',', '%2C', $value);
		return $this->sendZwaveResquest($request);
	}

}
