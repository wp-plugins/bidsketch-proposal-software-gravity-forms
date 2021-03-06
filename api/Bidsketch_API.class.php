<?php

/**
 * Bidsketch API
 * @author Svetoslav Marinov (SLAVI)
 */
Class Bidsketch_API_Gravity {
	private $version = "2";
    private $api_token = '';
    private $error_code = '';
    private $error_message = '';
    private $user_agent = "Gravity Forms Bidsketch Add-On (bidsketch.com)";
    private $api_end_point = 'https://bidsketch.com/api/v1/';
    //private $api_end_point = 'http://localhost/echo/'; // dbg only
    private $recent_req_info = array(); // holds dbg info from a recent request
    private $timeout = 30;
    private $connect_timeout = 30;
    private $debug = false; // Requests headers and other info to be fetched from the request. Command-line windows will show info in STDERR

    const GET  = 1;
    const POST = 2;
    const DELETE = 3;
    const PUT = 4;

    /**
     * Accepts the token and saves it internally.
     * 
     * @param string $api_token e.g. qsor48ughrjufyu2dqcraasfa1212424
     * @throws Exception
     */
    public function __construct($api_token) {
        $api_token = trim($api_token);

        if (empty($api_token) || !preg_match('#^[\w-]+$#si', $api_token)) {
            throw new Exception("Missing or invalid Bidsketch API token.");
        }

        $this->api_token = $api_token;
    }

    /**
     * Requests the accounts for the given account.
     * Parses the response JSON and returns an array which contains: id, name, created_at etc
     * @param void
     * @return bool/array
     */
    public function get_clients() {
        $res = $this->make_request($this->api_end_point . 'clients.json');

        if (!empty($res['buffer'])) {
            $raw_json = json_decode($res['buffer'], true);
        }

        $data = empty($raw_json) ? array() : $raw_json;

        return $data;
    }

    /**
     * Sends a request to add a subscriber and returns its record or false
     * 
     * @param array $params
     * @param array/bool $account
     */
    public function add_client($params) {
        if (empty($params['email'])) {
            throw new Exception("Email not specified");
        }

        $params['first_name'] = empty($params['first_name']) ? 'No First Name' : $params['first_name'];
        $params['last_name'] = empty($params['last_name']) ? 'No Last Name' : $params['last_name'];
        
        $res = $this->make_request($this->api_end_point . "clients.json", $params, self::POST);

        if (!empty($res['buffer'])) {
            $raw_json = json_decode($res['buffer'], true);
        }

        $data = empty($raw_json) ? array() : $raw_json;

        return $data;
    }

    /**
     * 
     * @param array $params
     * @param array $params
     */
    public function fetch_subscriber($params) {
        if (empty($params['account_id'])) {
            throw new Exception("Account ID not specified");
        }

        $account_id = $params['account_id'];
        unset($params['account_id']); // clear it from the params

        if (!empty($params['subscriber_id'])) {
            $subscriber_id = $params['subscriber_id'];
            unset($params['subscriber_id']); // clear it from the params
        } elseif (!empty($params['email'])) {
            $subscriber_id = $params['email'];
            unset($params['email']); // clear it from the params
        } else {
            throw new Exception("Subscriber ID or Email was not specified. You must specify either Subscriber ID or Email.");
        }

        $subscriber_id = urlencode($subscriber_id);

        $api_action = "$account_id/subscribers/$subscriber_id";
        $url = $this->api_end_point . $api_action;

        $res = $this->make_request($url);

        if (!empty($res['buffer'])) {
            $raw_json = json_decode($res['buffer'], true);
        }

        $data = empty($raw_json)
            ? false
            : empty($raw_json['subscribers'])
                ? array()
                : $raw_json['subscribers'][0];

        return $data;
    }

    /**
     *
     * @param string $url
     * @param array $params
     * @param int $req_method
     * @return type
     * @throws Exception
     */
    public function make_request($url, $params = array(), $req_method = self::GET) {
        if (!function_exists('curl_init')) {
            throw new Exception("Cannot find cURL php extension or it's not loaded.");
        }

        $ch = curl_init();

        if ($this->debug) {
            //curl_setopt($ch, CURLOPT_HEADER, true);
            // TRUE to output verbose information. Writes output to STDERR, or the file specified using CURLOPT_STDERR.
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
        //curl_setopt($ch, CURLOPT_USERPWD, $this->api_token . ":" . ''); // no pwd
        curl_setopt($ch, CURLOPT_USERAGENT, empty($params['user_agent']) ? $this->user_agent : $params['user_agent']);

        if ($req_method == self::POST) { // We want post but no params to supply. Probably we have a nice link structure which includes all the info.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        } elseif ($req_method == self::DELETE) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        } elseif ($req_method == self::PUT) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        }

        if (!empty($params)) {
            if ((isset($params['__req']) && strtolower($params['__req']) == 'get')
                    || $req_method == self::GET) {
                unset($params['__req']);
                $url .= '?' . http_build_query($params);
            } elseif ($req_method == self::POST || $req_method == self::DELETE) {
                $params_str = is_array($params) ? json_encode($params) : $params;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params_str);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept:application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/vnd.api+json',
            sprintf('Authorization: Token token="%s"', $this->api_token ),
        ));

        $buffer = curl_exec($ch);
        $status = !empty($buffer);
        
        $data = array(
            'url'       => $url,
            'params'    => $params,
            'status'    => $status,
            'error'     => empty($buffer) ? curl_error($ch) : '',
            'error_no'  => empty($buffer) ? curl_errno($ch) : '',
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'debug'     => $this->debug ? curl_getinfo($ch) : '',
        );

        curl_close($ch);

        // remove some weird headers HTTP/1.1 100 Continue or HTTP/1.1 200 OK
        $buffer = preg_replace('#HTTP/[\d.]+\s+\d+\s+\w+[\r\n]+#si', '', $buffer);
        $buffer = trim($buffer);
        $data['buffer'] = $buffer;

        $this->_parse_error($data);
        $this->recent_req_info = $data;

        return $data;
    }

    /**
     * This returns the RAW data from the each request that has been sent (if any).
     * @return arraay of arrays
     */
    public function get_request_info() {
        return $this->recent_req_info;
    }

    /**
     * Retruns whatever was accumultaed in error_message
     * @param string
     */
    public function get_error_message() {
        return $this->error_message;
    }
    
    /**
     * Retruns whatever was accumultaed in error_code
     * @return string
     */
    public function get_error_code() {
        return $this->error_code;
    }

    /**
     * Some keys are removed from the params so they don't get send with the other data to Bidsketch.
     *
     * @param array $params
     * @param array
     */
    public function _parse_error($res) {
        if (empty($res['http_code']) || $res['http_code'] >= 200 && $res['http_code'] <= 299) {
            return true;
        }

        if (empty($res['buffer'])) {
            $this->error_message = "Response from the server.";
            $this->error_code = $res['http_code'];
        } elseif (!empty($res['buffer'])) {
            $json_arr = json_decode($res['buffer'], true);

            // The JSON error response looks like this.
            /*
             {
                "errors": [{
                  "code": "authorization_error",
                  "message": "You are not authorized to access this resource"
                }]
              }
             */
            if (!empty($json_arr['errors'])) { // JSON
                $messages = $error_codes = array();
                
                foreach ($json_arr['errors'] as $rec) {
                    $messages[] = $rec['message'];
                    $error_codes[] = $rec['code'];
                }

                $this->error_code = join(", ", $error_codes);
                $this->error_message = join("\n", $messages);
            } else { // There's no JSON in the reply so we'll extract the message from the HTML page by removing the HTML.
                $msg = $res['buffer'];
                
                $msg = preg_replace('#.*?<body[^>]*>#si', '', $msg);
                $msg = preg_replace('#</body[^>]*>.*#si', '', $msg);
                $msg = strip_tags($msg);
                $msg = preg_replace('#[\r\n]#si', '', $msg);
                $msg = preg_replace('#\s+#si', ' ', $msg);
                $msg = trim($msg);
                $msg = substr($msg, 0, 256);

                $this->error_code = $res['http_code'];
                $this->error_message = $msg;
            }
        } elseif ($res['http_code'] >= 400 || $res['http_code'] <= 499) {
            $this->error_message = "Not authorized.";
            $this->error_code = $res['http_code'];
        } elseif ($res['http_code'] >= 500 || $res['http_code'] <= 599) {
            $this->error_message = "Internal Server Error.";
            $this->error_code = $res['http_code'];
        }
    }

    // tmp
    public function __call($method, $args) {
        return array();
    }
}