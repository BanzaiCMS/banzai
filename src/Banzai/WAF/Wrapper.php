<?php
declare(strict_types=1);

namespace Banzai\WAF;

use Exception;
use Flux\Logger\LoggerInterface;

use Banzai\Http\Request;

use IDS\Init;
use IDS\Monitor;

class Wrapper
{
    public function __construct(protected LoggerInterface $logger, protected Request $request, protected string $ConfigDir = '', protected string $TempDir = '')
    {
    }

    public static function create(LoggerInterface $logger, Request $request, string $ConfigDir = '', string $TempDir = ''): Wrapper
    {
        return new static($logger, $request, $ConfigDir, $TempDir);
    }

    public function check(): int
    {

        try {

            $request = array(
                'REQUEST' => $_REQUEST      // TODO replace with data from $this->request
            );

            $init = Init::init($this->ConfigDir . 'phpids_config.ini.php');

            $init->config['General']['use_base_path'] = false;
            $init->config['General']['filter_path'] = $this->ConfigDir . 'phpids_default_filter.xml';
            $init->config['General']['tmp_path'] = $this->TempDir;
            $init->config['Caching']['caching'] = 'none';

            // 2. Initiate the PHPIDS and fetch the results
            $ids = new Monitor($init);

            $result = $ids->run($request);

            if (!$result->isEmpty()) {

                $logdata = array('msgid' => 'wafdeny');
                $logdata['ip'] = $this->request->getClientIP();  // für waf die ip immer protokollieren

                $score = (int)$result->getImpact();

                $logdata['score'] = $score;

                $events = $result->getIterator();

                $count = 0;
                foreach ($events as $event) {
                    foreach ($event as $filter) {
                        $count++;
                        $logdata['score' . $count] = $filter->getImpact();
                        $logdata['description' . $count] = $filter->getDescription();
                    }
                }

                $this->logger->warning('WAF denied request from ipaddress ' . $logdata['ip'], $logdata);
                return $score;

            }

        } catch (Exception $e) {

            $msg = $e->getMessage();
            $this->logger->critical('WAF critical error: ' . $msg);
            exit(0);

        }

        return 0;
    }
}
