<?php

namespace Banzai\Domain\Customers;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Authentication\Permissions;
use Banzai\Domain\Countries\CountriesGateway;
use INS\Domain\Finance\FinanceGateway;          // TODO
use Banzai\Domain\Users\UsersGateway;

class CustomersGateway
{
    const CUSTOMER_TABLE = 'addresses';
    const CUSTOMER_GROUP_TABLE = 'customers_groups';
    const CUSTOMER_OCCUPATION_TABLE = 'addresses_occupation';
    const CUSTOMER_SOURCE_TABLE = 'addresses_sources';
    const CUSTOMER_OPTION_TABLE = 'addresses_options';

    const CUSTOMER_OPTION_TYPE_TABLE = 'addresses_optiontypes';
    const CUSTOMER_VATLOG_TABLE = 'addresses_vatlog';
    const CUSTOMER_USER_STATE_TABLE = 'addresses_user_states';
    const CUSTOMER_DELIVERY_TABLE = 'addresses_delivery';
    const CUSTOMER_ADDRESS_TABLE = 'addresses_address';

    const BUDGET_TABLE = 'budgets';     // also in FinanceGateway

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }


    /**
     * @param $adrid
     * @return string
     */
    public static function get_adr_name($adrid)
    {
        global $db;

        $adr = $db->get('SELECT adr_company,adr_firstname,adr_lastname FROM ' . self::CUSTOMER_TABLE . ' WHERE adr_id=?', array($adrid));

        if (empty($adr))
            return '';

        if (empty($adr['adr_company']))
            $name = $adr['adr_firstname'] . ' ' . $adr['lastname'];
        else
            $name = $adr['adr_company'];
        return $name;
    }


    /**
     * @param $adrstateid
     * @param int $adrid
     * @param int $userid
     * @param bool $manuell
     * @return bool
     */
    public static function set_adr_user_status($adrstateid, int $adrid = 0, int $userid = 0, bool $manuell = false): bool
    {
        global $db;
        global $logger;


        if (!is_numeric($adrstateid)) {
            if (empty($adrstateid)) {
                $logger->error('adrstateid is empty');
                return false;
            } else {
                $st = $db->get('SELECT adruser_state_id FROM ' . self::CUSTOMER_USER_STATE_TABLE . ' WHERE adruser_code=?', array($adrstateid));
                if (!empty($st))
                    $adrstateid = $st['adruser_state_id'];
                else {
                    $logger->error('adrstateid(' . $adrstateid . ') Code nicht gefunden.');
                    return false;
                }
            }
        }

        if ($adrstateid < 1) {
            $logger->error('adrstateid(' . $adrstateid . ') < 1');
            return false;
        }

        if ($adrid < 1) {
            $logger->error('adrid(' . $adrid . ') < 1');
            return false;
        }

        $status = $db->get('SELECT * FROM ' . self::CUSTOMER_USER_STATE_TABLE . ' WHERE adruser_state_id=?', array($adrstateid));

        if (empty($status)) {
            $logger->error('Adress-Status ID ' . $adrstateid . ' nicht gefunden.');
            return false;
        }

        // Adresse setzen ...
        if (($status['adruser_scope'] == 'adr') || ($status['adruser_scope'] == 'all')) {
            $neu = array();

            if ($manuell)
                $neu['date_last_manual_state_change'] = date("Y-m-d");

            $neu['adruser_state_id'] = $status['adruser_state_id'];

            if ($status['adruser_set_adr_active'] != 'dontset')
                $neu['adr_active'] = $status['adruser_set_adr_active'];

            if ($status['adruser_set_adr_delivery_stop'] != 'dontset')
                $neu['delivery_stop'] = $status['adruser_set_adr_delivery_stop'];

            $neu['adr_id'] = $adrid;

            $oki = $db->put(self::CUSTOMER_TABLE, $neu, array('adr_id'));

            if (!$oki) {
                $logger->error('db->put hat nicht geklappt in CUSTOMER_TABLE bei id=' . $adrid);
                return false;
            }
        }

        // User setzen
        if (($status['adruser_scope'] == 'user') || ($status['adruser_scope'] == 'all')) {
            $neu = array();
            $neu['adruser_state_id'] = $status['adruser_state_id'];

            if ($status['adruser_set_user_active'] != 'dontset')
                $neu['user_active'] = $status['adruser_set_user_active'];

            if ($status['adruser_set_user_blocked'] != 'dontset')
                $neu['user_blocked'] = $status['adruser_set_user_blocked'];

            $bind = array();
            $sql = 'SELECT user_id FROM ' . UsersGateway::USER_TABLE . ' WHERE address_id=:address_id';
            $bind['address_id'] = $adrid;

            if ($userid > 0) {
                $sql .= ' AND user_id=:user_id';
                $bind['user_id'] = $userid;
            }

            $liste = $db->getlist($sql, $bind);

            if (empty($liste))
                return true;

            $soki = true;

            foreach ($liste as $us) {
                $data = $neu;
                $data['user_id'] = $us['user_id'];
                $oki = $db->put(UsersGateway::USER_TABLE, $data, array('user_id'));
                if (!$oki) {
                    $soki = false;
                    $logger->error('db->put hat nicht geklappt in \Banzai\Domain\Users\UsersGateway::USER_TABLE bei id=' . $us['user_id']);
                }
            }

            return $soki;
        }

        return true;
    }

    public function getFeatureCodes(int $adrid = 0, bool $onlyinheritable = false): array
    {

        $optarr = array();

        if ($adrid > 0) {
            $sql = 'SELECT t.optiontypefeaturecode,o.adroptid,o.option_is_set FROM ' .
                self::CUSTOMER_OPTION_TABLE . ' o ' . 'JOIN ' .
                self::CUSTOMER_OPTION_TYPE_TABLE . ' t ON o.optiontypeid=t.optiontypeid ' .
                'WHERE o.adr_id=? AND t.isactive="yes" AND optiontypefeaturecode<>""';

            if ($onlyinheritable)
                $sql .= ' AND t.isinheritable="yes"';

            $optarr = $this->db->getlist($sql, array($adrid), 'optiontypefeaturecode', 'option_is_set');
        }

        return $optarr;
    }

    /**
     * @param int $adrid
     * @param string $optioncode
     */
    public static function clear_address_option($adrid = 0, $optioncode = '')
    {
        global $db;

        if ($adrid < 1)
            return;

        if (empty($optioncode))
            return;

        $opt = $db->get('SELECT optiontypeid FROM ' . self::CUSTOMER_OPTION_TYPE_TABLE . ' WHERE optiontypefeaturecode="' . $optioncode . '"');
        $optid = $opt['optiontypeid'];
        if ($optid > 0) {
            $sql = 'DELETE FROM ' . self::CUSTOMER_OPTION_TABLE . ' WHERE adr_id=' . $adrid . ' AND optiontypeid=' . $optid;
            $db->delete($sql);
        }
    }

    /**
     * @param int $adrid
     * @param string $optioncode
     */
    public static function set_address_option($adrid = 0, $optioncode = '')
    {
        global $db;

        if ($adrid < 1)
            return;

        if (empty($optioncode))
            return;

        $opt = $db->get('SELECT optiontypeid FROM ' . self::CUSTOMER_OPTION_TYPE_TABLE . ' WHERE optiontypefeaturecode="' . $optioncode . '"');
        $optid = $opt['optiontypeid'];

        if ($optid > 0) {
            $sql = 'DELETE FROM ' . self::CUSTOMER_OPTION_TABLE . ' WHERE adr_id=' . $adrid . ' AND optiontypeid=' . $optid;
            $db->delete($sql);

            $sql = 'INSERT INTO ' . self::CUSTOMER_OPTION_TABLE . ' SET adr_id=' . $adrid . ', optiontypeid=' . $optid;
            $db->insert($sql);
        }
    }

    public function getCustomerFromSession(): ?CustomerInterface
    {

        if (isset($_SESSION['customerobj']) && is_array($_SESSION['customerobj']))
            return new Customer($this->db, $this->logger, $_SESSION['customerobj']);
        else
            return null;
    }

    public function getCustomerByID(int $CustomerID = 0): ?CustomerInterface
    {
        if ($CustomerID < 1)
            return null;

        $cust = $this->db->get('SELECT * FROM ' . self::CUSTOMER_TABLE . ' WHERE adr_id=?', array($CustomerID));

        if (empty($cust))
            return null;

        $optarr = $this->getFeatureCodes($CustomerID);

        if ($cust['corporation_adr_id'] > 0) {
            $corpoptarr = $this->getFeatureCodes($cust['corporation_adr_id'], true);
            foreach ($corpoptarr as $feld => $inhalt) {
                if (isset($optarr[$feld]) && ($optarr[$feld] == 'no'))
                    continue;
                $optarr[$feld] = $inhalt;
            }
        }

        // feature-code budget
        if ($cust['budgetid'] > 0) {
            $cust['budget'] = $this->db->get('SELECT * FROM ' . self::BUDGET_TABLE . ' WHERE budgetid=?', array($cust['budgetid']));

            // featurecodes des budgets mit einbauen ...
            $bf = $cust['budget']['budgetfeaturecode'];
            if (!empty($bf)) {
                $bfarr = explode(',', $bf);
                foreach ($bfarr as $fc) {
                    if (!empty($fc))
                        $optarr[$fc] = 'yes';
                }
            }
        }

        // feature-code land
        if ($cust['adr_countryid'] > 0) {
            $cust['country'] = $this->db->get('SELECT * FROM ' . CountriesGateway::COUNTRY_TABLE . ' WHERE countries_id=?', array($cust['adr_countryid']));

            // featurecodes des Landes mit einbauen ...
            $bf = $cust['country']['countries_featurecode'];
            if (!empty($bf)) {
                $bfarr = explode(',', $bf);
                foreach ($bfarr as $fc) {
                    if (!empty($fc))
                        $optarr[$fc] = 'yes';
                }
            }
        }

        $cust['optarr'] = $optarr;

        $cust['permissions'] = $this->getOptionPermissions($CustomerID);

        return new Customer($this->db, $this->logger, $cust);
    }

    public function getOptionPermissions(int $CustomerID): array
    {

        if ($CustomerID < 1)
            return array();

        // TODO

        $sql = 'SELECT p.id, p.code FROM ' . Permissions::PERM_TABLE . ' p ' .
            'JOIN ' . self::CUSTOMER_OPTION_TYPE_TABLE . ' t ON p.id=t.permid ' .
            'JOIN ' . self::CUSTOMER_OPTION_TABLE . ' o ON t.optiontypeid=o.optiontypeid ' .
            'WHERE o.adr_id=? AND t.isactive="yes" AND o.option_is_set="yes"';

        return $this->db->getlist($sql, array($CustomerID));
    }

    /**
     * @param int $adrid
     * @param array $cust
     * @return int
     */
    public static function get_gracetime_days($adrid = 0, $cust = array())
    {
        global $db;

        $ret = array();

        if (!empty($cust))
            $adrid = $cust['adr_id'];

        if ($adrid == 0) {
            $ret = $db->get('SELECT * FROM ' . PAYMENT_TERMS_TABLE . ' WHERE is_active="yes" AND is_default="yes"');
            if (empty($ret))
                return 0;
        }

        if (empty($cust))
            $cust = $db->get('SELECT payment_term_id FROM ' . self::CUSTOMER_TABLE . ' WHERE adr_id=' . $adrid);

        if (empty($cust))
            return 0;

        if ($cust['payment_term_id'] > 0) {
            $ret = $db->get('SELECT * FROM ' . PAYMENT_TERMS_TABLE . ' WHERE payment_term_id=' . $cust['payment_term_id']);
            if (!empty($ret))
                return $ret['days_of_grace'];
        }

        $ret = $db->get('SELECT * FROM ' . PAYMENT_TERMS_TABLE . ' WHERE is_active="yes" AND is_default="yes"');
        if (empty($ret))
            return 0;
        else
            return $ret['days_of_grace'];
    }


}
