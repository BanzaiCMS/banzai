<?php

namespace Banzai\Http\Tracker;

use Flux\Config\Config;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Users\User;
use Banzai\Http\Request;


class ClientTracker
{

    const WEBTRACKER_VISIT_TABLE = 'visits';
    const WEBTRACKER_CLICKS_TABLE = 'visit_clicks';
    const WEBTRACKER_FEEDS_TABLE = 'feedsubscribers';
    const CAMPAIGN_TABLE = 'campaigns';
    const CONVERSIONTYPE_TABLE = 'conversion_types';


    protected int $WAFScore = 0;

    protected Request $request;

    protected bool $AllowTrace = true;
    protected bool $AllowTraceWithIP = true;

    protected bool $isAlreadylogged = false;

    protected bool $LogPostData = false;

    protected array $visit = array();

    protected int $ItemID = 0;

    protected string $ItemName = '';

    protected string $ItemType = '';

    private $_visitoptions = array();
    private $_pageoptions = array();
    private $ref = '';
    private $uri = '';
    private $agent = '';
    private $client_ip = '';
    private $errorcode = '';

    private $_header = '';
    private $_body = '';
    private $_requestheader = '';
    private $_requestbody = '';

    private $_apilog = false;
    private $_processid = 0;

    function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected Config $params, protected User $user)
    {
        $this->AllowTraceWithIP = $this->params->get('system.tracking.withip') == 'yes';

    }

    public function setItemID(?int $id = 0): self
    {
        if (!is_null($id))
            $this->ItemID = $id;
        return $this;
    }

    public function setItemName(?string $name = ''): self
    {
        if (!is_null($name))
            $this->ItemName = $name;
        return $this;
    }

    public function setItemType(?string $type = ''): self
    {
        if (!is_null($type))
            $this->ItemType = $type;

        return $this;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function setTraceFlag(bool $trace = true): self
    {
        $this->AllowTrace = $trace;
        return $this;
    }

    public function setTraceWithIP(bool $trace = true): self
    {
        $this->AllowTraceWithIP = $trace;
        return $this;
    }

    public function getTraceWithIP(): bool
    {
        return $this->AllowTraceWithIP;
    }

    public function dontLogPostData()
    {
        $this->LogPostData = false;
    }

    public function processPixelRequest(?int $id = null): self
    {

        if (is_null($id))
            return $this;

        if ($id < 1)
            return $this;

        $data = array();
        $data['getpixel'] = 'yes';
        $data['visitid'] = $id;

        if (!empty($_REQUEST['js']))                             // TODO
            $data['client_javascript'] = 'yes';

        if (!empty($_REQUEST['cd']))
            $data['client_coldepth'] = $_REQUEST['cd'];         // TODO

        if (!empty($_REQUEST['co']))                            // TODO
            if ($_REQUEST['co'] == 'y')
                $data['client_cookies_allowed'] = "yes";

        if (!empty($_REQUEST['s']))
            $data['client_screen'] = $_REQUEST['s'];            // TODO

        $this->db->put(self::WEBTRACKER_VISIT_TABLE, $data, array('visitid'), false);

        return $this;
    }

    public function gotPixel(): bool
    {
        $res = $this->db->get('SELECT getpixel FROM ' . self::WEBTRACKER_VISIT_TABLE . ' WHERE visitid=?', array($_SESSION['visitid']));
        return $res['getpixel'] == 'yes';
    }


    public function setAPIlog(bool $bla): self
    {
        $this->_apilog = $bla;
        return $this;
    }

    public function setProcessid($bla): self
    {
        if ($bla > 0)
            $this->_processid = $bla;
        return $this;
    }

    public function setHeaderLog(string $header = '', string $errorcode = ''): self
    {
        $this->_header = $header;

        if (!empty($errorcode))
            $this->setPageError($errorcode);

        $this->logRequest();
        return $this;
    }

    public function setResponseBody(string $body = ''): self
    {
        $this->_body = $body;
        return $this;
    }

    public function setRequestHeader(string $header = ''): self
    {
        $this->_requestheader = $header;
        return $this;
    }

    public function setRequestBody(string $body = ''): self
    {
        $this->_requestbody = $body;
        return $this;
    }


    /**
     * LEGACY-Funktion für alte PHP-Templates
     *
     * deprecated !!!
     *
     * @param string $cmd
     * @param string $logfeed
     * @return $this
     */
    public function doLog(string $cmd = '', string $logfeed = ''): void
    {

        global $item_obj;           // required for Legacy Functions,
        global $catobj;             // required for Legacy Functions,

        if (empty($item_obj))
            $page = $catobj;
        else
            $page = $item_obj;

        $klasse = $page['objclass'];

        switch ($klasse) {
            case 'article':
                $id = $page['article_id'];
                break;
            case 'product':
                $id = $page['products_id'];
                break;
            case 'category':
                $id = $page['categories_id'];
                break;
            default:
                $id = 0;
        }

        $this->setItemID($id);

        $this->setItemType($klasse);

        if (isset($page['pagetitle']))
            $this->setItemName($page['pagetitle']);

        $this->logRequest($logfeed);

        if (empty($this->visit))
            return;

        if ($cmd == 'nopixel')
            return;

        echo $this->createPixel(false);

    }

    /**
     * logs a request and returns the string of the logpixel for use in new templates
     * is called from AbstractController
     */
    public function getPixel(): string
    {
        $this->logRequest();

        if (empty($this->visit))
            return '';

        return $this->createPixel(false);

    }

    /**
     *  logs a http request in DB and sets $this->visit for later us from createPixel()
     */
    public function logRequest(string $logfeed = ''): self
    {

        if (!$this->AllowTrace) // nicht tracen / loggen ...
            return $this;

        if ($this->isAlreadylogged)
            return $this;

        if ($this->user->isLoggedIn() && $this->user->isTrackingDisabled())
            return $this;

        $this->isAlreadylogged = true;

        $campaign = '';

        $this->ref = '';

        if (isset($_SERVER["HTTP_REFERER"]))        // TODO
            $this->ref = $_SERVER["HTTP_REFERER"];  // TODO

        $dnts = '';

        if (isset($_SERVER['HTTP_DNT'])) {          // TODO
            if ($_SERVER['HTTP_DNT'] == '1')        // TODO
                $dnts = 'yes';
            else
                $dnts = 'no';
        }

        $this->uri = $this->request->getAbsolutePath();

        if (!empty($_SERVER['HTTP_USER_AGENT']))        // TODO
            $this->agent = $_SERVER['HTTP_USER_AGENT'];     // TODO

        $this->client_ip = $this->request->getClientIP();

        $scripturi = "http://" . $_SERVER['HTTP_HOST']; // TODO

        // lokaler referrer !
        $loref = (strncmp($scripturi, $this->ref, strlen($scripturi)) == 0);

        $lorefurl = '';

        if ($loref)
            $lorefurl = substr($this->ref, strlen($scripturi));

        if ((!empty($this->ref)) && (!$loref))
            $seo = $this->searchEngine($this->ref);

        $jetzt = date('Y-m-d');

        // FEED loggen
        if (!empty($logfeed)) {
            $feedip = $this->client_ip;
            $loagent = strtolower($this->agent);

            if (strncmp('netvibes', $loagent, 8) == 0) // Netvibes-reader kommt
                // mit verschiedenen IPs
                $feedip = '0.0.0.0';

            if (strncmp('feedfetcher-google', $loagent, 18) == 0) // Feedfetcher
                // kommt mit
                // verschiedenen
                // IPs
                $feedip = '0.0.0.0';

            $subscriber = 1;

            $sql = ' SELECT * FROM ' . self::WEBTRACKER_FEEDS_TABLE . ' WHERE created=:jetzt AND client_ip=:ip AND client_uagent=:agent';
            $data['jetzt'] = $jetzt;
            if ($this->AllowTraceWithIP)
                $data['ip'] = $feedip;
            $data['agent'] = $this->agent;

            $feed = $this->db->get($sql, $data);

            if (empty($feed)) {
                $sub = '';
                $agentarr = explode(' ', $loagent);
                foreach ($agentarr as $feld) {
                    if (strncmp('subscriber', $feld, 10) == 0)
                        $subscriber = $sub;
                    $sub = $feld;
                }
                $data = array();
                $data['subscribers'] = $subscriber;
                $data['usecount'] = 1;
                $data['created'] = $this->db->timestamp();
                if ($this->AllowTraceWithIP)
                    $data['client_ip'] = $feedip;
                $data['client_uagent'] = $this->agent;
                $this->db->add(self::WEBTRACKER_FEEDS_TABLE, $data);
            } else {
                $data = array('id' => self::WEBTRACKER_FEEDS_TABLE);
                $data['usecount'] = $feed['usecount'] + 1;
                $this->db->put(self::WEBTRACKER_FEEDS_TABLE, $data, array('id'), false);
            }
        }

        // In DB loggen ...

        $tcn = $this->params->get('system.tracking.cookie.name');
        if (empty($tcn))
            $tcn = 'instid';

        if (isset($_COOKIE[$tcn]))          // TODO
            $uv = $_COOKIE[$tcn];           // TODO
        else
            $uv = '';

        // falls kein tracking-cookie gesetzt ist, prüfen, ob session-cookie gesetzt ist und das verwenden

        if (empty($uv) && isset($_COOKIE['secureinsid']))       // TODO
            $uv = $_COOKIE['secureinsid'];

        if (empty($uv) && isset($_COOKIE['insid']))             // TODO
            $uv = $_COOKIE['insid'];

        // wenn cookie, dann cookie-key, sonst ip, sonst useragent für die zuordnung des visits verwenden

        // if cookie is set, we look for cookie AND ip-address, else wie look for ip-address AND user-agent
        // we always store the ip-addres in visit, even if we do not show it to the user
        // TODO clear ip-adress in timer after x-days (if $this->AllowTraceWithIP is not set in system params
        //

        if (empty($uv)) {
            $sql = 'SELECT * FROM ' . self::WEBTRACKER_VISIT_TABLE . ' WHERE client_ip=:ip AND client_uagent=:uagent';
            $bind = array('ip' => $this->client_ip, 'uagent' => $this->agent);
        } else {
            $sql = 'SELECT * FROM ' . self::WEBTRACKER_VISIT_TABLE . ' WHERE client_ip=:ip AND uvkey=:cookie';
            $bind = array('ip' => $this->client_ip, 'cookie' => $uv);
        }

        $timeoutsecs = (int)$this->params->get('system.tracking.visit.timeout');

        if ($timeoutsecs > 0) {
            $sql .= ' AND (changed > (now() - INTERVAL :timeoutsecs SECOND))';
            $bind['timeoutsecs'] = $timeoutsecs;
        }

        // TOD
        $visit = $this->db->get($sql, $bind);

        if (!empty($visit)) {

            $newvisit = false;

            // falls cookie noch nicht gesetzt, dann setzen
            if ((empty($visit['uvkey'])) && (!empty($uv))) {
                $data = array('visitid' => $visit['visitid'], 'uvkey' => $uv);
                $this->db->put(self::WEBTRACKER_VISIT_TABLE, $data, array('visitid'), false);
            }

            // Kampagne vermerken, falls vorhanden
            if (isset($item_obj['campaignstart']))
                if ($item_obj['campaignstart'] == 'always')
                    if ($visit['campaignid'] == 0) {
                        $campaign = $this->getcampaign($item_obj);
                        if (!empty($campaign)) {
                            $data = array('visitid' => $visit['visitid']);
                            $data['campaignid'] = $campaign['id'];
                            $data['campaigncost'] = $campaign['costpervisit'];
                            $this->db->put(self::WEBTRACKER_VISIT_TABLE, $data, array('visitid'), false);
                        }
                    }
        } else { // Neuer Visit

            $newvisit = true;

            if (isset($item_obj['campaignstart']))
                if (($item_obj['campaignstart'] == 'startpage') || ($item_obj['campaignstart'] == 'always'))
                    $campaign = $this->getcampaign($item_obj);

            $uag = $this->userAgent($this->agent);

            $data = array();
            $data['created'] = $this->db->timestamp();
            $data['changed'] = $this->db->timestamp();

            if (!empty($dnts))
                $data['donottrack'] = $dnts;

            if (!empty($campaign)) {
                $data['campaigncost'] = $campaign['costpervisit'];
                $data['campaignid'] = $campaign['id'];
            }

            if (!empty($uag['user_agent']))
                $data['client_browser'] = $uag['user_agent'];

            if (!empty($uag['user_agent_version']))
                $data['client_browserversion'] = $uag['user_agent_version'];

            if (!empty($uag['operating_system']))
                $data['client_os'] = $uag['operating_system'];

            if (!empty($seo['searchengine']))
                $data['searchengine'] = $seo['searchengine'];

            if (!empty($seo['keywords']))
                $data['keywords'] = $seo['keywords'];

            if (!empty($uv))
                $data['uvkey'] = $uv;

            // if ($this->AllowTraceWithIP)
            // we always set this internally for visit-tracking
            $data['client_ip'] = $this->client_ip;

            $data['client_uagent'] = $this->agent;

            if (!$loref)
                $data['external_referer_url'] = $this->ref;

            $visitid = $this->db->add(self::WEBTRACKER_VISIT_TABLE, $data);

            if ($visitid < 1) {
                $this->logger->error('can not create new visit', $data);
                return $this;
            }

            $visit['visitid'] = $visitid;
            $visit['clicks_count'] = 0;
            $visit['getpixel'] = 'no';      // we do not have a pixel yet for this session
        }

        // TODO update only, if we do not have created a new visit, else a db.query too much

        $_SESSION['visitid'] = $visit['visitid'];       // TODO !!!

        $data = array('visitid' => $visit['visitid']);
        $data['changed'] = $this->db->timestamp();
        $data['clicks_count'] = $visit['clicks_count'] + 1;

        if ($this->user->isLoggedIn())
            $data['username'] = $this->user->getLoginName();

        $this->db->put(self::WEBTRACKER_VISIT_TABLE, $data, array('visitid'), false);


        // Hier haben wir jetzt entweder einen visit neu angelegt oder einen
        // vorhandenen geholt


        $rec = array();
        $rec['created'] = $this->db->timestamp();

        $httprequestmethod = $this->request->getMethod();

        $rec['requestmethod'] = $httprequestmethod;
        if (!empty($visit['visitid']))
            $rec['visitid'] = $visit['visitid'];

        $rec['pageurl'] = $this->uri;

        if (!empty($this->ItemName))
            $rec['pagetitle'] = $this->ItemName;

        if (empty($this->ItemID > 0))
            $rec['pageid'] = $this->ItemID;

        if (!empty($this->ItemType))
            $rec['pagetype'] = $this->ItemType;

        if ($this->AllowTraceWithIP)
            $rec['clientip'] = $this->client_ip;

        if ($this->user->isLoggedIn())
            $rec['clickusername'] = $this->user->getLoginName();

        // TODO if (! empty ( $this->_header ))
        // TODO $rec ['responseheader'] = $this->_header;

        // TODO if (! empty ( $this->_requestheader ))
        // TODO $rec ['requestheader'] = $this->_requestheader;

        // TODO if (! empty ( $this->_body ))
        // TODO $rec ['responsebody'] = $this->_body;

        if (!empty($newvisit))
            $rec['entry_page'] = 'yes';

        if (!empty($seo['searchengine']))
            $rec['searchengine'] = $seo['searchengine'];

        if (!empty($seo['keywords']))
            $rec['keywords'] = $seo['keywords'];

        if ((empty($seo['searchengine'])) && (empty($lorefurl)) && (!empty($this->ref)))
            $rec['ext_referer'] = $this->ref;

        if (!empty($this->errorcode))
            if ($this->errorcode > 0)
                if ($this->errorcode != 200)
                    $rec['error404'] = $this->errorcode;

        if ($this->WAFScore > 0)
            $rec['wafscore'] = $this->WAFScore;

        if (!empty($_GET))          // TODO
            if ($this->params->get('system.tracking.getdata') == 'yes') {
                $rec['getdata'] = @json_encode($_GET);
            }

        if (!empty($_POST)) {       // TODO
            if ($this->params->get('system.tracking.postdata') == 'yes') {
                if (isset($this->LogPostData))
                    $rec['postdata'] = @json_encode($_POST);
                else
                    $rec['postdata'] = @json_encode('not logged');
            }

        } else { // keine Postdata ...
            if (!empty($this->_requestbody))
                $rec['postdata'] = $this->_requestbody; // normalerweise bereits json ...
        }

        if ($this->params->get('system.tracking.cookiedata') == 'yes')
            $rec['cookiedata'] = @json_encode($_COOKIE);                        // TODO

        if ($this->params->get('system.tracking.sessiondata') == 'yes')
            $rec['sessiondata'] = @json_encode($_SESSION);                      // TODO

        // request execution Zeit in millisekunden
        $rec['request_executiontime'] = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000.0;    // TODO

        // Neuen Click-Eintrag erzeugen
        $this->db->add(self::WEBTRACKER_CLICKS_TABLE, $rec);

        $this->visit = $visit;

        return $this;
    }

    /**
     *  creates the javascript for the logpixel and returns it as a string
     *  uses the data in $this->visit that was created from logRequest();
     *
     */
    public function createPixel(bool $force = true): string
    {
        // if (($visit['clicks_count']<20) && ($visit['getpixel']!='yes') &&
        // ($cmd!='nopixel') ) {

        if (empty($this->visit))
            return '';

        // we do not generate a pixel if we already generated one for this session if we are not forced to
        if (($this->visit['getpixel'] == 'yes') && (!$force))
            return '';

        $ra = time();
        $par = '?ra=' . $ra;
        $par = $par . '&amp;id=' . $this->visit['visitid'];
        // $par = $par . '&amp;js=n';

        // if (!empty($sess))
        //    $par = $par . '&amp;se=' . $sess;

        $ret = <<<JAVASCRIPTBLABLA
<script>
 function mypixl(id,ra){cnt='/cgi-bin/blank.gif?js=y';ras='&amp;ra='+escape(ra);ids='&amp;id='+escape(id);swh=screen.width+'x'+screen.height;scd=screen.colorDepth;s='&amp;s='+escape(swh);cd='&amp;cd='+escape(scd);ico=navigator.cookieEnabled;if(ico){coo = '&amp;co=y';}else{coo = '&amp;co=n';}fasel=cnt+ras+ids+coo+s+cd;if(fasel){document.write("<img src='" + fasel + "' width='1' height='1' alt='' />")}}
JAVASCRIPTBLABLA;

        $ret .= ' mypixl(' . $this->visit['visitid'] . ',' . $ra . ');';

        $ret .= "\n</script>\n<noscript>\n" . '   <img src="/cgi-bin/blank.gif' . $par . '" width="1" height="1" alt="&nbsp;" />' . "\n";
        $ret .= "</noscript>\n";

        return $ret;

    }


    /**
     * @param $var
     * @param $value
     */
    public function setPageOption($var, $value): self
    {
        $this->_pageoptions[$var] = $value;
        return $this;
    }

    /**
     * @param $var
     * @param $value
     */
    public function setVisitOption($var, $value): self
    {
        $this->_visitoptions[$var] = $value;
        return $this;
    }

    public function setPageError($value = '404'): self
    {
        $this->errorcode = $value;
        return $this;
    }

    public function setWAFScore(int $score = 0): self
    {
        $this->WAFScore = $score;
        return $this;
    }


    /**
     * @param $rulesFile
     * @return array
     */
    protected function readRules($rulesFile): array
    {
        $rules = array();

        if (($file = @file($rulesFile)) == true) {
            $index = 0;
            $numLines = sizeof($file);

            for ($i = 0; $i < $numLines; $i += 3) {
                $rules[$index]['pattern'] = $file[$i];
                $rules[$index]['string'] = $file[$i + 1];
                $index++;
            }
        }

        return $rules;
    }

    /**
     * @param string $string
     * @return array
     */
    public function userAgent($string = ''): array
    {
        if (empty($string))
            $string = $_SERVER['HTTP_USER_AGENT'];      // TODO

        if (preg_match('#\((.*?)\)#', $string, $tmp)) {
            $elements = explode(';', $tmp[1]);
            $elements[] = $string;
        } else {
            $elements = array(
                $string
            );
        }

        if ($elements[0] != 'compatible') {
            $elements[] = substr($string, 0, strpos($string, '('));
        }

        if (empty($this->OperatingSystemRules))
            $this->OperatingSystemRules = $this->readRules(__DIR__ . '/operating_systems.ini');

        $result['operating_system'] = $this->ruleMatch($elements, $this->OperatingSystemRules);

        if (empty($this->UserAgentRules))
            $this->UserAgentRules = $this->readRules(__DIR__ . '/user_agents.ini');

        $result['user_agent'] = $this->ruleMatch($elements, $this->UserAgentRules);

        $result['user_agent'] = str_replace('/', ' ', $result['user_agent']);
        $ele = explode(' ', $result['user_agent']);
        $eanz = count($ele);
        $result['user_agent_version'] = $ele[$eanz - 1];
        $reua = '';
        $spa = '';
        for ($i = 0; $i < $eanz - 1; $i++) {
            $reua = $reua . $spa . $ele[$i];
            $spa = ' ';
        }

        $result['user_agent'] = $reua;
        return $result;
    }

    /**
     * @param $elements
     * @param $rules
     * @return mixed|string
     */
    protected function ruleMatch($elements, $rules)
    {
        if (!is_array($elements)) {
            $noMatch = $elements;
            $elements = array(
                $elements
            );
        } else {
            $noMatch = 'Not identified';
        }
        $result = '';

        foreach ($rules as $rule) {
            if (empty($result)) {
                foreach ($elements as $element) {
                    $element = trim($element);
                    $pattern = trim($rule['pattern']);

                    if (preg_match($pattern, $element, $tmp)) {
                        // echo('pattern: '.$pattern.'<br/><pre>');
                        // print_r($tmp);
                        // echo('</pre>');
                        $result = str_replace(array(
                            '$1',
                            '$2',
                            '$3',
                            '$4',
                            '$5',
                            '$6',
                            '$7',
                            '$8'
                        ), array(
                            isset($tmp[1]) ? $tmp[1] : '',
                            isset($tmp[2]) ? $tmp[2] : '',
                            isset($tmp[3]) ? $tmp[3] : '',
                            isset($tmp[4]) ? $tmp[4] : '',
                            isset($tmp[5]) ? $tmp[5] : '',
                            isset($tmp[6]) ? $tmp[6] : '',
                            isset($tmp[7]) ? $tmp[7] : '',
                            isset($tmp[8]) ? $tmp[8] : ''
                        ), trim($rule['string']));

                        break;
                    }
                }
            } else {
                break;
            }
        }

        return (!empty($result)) ? $result : $noMatch;
    }

    /**
     * @param $referer
     * @return mixed
     */
    protected function searchEngine($referer): array
    {
        $ret = array();

        if (empty($this->se_match_rules))

            $this->se_match_rules = @file(__DIR__ . '/search_engines.match.ini');

        foreach ($this->se_match_rules as $matchRule) {
            if (preg_match(trim($matchRule), $referer, $tmp)) {
                $keywords = $tmp[1];
            }
        }

        if (empty($this->se_group))
            $this->se_group = $this->readRules(__DIR__ . '/search_engines.group.ini');

        $searchEngineName = $this->ruleMatch($referer, $this->se_group);

        if ($searchEngineName != $referer) {
            $ret['searchengine'] = $searchEngineName;
            $ret['keywords'] = strtolower(utf8_decode(urldecode($keywords)));
        }
        return $ret;
    }

    protected function getcampaign($item_obj): array
    {
        $ref = $this->ref;

        if ($item_obj['campaignid'] > 0) {
            $ca = $this->db->get('SELECT * FROM ' . self::CAMPAIGN_TABLE . ' WHERE id=' . $item_obj['campaignid']);
            return $ca;
        }

        if (empty($ref)) {
            $rsql = ' referrer="" ';
        } else {
            $refarr = parse_url($ref);
            $refhost = $refarr['host'];
            $rsql = ' ( referrer="" or referrer="' . $refhost . '" ) ';
        }

        if (empty($_GET)) {
            $sql = 'SELECT * FROM ' . self::CAMPAIGN_TABLE . ' WHERE active="yes" AND paramname="" AND ' . $rsql . ' ORDER BY referrer DESC ';
            $ca = $this->db->get($sql);
            return $ca;
        }

        // Hier jetzt mit übergabeparametern
        $list = $this->db->getlist('SELECT * FROM ' . self::CAMPAIGN_TABLE . ' WHERE active="yes" AND paramname<>"" AND ' . $rsql . ' ORDER BY referrer DESC ');
        foreach ($list as $u) {
            $pm = $u['paramname'];
            $pv = $u['paramvalue'];
            if ($_GET[$pm] == $pv)
                return $u;
        }
        return array();
    }

}
