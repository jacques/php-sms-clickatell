<?php
/* +----------------------------------------------------------------------+
 * | SMS_Clickatell                                                       |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 2002-2005 Jacques Marneweck                            |
 * +----------------------------------------------------------------------+
 * | This source file is subject to version 3.0 of the PHP license,       |
 * | that is bundled with this package in the file LICENSE, and is        |
 * | available at through the world-wide-web at                           |
 * | http://www.php.net/license/3_0.txt.                                  |
 * | If you did not receive a copy of the PHP license and are unable to   |
 * | obtain it through the world-wide-web, please send a note to          |
 * | license@php.net so we can mail you a copy immediately.               |
 * +----------------------------------------------------------------------+
 * | Authors: Jacques Marneweck <jacques@php.net>                         |
 * +----------------------------------------------------------------------+
 */

require_once 'PEAR.php';

/**
 * PHP Interface into the Clickatell API
 *
 * @author	Jacques Marneweck <jacques@php.net>
 * @copyright	2002-2005 Jacques Marneweck
 * @license	http://www.php.net/license/3_0.txt	PHP License
 * @version	$Id: Clickatell.php,v 1.31 2005/12/18 14:24:08 jacques Exp $
 * @access	public
 * @package	SMS
 */

class SMS_Clickatell {
	/**
	 * Clickatell API Server
	 * @var string
	 */
	var $_api_server = "https://api.clickatell.com";

	/**
	 * Clickatell API Server Session ID
	 * @var string
	 */
	var $_session_id = null;

	/**
	 * Username from Clickatell used for authentication purposes
	 * @var string
	 */
	var $_username = null;

	/**
	 * Password for Clickatell Usernaem
	 * @var string
	 */
	var $_password = null;

	/**
	 * Clickatell API Server ID
	 * @var string
	 */
	var $_api_id = null;

	/**
	 * Curl handle resource id
	 */
	var $_ch;

	/**
	 * Temporary file resource id
	 * @var	resource
	 */
	var $_fp;

	/**
	 * Error codes generated by Clickatell Gateway
	 * @var array
	 */
	var $_errors = array (
		'001' => 'Authentication failed',
		'002' => 'Unknown username or password',
		'003' => 'Session ID expired',
		'004' => 'Account frozen',
		'005' => 'Missing session ID',
		'007' => 'IP lockdown violation',
		'101' => 'Invalid or missing parameters',
		'102' => 'Invalid UDH. (User Data Header)',
		'103' => 'Unknown apismgid (API Message ID)',
		'104' => 'Unknown climsgid (Client Message ID)',
		'105' => 'Invalid Destination Address',
		'106' => 'Invalid Source Address',
		'107' => 'Empty message',
		'108' => 'Invalid or missing api_id',
		'109' => 'Missing message ID',
		'110' => 'Error with email message',
		'111' => 'Invalid Protocol',
		'112' => 'Invalid msg_type',
		'113' => 'Max message parts exceeded',
		'114' => 'Cannot route message',
		'115' => 'Message Expired',
		'116' => 'Invalid Unicode Data',
		'201' => 'Invalid batch ID',
		'202' => 'No batch template',
		'301' => 'No credit left',
		'302' => 'Max allowed credit'
	);

	/**
	 * Message status
	 *
	 * @var array
	 */
	var $_message_status = array (
		'001' => 'Message unknown',
		'002' => 'Message queued',
		'003' => 'Delivered',
		'004' => 'Received by recipient',
		'005' => 'Error with message',
		'006' => 'User cancelled message delivery',
		'007' => 'Error delivering message',
		'008' => 'OK',
		'009' => 'Routing error',
		'010' => 'Message expired',
		'011' => 'Message queued for later delivery',
		'012' => 'Out of credit'
	);

	var $_msg_types = array (
		'SMS_TEXT',
		'SMS_FLASH',
		'SMS_NOKIA_OLOGO',
		'SMS_NOKIA_GLOGO',
		'SMS_NOKIA_PICTURE',
		'SMS_NOKIA_RINGTONE',
		'SMS_NOKIA_RTTL',
		'SMS_NOKIA_CLEAN',
		'SMS_NOKIA_VCARD',
		'SMS_NOKIA_VCAL'
	);

