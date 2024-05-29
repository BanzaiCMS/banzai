<?php
declare(strict_types=1);

namespace Banzai\Http\Filter;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Http\RequestInterface;


class IPFilter
{
    const IPV4FILTER_TABLE = 'ipfilter';        // yes, no "v4" in table name. at the time it was created ipv4 and ip were the same
    const IP6FILTER_TABLE = 'ip6filter';

    function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {

    }


    protected function FilterResponseFactory(bool $block, bool $disableTracking = false, int|string $statuscode = 0, string $statustext = '', string $infotext = ''): FilterReponse
    {
        if (!$block)
            return new FilterReponse(false, $disableTracking);

        $statuscode = (int)$statuscode;

        if ($statuscode <= 200)
            $statuscode = 403;

        if (empty($statustext))
            $statustext = 'Forbidden';

        if (empty($infotext))
            $infotext = "<html><head><title>403 Not allowed on this Server.</title></head><body><center><h1>403 Sorry, you are not allowed on this Server.</h1><p>Please go away.</p></center></body></html>";

        return new FilterReponse(true, $disableTracking, $statuscode, $statustext, $infotext);

    }

    public function filterRequest(RequestInterface $request): FilterReponse
    {
        $ip = $request->getClientIP();
        $version = ipv4::getIPVersion($ip);

        if ($version == 6) {
            $entry = $this->getIPv6FilterEntry($ip);
        } else {
            $entry = $this->getIPv4FilterEntry($ip);
        }

        if (empty($entry)) {
            $_SESSION['ip_access_perm_code'] = '';          // TODO ???
            return $this->FilterResponseFactory(false);
        }

        $ipblocked = $entry['blocked'] == 'yes';
        $donttrace = $entry['tracked'] == 'no';

        if (!$ipblocked) {
            $_SESSION['ip_access_perm_code'] = $entry['ip_access_perm_code'];   // TODO ???
            // if ((empty($permobj)) && (!empty($rec['ip_access_perm_code']))) {
            //     $permobj = array();
            //    $permobj[$rec['ip_access_perm_code']] = $rec['ip_access_perm_code'];
            // }
            return $this->FilterResponseFactory(false, $donttrace);
        }

        $this->logger->warning('request from ipaddress ' . $ip . ' is blocked by ipfilter', array(
            'msgid' => 'ipfilterblocked',
            'ip' => $ip
        ));

        return $this->FilterResponseFactory(true, $donttrace, $entry['blockcode'], $entry['blockresponse'], $entry['blockinfo']);

    }

    protected function getIPv4FilterEntry(string $ip = ''): array
    {

        $val = (int)ip2long($ip);
        $vas = sprintf("%u", $val);

        $bind = array('ipstart' => $vas, 'ipend' => $vas);
        $sql = 'SELECT * FROM ' . self::IPV4FILTER_TABLE . ' WHERE ipstart<=:ipstart AND ipend>=:ipend ORDER BY ipcount';
        return $this->db->get($sql, $bind);
    }


    protected function getIPv6FilterEntry(string $ip = ''): array
    {
        $d = ipv6::inet6_to_int64($ip);

        if ($d == null) {
            $this->logger->error($ip . 'is not a valid IPV6 address');
            return array();
        }

        $high = $d[0];
        $low = $d[1];

        $bind = array();

        $sql = 'SELECT * FROM ' . self::IP6FILTER_TABLE . ' WHERE (starthi<:high1 OR (starthi=:high2 AND startlo<=:low1)) AND (endhi>:high3 OR (endhi=:high4 AND endlo>=:low2))
              ORDER BY prefix DESC, starthi,startlo';

        $bind['high1'] = $high;
        $bind['high2'] = $high;
        $bind['high3'] = $high;
        $bind['high4'] = $high;

        $bind['low1'] = $low;
        $bind['low2'] = $low;

        return $this->db->get($sql, $bind);

    }

}
