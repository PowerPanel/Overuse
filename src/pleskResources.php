<?php
/**
 * @author PowerPanel
 * @copyright 2018 PowerPanel BV
 * @since  18-8-2016
 * @version 1.3
 * Filename:    resourcescriptPlesk.php
 * Based on : https://github.com/plesk/api-examples/tree/master/php
 * Description: class for getting stats back from plesk
 */

//Execute script
$hostname = ''; //hostname without http:// or https://. You can use gethostname();
$username = ''; //admin
$password = '';
$secret_key = ''; //Leave username + password empty in case secret key is used. Command: `plesk bin secret_key -c -ip-address 127.0.0.1 -description "PowerPanel Resource Hook"`

$webhook_url = ''; //Get the unique webhook URL at the PowerPanel Plesk plugin settings. For more information read: https://powerpanel.io/support/monitor-charge-overusage


// ====== Don't edit under this line

$plesk = new PleskStats($hostname);
$plesk->setCredentials($username, $password, $secret_key);
$plesk->setPowerPanelHook($webhook_url);

$stats = $plesk->getStats();
$plesk->sendToPowerPanel($stats);

class PleskStats {

	private $_host;
	private $_port;
	private $_protocol;
	private $_login;
	private $_password;
	private $_secretKey;

	/**
	 * Create client
	 *
	 * @param string $host
	 * @param int $port
	 * @param string $protocol
	 */
	public function __construct($host, $port = 8443, $protocol = 'https')
	{
		$this->_host = $host;
		$this->_port = $port;
		$this->_protocol = $protocol;

		$this->_webhook_url = '';
		$this->result_array = array();
	}
	/**
	 * Setup credentials for authentication
	 *
	 * @param string $login
	 * @param string $password
	 */
	public function setCredentials($login, $password, $secret_key) {
		$this->_login = $login;
		$this->_password = $password;
		$this->_secretKey = $secret_key;
	}
	/**
	 * Setup credentials for authentication
	 *
	 * @param string $login
	 * @param string $password
	 */
	public function setPowerPanelHook($webhook_url) {
		$this->_webhook_url = $webhook_url;
	}
	/**
	 * Define secret key for alternative authentication
	 *
	 * @param string $secretKey
	 */
	public function setSecretKey($secretKey) {
		$this->_secretKey = $secretKey;
	}