	/**
	 * Authenticate to the Clickatell API Server.
	 *
	 * @return mixed true on sucess or PEAR_Error object
	 * @access public
	 * @since 1.1
	 */
	function auth () {
		$_url = $this->_api_server . "/http/auth";
		$_post_data = "user=" . $this->_username . "&password=" . $this->_password . "&api_id=" . $this->_api_id;

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$sess = split(":", $response['data']);

		$this->_session_id = trim($sess[1]);

		if ($sess[0] == "OK") {
			return (true);
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Delete message queued by Clickatell which has not been passed
	 * onto the SMSC.
	 *
	 * @access	public
	 * @since	1.14
	 * @see		http://www.clickatell.com/downloads/Clickatell_http_2.2.2.pdf
	 */
	function deletemsg ($apimsgid) {
		$_url = $this->_api_server . "/http/delmsg";
		$_post_data = "session_id=" . $this->_session_id . "&apimsgid=" . $apimsgid;

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$sess = split(":", $response['data']);

		$deleted = preg_split("/[\s:]+/", $response['data']);
		if ($deleted[0] == "ID") {
			return (array($deleted[1], $deleted[3]));
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Query balance of remaining SMS credits
	 *
	 * @access	public
	 * @since	1.9
	 */
	function getbalance () {
		$_url = $this->_api_server . "/http/getbalance";
		$_post_data = "session_id=" . $this->_session_id;

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$send = split(":", $response['data']);

		if ($send[0] == "Credit") {
			return trim($send[1]);
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Determine the cost of the message which was sent
	 *
	 * @param	string	api_msg_id
	 * @since	1.20
	 * @access  public
	 */
	function getmsgcharge ($apimsgid) {
		$_url = $this->_api_server . "/http/getmsgcharge";
		$_post_data = "session_id=" . $this->_session_id . "&apimsgid=" . trim($apimsgid);

		if (strlen($apimsgid) < 32 || strlen($apimsgid) > 32) {
			return PEAR::raiseError('Invalid API Message Id');
		}

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$charge = preg_split("/[\s:]+/", $response['data']);

		if ($charge[2] == "charge") {
			return (array($charge[3], $charge[5]));
		}

		/**
		 * Return charge and message status
		 */
		return (array($charge[3], $charge[5]));
	}

	/**
	 * Initilaise the Clicaktell SMS Class
	 *
	 * <code>
	 * <?php
	 * require_once 'SMS/Clickatell.php';
	 *
	 * $sms = new SMS_Clickatell;
 	 * $res = $sms->init (
	 *	array (
	 *		'user' => 'username',
	 *		'pass' => 'password',
	 *		'api_id' => '12345'
	 * 	)
	 * );
	 * if (PEAR::isError($res)) {
	 * 	die ($res->getMessage());
	 * }
	 * $res = $sms->auth();
	 * if (PEAR::isError($res)) {
	 *	die ($res->getMessage());
	 * }
	 * ?>
	 * </code>
	 *
	 * @param   array   array of parameters
	 * @return  mixed   void if valid else a PEAR Error
	 * @access	public
	 * @since	1.9
	 */
	function init ($_params = array()) {
		if (is_array($_params)) {
			if (!isset($_params['user'])) {
				return PEAR::raiseError('Missing parameter user.');
			}

			if (!isset($_params['pass'])) {
				return PEAR::raiseError('Missing parameter pass.');
			}

			if (!isset($_params['api_id'])) {
				return PEAR::raiseError('Missing parameter api_id.');
			}

			if (!is_numeric($_params['api_id'])) {
				return PEAR::raiseError('Invalid api_id.');
			}

			$this->_username = $_params['user'];
			$this->_password = $_params['pass'];
			$this->_api_id = $_params['api_id'];
		} else {
			return PEAR::raiseError('You need to specify paramaters for authenticating to Clickatell.');
		}
	}

	/**
	 * Keep our session to the Clickatell API Server valid.
	 *
	 * @return mixed true on sucess or PEAR_Error object
	 * @access public
	 * @since 1.1
	 */
	function ping () {
		$_url = $this->_api_server . "/http/ping";
		$_post_data = "session_id=" . $this->_session_id;

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$sess = split(":", $response['data']);

		if ($sess[0] == "OK") {
			return (true);
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Query message status
	 *
	 * @param string spimsgid generated by Clickatell API
	 * @return string message status or PEAR_Error object 
	 * @access public
	 * @since 1.5
	 */

	function querymsg ($apimsgid) {
		$_url = $this->_api_server . "/http/querymsg";
		$_post_data = "session_id=" . $this->_session_id . "&apimsgid=" . $apimsgid;

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$status = split(" ", $response['data']);

		if ($status[0] == "ID:") {
			return (trim($status[3]));
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Send an SMS Message via the Clickatell API Server
	 *
	 * @param array database result set
	 *
	 * @return mixed true on sucess or PEAR_Error object
	 * @access public
	 * @since 1.2
	 */
	function sendmsg ($_msg) {
		$_url = $this->_api_server . "/http/sendmsg";
		$_post_data = "session_id=" . $this->_session_id . "&to=" . $_msg['to'] . "&text=" . urlencode ($_msg['text']) . "&callback=3&deliv_ack=1&from=" . $_msg['from'] . "&climsgid=" . $_msg['climsgid'];

		if (!in_array($_msg['msg_type'], $this->_msg_types)) {
			return PEAR::raiseError("Invalid message type. Message ID is " . $_msg['id']);
		}

		if ($_msg['msg_type'] != "SMS_TEXT") {
			$_post_data .= "&msg_type=" . $_msg['msg_type'];
		}

		/**
		 * Check if we are using a queue when sending as each account
		 * with Clickatell is assigned three queues namely 1, 2 and 3.
		 */
		if (isset($_msg['queue']) && is_numeric($_msg['queue'])) {
			if (in_array($_msg['queue'], range(1, 3))) {
				$_post_data .= "&queue=" . $_msg['queue'];
			}
		}

		$req_feat = 0;
		/**
		 * Normal text message
		 */
		if ($_msg['msg_type'] == 'SMS_TEXT') {
			$req_feat += 1;
		}
		/**
		 * We set the sender id is alpha numeric or numeric
		 * then we change the sender from data.
		 */
		if (is_numeric($_msg['from'])) {
			$req_feat += 32;
		} elseif (is_string($_msg['from'])) {
			$req_feat += 16;
		}
		/**
		 * Flash Messaging
		 */
		if ($_msg['msg_type'] == 'SMS_FLASH') {
			$req_feat += 512;
		}
		/**
		 * Delivery Acknowledgments
		 */
		$req_feat += 8192;

		if (!empty($req_feat)) {
			$_post_data .= "&req_feat=" . $req_feat;
		}

		/**
		 * Must we escalate message delivery if message is stuck in
		 * the queue at Clickatell?
		 */
		if (isset($_msg['escalate']) && !empty($_msg['escalate'])) {
			if (is_numeric($_msg['escalate'])) {
				if (in_array($_msg['escalate'], range(1, 2))) {
					$_post_data .= "&escalate=" . $_msg['escalate'];
				}
			}
		}

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$send = split(":", $response['data']);

		if ($send[0] == "ID") {
			return array ("1", trim($send[1]));
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Spend a clickatell voucher which can be used for topping up of
	 * sub user accounts.
	 *
	 * @param	string	voucher number
	 * @access	public
	 * @since	1.22
	 * @see		http://www.clickatell.com/downloads/Clickatell_http_2.2.4.pdf
	 */
	function tokenpay ($voucher) {
		$_url = $this->_api_server . "/http/token_pay";
		$_post_data = "session_id=" . $this->_session_id . "&token=" . trim($voucher);

		if (!is_numeric($voucher) || strlen($voucher) < 16 || strlen($voucher) > 16) {
			return (PEAR::raiseError('Invalid voucher number'));
		}

		$response = $this->_curl($_url, $_post_data);
		if (PEAR::isError($response)) {
			return $response;
		}
		$sess = split(":", $response['data']);

		$paid = preg_split("/[\s:]+/", $response['data']);
		if ($paid[0] == "OK") {
			return true; 
		} else {
			return PEAR::raiseError($response['data']);
		}
	}

	/**
	 * Perform curl stuff
	 *
	 * @param   string  URL to call
	 * @param   string  HTTP Post Data
	 * @return  mixed   HTTP response body or PEAR Error Object
	 * @access	private
	 */
	function _curl ($url, $post_data) {
		/**
		 * Reuse the curl handle
		 */
		if (!is_resource($this->_ch)) {
			$this->_ch = curl_init();
			if (!$this->_ch || !is_resource($this->_ch)) {
				return PEAR::raiseError('Cannot initialise a new curl handle.');
			}
			curl_setopt($this->_ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($this->_ch, CURLOPT_VERBOSE, 1);
			curl_setopt($this->_ch, CURLOPT_FAILONERROR, 1);
			curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($this->_ch, CURLOPT_COOKIEJAR, "/dev/null");
			curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, 2);
			curl_setopt($this->_ch, CURLOPT_USERAGENT, 'SMS_Clickatell 0.6.1 - http://www.powertrip.co.za/PEAR/SMS_Clickatell/');
		}

		$this->_fp = tmpfile();

		curl_setopt($this->_ch, CURLOPT_URL, $url);
		curl_setopt($this->_ch, CURLOPT_POST, 1);
		curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($this->_ch, CURLOPT_FILE, $this->_fp);

		$status = curl_exec($this->_ch);
		$response['http_code'] = curl_getinfo($this->_ch, CURLINFO_HTTP_CODE);

		if (empty($response['http_code'])) {
			return PEAR::raiseError ('No HTTP Status Code was returned.');
		} elseif ($response['http_code'] === 0) {
			return PEAR::raiseError ('Cannot connect to the Clickatell API Server.');
		}

		if ($status) {
			$response['error'] = curl_error($this->_ch);
			$response['errno'] = curl_errno($this->_ch);
		}

		rewind($this->_fp);

		$pairs = "";
		while ($str = fgets($this->_fp, 4096)) {
			$pairs .= $str;
		}
		fclose($this->_fp);

		$response['data'] = $pairs;
		unset($pairs);
		asort($response);

		return ($response);
	}
}

/* vim: set noet ts=4 sw=4 ft=php: : */
