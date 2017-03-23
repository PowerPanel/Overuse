<?php
/**
 * @author PowerPanel
 * @copyright 2016 PowerPanel BV
 * @since  18-8-2016
 * @version 1.1
 * Filename:    resourcescriptPlesk.php
 * Based on : https://github.com/plesk/api-examples/tree/master/php
 * Description: class for getting stats back from plesk
 */

//Execute script
$vps = ''; //hostname without http:// or https://
$username = '';
$password = '';
$webhook_url = ''; //get the url from PowerPanel for more information read http://support.powerpanel.io/overuse/ ‎

$plesk = new PleskStats($vps);
$plesk->setCredentials($username, $password);

$stats = $plesk->getStats();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_TIMEOUT, 600); //10min
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $stats);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

class PleskStats{

    private $_host;
    private $_port;
    private $_protocol;
    private $_login;
    private $_password;
    private $_secretKey;

    private $domainnames;

    private function setDomainnames($value){
        $this->domainnames = $value;
    }

    private function getDomainnames(){
        return $this->domainnames;
    }

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
    }
    /**
     * Setup credentials for authentication
     *
     * @param string $login
     * @param string $password
     */
    public function setCredentials($login, $password)
    {
        $this->_login = $login;
        $this->_password = $password;
    }
    /**
     * Define secret key for alternative authentication
     *
     * @param string $secretKey
     */
    public function setSecretKey($secretKey)
    {
        $this->_secretKey = $secretKey;
    }

    /**
     * Get the statistics from the webserver
     */
public function getStats()
	{
		$resultarray = array();
		$temparray = array();
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
		<packet version ="1.6.3.5">
			<webspace>
				<get>
				<filter/>
				<dataset>
					<hosting/>
					<stat/>
				</dataset>
				</get>
			</webspace>
		</packet>';

		$response = $this->curlRequest($xml);
		$xml = simplexml_load_string($response);
		$json = json_encode($xml);
		$array = json_decode($json, true);

		if (isset($array["system"]["status"]) && $array["system"]["status"] == "error") {
			$returnarray = array(
				'status' => 'error',
				'message' => $array["system"]["errtext"]
			);
			return $returnarray;
		} else {
			$resultarray["status"] = 'ok';
			$result = $array["webspace"]["get"]["result"];


			// Result with a single row, create array
			if (isset($result['status'])) {
				$result = array(0 => $result);
			}

			foreach ($result as $site) {
				$temparray["domain"] = $site['data']["gen_info"]["name"];
				$temparray["traffic"] = $site['data']["stat"]["traffic"];
				$temparray["traffic_prevday"] = $site['data']["stat"]["traffic_prevday"];
				$temparray["subdomains"] = $site['data']["stat"]["subdom"];
				$temparray["emailboxen"] = $site['data']["stat"]["box"];
				$temparray["redirect"] = $site['data']["stat"]["redir"];
				$temparray["webusers"] = $site['data']["stat"]["wu"];
				$temparray["mailgroups"] = $site['data']["stat"]["mg"];
				$temparray["responders"] = $site['data']["stat"]["resp"];
				$temparray["maillists"] = $site['data']["stat"]["maillists"];
				$temparray["databases"] = $site['data']["stat"]["db"];
				$temparray["mssyl_databases"] = $site['data']["stat"]["mssql_db"];
				$temparray["webapps"] = $site['data']["stat"]["webapps"];
				$temparray["domains"] = $site['data']["stat"]["domains"];
				$temparray["sites"] = $site['data']["stat"]["sites"];
				$temparray["disk_usage"] = $site['data']["gen_info"]["real_size"];
				$temparray["log_usage"] = '';
				$temparray["quota"] = '';
				$temparray["ssl"] = '';
				$temparray["cgi"] = '';
				$temparray["php"] = '';
				$resultarray["result"][] = $temparray;
			}
			return json_encode($resultarray);
		}
	}

    private function curlRequest($data){
        $request = $data;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "$this->_protocol://$this->_host:$this->_port/enterprise/control/agent.php");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->_getHeaders());
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }

    private function _getHeaders()
    {
        $headers = array(
            "Content-Type: text/xml",
            "HTTP_PRETTY_PRINT: TRUE",
        );
        if ($this->_secretKey) {
            $headers[] = "KEY: $this->_secretKey";
        } else {
            $headers[] = "HTTP_AUTH_LOGIN: $this->_login";
            $headers[] = "HTTP_AUTH_PASSWD: $this->_password";
        }
        return $headers;
    }
}