	/**
	 * Get the statistics from the webserver
	 */
	public function getStats() {
		$this->result_array = array();

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
		<packet version ="1.6.3.5">
			<webspace>
				<get>
				<filter/>
				<dataset>
					<stat/>
				</dataset>
				</get>
				<get_traffic>
					<filter/>
					<since_date>'. date('Y-m-01') .'</since_date>
				</get_traffic>
			</webspace>
		</packet>';

		$response = $this->curlRequest($xml);
		$plesk_response = json_decode(json_encode(simplexml_load_string($response)), true); // https://stackoverflow.com/a/19391553

		if(isset($plesk_response["system"]["status"]) && $plesk_response["system"]["status"] == "error") {
			$this->result_array = array(
				'status' => 'error',
				'message' => $plesk_response["system"]["errtext"]
			);
			return $this->result_array;
		}
		else {
			$this->result_array["status"] = 'ok';

			// Result with a single row, create array
			if(isset($plesk_response["webspace"]["get"]["result"]['status'])) {
				$plesk_response["webspace"]["get"]["result"] = array($plesk_response["webspace"]["get"]["result"]);
			}

			foreach($plesk_response["webspace"]["get"]["result"] as $site) {
				$this->result_array["result"][$site["id"]] = array(
					"id" => $site["id"],
					"domain" => $site["data"]["gen_info"]["name"],
					"traffic" => $site["data"]["stat"]["traffic"],
					"traffic_prevday" => $site["data"]["stat"]["traffic_prevday"],
					"subdomains" => $site["data"]["stat"]["subdom"],
					"emailboxes" => $site["data"]["stat"]["box"],
					"redirect" => $site["data"]["stat"]["redir"],
					"webusers" => $site["data"]["stat"]["wu"],
					"mailgroups" => $site["data"]["stat"]["mg"],
					"responders" => $site["data"]["stat"]["resp"],
					"maillists" => $site["data"]["stat"]["maillists"],
					"databases" => $site["data"]["stat"]["db"],
					"mssyl_databases" => $site["data"]["stat"]["mssql_db"],
					"webapps" => $site["data"]["stat"]["webapps"],
					"domains" => $site["data"]["stat"]["domains"],
					"sites" => $site["data"]["stat"]["sites"],
					"disk_usage" => $site["data"]["gen_info"]["real_size"],
					"log_usage" => '',
					"quota" => '',
					"ssl" => '',
					"cgi" => '',
					"php" => ''
				);
			}

			// Result with a single row, create array
			if(isset($plesk_response["webspace"]["get_traffic"]["result"]['status'])) {
				$plesk_response["webspace"]["get_traffic"]["result"] = array($plesk_response["webspace"]["get_traffic"]["result"]);
			}
			foreach($plesk_response["webspace"]["get_traffic"]["result"] as $site_traffic) {
				if(isset($this->result_array["result"][$site_traffic['id']]) && isset($site_traffic["status"]) && $site_traffic["status"] == 'ok') {

					// Result with a single row
					if(isset($site_traffic['traffic']['date'])) {
						//Update it to multiple rows for the foreach-loop
						$site_traffic['traffic'] = array($site_traffic['traffic']);
					}

					//We update the traffic to 0. We switched from daily -> month. v1.3 and above now use traffic_month + traffic_day
					$traffic = 0;
					$this->result_array["result"][ $site_traffic["id"] ]["traffic_month"] = $this->result_array["result"][ $site_traffic["id"] ]["traffic"];
					$this->result_array["result"][ $site_traffic["id"] ]['traffic'] = 0;

					if(isset($site_traffic['traffic'])) {
						foreach($site_traffic['traffic'] AS $site_traffic_data) {
							if($site_traffic_data['date'] == date('Y-m-d')) {
								// today
								unset($site_traffic_data['date']);
								$traffic = array_sum($site_traffic_data);
								// We found 'today', so we set the traffic for only today:
								$this->result_array["result"][ $site_traffic["id"] ]['traffic'] = $traffic;
							}
							unset($site_traffic_data['date']);
						}
					}
					$this->result_array["result"][ $site_traffic["id"] ]["traffic_day"] = $traffic;
				}
			}

			$this->result_array["plugin"] = 'Plesk';
			$this->result_array["date"] = date("Y-m-d H:i:s");
			//We reset the keys to 0 -> 100 for a better json
			$this->result_array["result"] = array_values($this->result_array["result"]);
			$this->result_array["version"] = 'v1.3'; // v1.2 and lower did not have any "version"

			return $this->result_array;
		}
	}

	private function curlRequest($request) {

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "$this->_protocol://$this->_host:$this->_port/enterprise/control/agent.php");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_getHeaders());
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	private function _getHeaders() {
		$headers = array(
			"Content-Type: text/xml",
			"HTTP_PRETTY_PRINT: TRUE",
		);
		if($this->_secretKey != '') {
			$headers[] = "KEY: $this->_secretKey";
		}
		else {
			$headers[] = "HTTP_AUTH_LOGIN: $this->_login";
			$headers[] = "HTTP_AUTH_PASSWD: $this->_password";
		}
		return $headers;
	}

	public function sendToPowerPanel($stats = array()) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->_webhook_url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 600); //10min
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stats));
		$resp = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($http_code != 200) {
			die("Failed to send it to PowerPanel. Got httpcode: ".$http_code);
		}
		if(isset($stats['status']) && $stats['status'] == 'error') {
			die($stats['message']);
		}
	}
}
