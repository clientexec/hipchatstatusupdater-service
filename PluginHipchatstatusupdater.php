<?php
require_once 'modules/clients/models/UserGateway.php';
require_once 'plugins/dashboard/teamstatus/TeamStatusGateway.php';
require_once 'modules/admin/models/ServicePlugin.php';
require_once 'library/CE/NE_PluginCollection.php';

/**
* @package Plugins
*/
class PluginHipchatstatusupdater extends ServicePlugin
{
    public $userGateway;
    public $teamStatusGateway;
    const STATUS_BAD_RESPONSE          = -1;
    const STATUS_OK                    = 200;
    const STATUS_BAD_REQUEST           = 400;

    function getVariables()
    {
        $variables = array(
            /*T*/'Plugin Name'/*/T*/   => array(
                'type'          => 'hidden',
                'description'   => /*T*/''/*/T*/,
                'value'         => /*T*/'HipChat Status Updater'/*/T*/,
            ),
            /*T*/'Enabled'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'When enabled, @status messages from the hipchat room will be converted to status messages in ClientExec.'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'HipChat Token'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Required. REST API Token'/*/T*/,
                'value'         => ''
            ),
            /*T*/'HipChat Roomid'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Required. ID of the room. '/*/T*/,
                'value'         => ''
            ),
            /*T*/'Run schedule - Minute'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '5',
                'helpid'        => '8',
            ),
            /*T*/'Run schedule - Hour'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Day'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Month'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Day of the week'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number in range 0-6 (0 is Sunday) or a 3 letter shortcut (e.g. sun)'/*/T*/,
                'value'         => '*',
            ),
        );

        return $variables;
    }

    function execute($lastInfo = "")
    {
        parent::execute();

        $this->userGateway = new UserGateway();
        $this->teamStatusGateway= new TeamStatusGateway();

        // service execution can take a while
        @set_time_limit(0);
        try {
            $token =  $this->settings->get('plugin_hipchatstatusupdater_HipChat Token');
            $roomid =  $this->settings->get('plugin_hipchatstatusupdater_HipChat Roomid');

            //get list of users to use when comparing the room messages
            if (!isset($_SESSION['hipchat_users'])) {
                $users = $this->makeRequest("http://api.hipchat.com/v1/users/list?auth_token=".$token);
                $_SESSION['hipchat_users'] = $users;
            } else {
                $users = $_SESSION['hipchat_users'];
            }

            $json_output = json_decode($users, true);
            $hipchatusers = array();
            foreach ( $json_output['users'] as $user ) {
                $hipchatusers[$user['user_id']] = $user['email'];
            }

            $messages = $this->makeRequest("http://api.hipchat.com/v1/rooms/history?room_id=".$roomid."&timezone=". date_default_timezone_get()."&date=".date('Y-m-d')."&auth_token=".$token);

            $json_output = json_decode($messages, true);
            $users = array();
            if ($lastInfo != "") {
                $checkagainstDate = $lastInfo['time'];
            } else {
                $checkagainstDate = date('Y-m-d', mktime(0, 0, 0, date("m") , date("d") - 1, date("Y")));
            }

            if ( is_array($json_output) ) {
                if ( array_key_exists('messages', $json_output) ) {
                    if ( count($json_output['messages']) > 0 ) {
                        foreach ( $json_output['messages'] as $message ) {
                            if (strtotime($message['date']) < strtotime($checkagainstDate)) continue;
                                $this->parseMessage($message,$hipchatusers,$users);
                        }
                    }
                }
            }
            return "";
        }
        catch (Exception $e) {
            $message = $e->getMessage();
            die('Unable to retrieve users. Error message'.$message);
        }
    }

    private function parseMessage($message,$hipchatusers,&$users)
    {
        if (key_exists($message['from']['user_id'], $hipchatusers)) {
            //add users to array so we don't have to look up for each message
            if (!key_exists($hipchatusers[$message['from']['user_id']],$users)) {
                $userid = $this->userGateway->getUserIdForEmail($hipchatusers[$message['from']['user_id']]);
                $users[$hipchatusers[$message['from']['user_id']]] = $userid;
            } else {
                $userid = $users[$hipchatusers[$message['from']['user_id']]];
            }
            if (substr(strtolower($message['message']),0,7)=="@status") {
                $this->teamStatusGateway->saveTeamStatus(new User($userid), substr($message['message'],8), 0);
            }
        } else {
            // they aren't in the hipchat users, so try to look up from their nick name
            $nickname = $message['from']['name'];
            $userid = $this->userGateway->getUserIdFromNickName($nickname);

            if ( $userid == 0 ) {
                $this->log(1, "Could not match user with hipchat status message of: ".$message['message']);
            } else {
                if (substr(strtolower($message['message']),0,7)=="@status") {
                    $this->teamStatusGateway->saveTeamStatus(new User($userid), substr($message['message'],8), 0);
                }
            }
        }
    }

    /**
     * Makes a new GET request to the HipChat API using cURL
     */
    private function makeRequest($url) {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch,CURLOPT_ENCODING , 'gzip');

        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        //Check we got a response
        if(strlen($response) == 0) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            die("CURL error: $errno - $error");
        }

        //Check we got the correct http code
        if($code !== self::STATUS_OK) {
            die("HTTP status code: $code, response=$response");
        }

        curl_close($ch);

        //Return JSON
        return $response;
    }
}