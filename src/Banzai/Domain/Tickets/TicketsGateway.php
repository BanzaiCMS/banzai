<?php

namespace Banzai\Domain\Tickets;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Users\UsersGateway;

class TicketsGateway
{
    // actual ones that are in use
    const string TICKET_TABLE = 'tickets';
    const string TICKET_TAG_TABLE = 'ticket_tag';
    const string TICKETTYPE_TABLE = 'ticket_type';
    const string TICKETPRIO_TABLE = 'ticket_priority';
    const string TICKETSTATE_TABLE = 'ticket_state';
    const string TICKETSOURCE_TABLE = 'ticket_source';
    const string TICKETPROD_TABLE = 'ticket_product';
    const string TICKETQUEUE_TABLE = 'ticket_queue';
    const string TICKETTRANSACTION_TABLE = 'ticket_transaction';
    const string TICKETTRANSACTION_TYPE_TABLE = 'ticket_transaction_type';

    // older one, deprecated
    const string TICKETHIST_TABLE = 'ticket_history';


    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }

    public function getTransactionTypeID(string $code): int
    {
        $ret = $this->db->get('SELECT ticket_transaction_type_id FROM ' . self::TICKETTRANSACTION_TYPE_TABLE . ' WHERE ticket_transaction_type_code=?', array($code));
        if (empty($ret))
            return 0;
        else
            return $ret['ticket_transaction_type_id'];
    }

    public function getSourceID(string $code): int
    {
        $ret = $this->db->get('SELECT ticket_source_id FROM ' . self::TICKETSOURCE_TABLE . ' WHERE ticket_source_code=?', array($code));
        if (empty($ret))
            return 0;
        else
            return $ret['ticket_source_id'];
    }


    public function getTicketTransactionList(int $TicketID): array
    {

        return $this->db->getlist(
            'SELECT * FROM ' . self::TICKETTRANSACTION_TABLE . ' t ' .
            'LEFT JOIN ' . self::TICKETTRANSACTION_TYPE_TABLE . ' y ON t.ticket_transaction_type_id=y.ticket_transaction_type_id ' .
            'LEFT JOIN ' . self::TICKETSOURCE_TABLE . ' s ON t.ticket_source_id=s.ticket_source_id ' .
            'LEFT JOIN ' . UsersGateway::USER_TABLE . ' u on t.ticket_transaction_creator_id=u.user_id ' .
            'WHERE ticket_id=? ORDER BY ticket_transaction_created', array($TicketID));

    }

    public function getTicketList(int $CustomerID): array
    {
        return $this->db->getlist(

            'SELECT * FROM ' . self::TICKET_TABLE . ' t ' .
            'LEFT JOIN ' . self::TICKETSOURCE_TABLE . ' q ON t.ticket_source_id=q.ticket_source_id ' .
            'LEFT JOIN ' . self::TICKETSTATE_TABLE . ' s ON t.ticket_state_id=s.ticket_state_id ' .
            'LEFT JOIN ' . UsersGateway::USER_TABLE . ' u ON t.assigned_to_user_id=u.user_id ' .
            'WHERE t.customer_id=?', array($CustomerID));
    }

}
