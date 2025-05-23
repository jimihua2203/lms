<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2019 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

/**
 * LMSEventManager
 *
 */
class LMSEventManager extends LMSManager implements LMSEventManagerInterface
{
    public function GetTimetableRange()
    {
        return $this->db->GetRow('SELECT MIN(date) AS fromdate, MAX(date) AS todate FROM events');
    }

    public function EventAdd($event)
    {
        $args = array(
            'title' => $event['title'],
            'description' => Utils::removeInsecureHtml($event['description']),
            'date' => $event['date'],
            'begintime' => $event['begintime'],
            'enddate' => $event['enddate'],
            'endtime' => $event['endtime'],
            SYSLOG::RES_USER => Auth::GetCurrentUser(),
            'private' => $event['private'],
            'closed' => isset($event['close']) ? 1 : 0,
            SYSLOG::RES_CUST => empty($event['custid']) ? null : $event['custid'],
            'type' => $event['type'],
            SYSLOG::RES_ADDRESS => empty($event['address_id']) ? null : $event['address_id'],
            SYSLOG::RES_NODE => $event['nodeid'],
            SYSLOG::RES_TICKET => empty($event['ticketid']) ? null : $event['ticketid'],
            SYSLOG::RES_NETNODE => empty($event['netnodeid']) ?
                null : $event['netnodeid'],
            SYSLOG::RES_NETDEV => empty($event['netdevid']) ?
                null : $event['netdevid'],
        );

        $this->db->BeginTrans();

        $this->db->Execute(
            'INSERT INTO events (title, description, date, begintime, enddate,
                endtime, userid, creationdate, private, closed, customerid, type, address_id, nodeid,
                ticketid, netnodeid, netdevid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?NOW?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($args)
        );

        $id = $this->db->GetLastInsertID('events');

        if ($this->syslog) {
            $args[SYSLOG::RES_EVENT] = $id;
            unset($args[SYSLOG::RES_USER]);
            $this->syslog->AddMessage(SYSLOG::RES_EVENT, SYSLOG::OPER_ADD, $args);
        }

        if (!empty($event['userlist'])) {
            foreach ($event['userlist'] as $userid) {
                $args = array(
                    SYSLOG::RES_EVENT => $id,
                    SYSLOG::RES_USER => $userid,
                );
                $this->db->Execute('INSERT INTO eventassignments (eventid, userid)
					VALUES (?, ?)', array_values($args));
                if ($this->syslog) {
                    $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_ADD, $args);
                }
            }
        }

        if (!empty($event['ticketid'])) {
            $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache);
            $ticketqueue = $helpdesk_manager->GetQueueByTicketId($event['ticketid']);
            $messageid = '<msg.' . $ticketqueue['id'] . '.' . $event['ticketid'] . '.' . time() . '@rtsystem.' . gethostname() . '>';
            $messagebody = trans('Assigned event ($a) was created.', $a = $id);

            $helpdesk_manager->TicketMessageAdd(array(
                'ticketid' => $event['ticketid'],
                'messageid' => $messageid,
                'body' => $messagebody,
                'type' => RTMESSAGE_ASSIGNED_EVENT_ADD,
            ));
        }

        $this->db->CommitTrans();

        return $id;
    }

    public function EventUpdate($event)
    {
        $args = array(
            'title' => $event['title'],
            'description' => Utils::removeInsecureHtml($event['description']),
            'date' => $event['date'],
            'begintime' => $event['begintime'],
            'enddate' => $event['enddate'],
            'endtime' => $event['endtime'],
            'private' => $event['private'],
            'note' => $event['note'],
            'closed' => isset($event['close']) ? 1 : 0,
            SYSLOG::RES_CUST => empty($event['custid']) ? null : $event['custid'],
            'type' => $event['type'],
            SYSLOG::RES_ADDRESS => empty($event['address_id']) ? null : $event['address_id'],
            SYSLOG::RES_NODE => empty($event['nodeid']) ? null : $event['nodeid'],
            SYSLOG::RES_TICKET => empty($event['helpdesk']) ? null : $event['helpdesk'],
            'moddate' => time(),
            'mod' . SYSLOG::getResourceKey(SYSLOG::RES_USER) => Auth::getCurrentUser(),
            SYSLOG::RES_NETNODE => empty($event['netnodeid']) ?
                null : $event['netnodeid'],
            SYSLOG::RES_NETDEV => empty($event['netdevid']) ?
                null : $event['netdevid'],
            SYSLOG::RES_EVENT => $event['id'],
        );

        $this->db->BeginTrans();

        $this->db->Execute(
            'UPDATE events SET title = ?, description = ?, date = ?, begintime = ?, enddate = ?, endtime = ?, private = ?,
                note = ?, closed = ?, customerid = ?, type = ?, address_id = ?, nodeid = ?, ticketid = ?, moddate = ?, moduserid = ?,
                netnodeid = ?, netdevid = ? WHERE id = ?',
            array_values($args)
        );

        if ($this->syslog) {
            $this->syslog->AddMessage(
                SYSLOG::RES_EVENT,
                SYSLOG::OPER_UPDATE,
                $args,
                array('mod' . SYSLOG::getResourceKey(SYSLOG::RES_USER))
            );
            $users = $this->db->GetCol('SELECT userid FROM eventassignments WHERE eventid = ?', array($event['id']));
            if (!empty($users)) {
                foreach ($users as $userid) {
                    $args = array(
                        SYSLOG::RES_EVENT => $event['id'],
                        SYSLOG::RES_USER => $userid,
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_DELETE, $args);
                }
            }
        }

        $this->db->Execute('DELETE FROM eventassignments WHERE eventid = ?', array($event['id']));
        if (!empty($event['userlist']) && is_array($event['userlist'])) {
            foreach ($event['userlist'] as $userid) {
                $this->db->Execute(
                    'INSERT INTO eventassignments (eventid, userid) VALUES (?, ?)',
                    array($event['id'], $userid)
                );
                if ($this->syslog) {
                    $args = array(
                        SYSLOG::RES_EVENT => $event['id'],
                        SYSLOG::RES_USER => $userid,
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_ADD, $args);
                }
            }
        }

        if (!empty($event['helpdesk'])) {
            $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache);
            $ticketqueue = $helpdesk_manager->GetQueueByTicketId($event['ticketid']);
            $messageid = '<msg.' . $ticketqueue['id'] . '.' . $event['helpdesk'] . '.' . time() . '@rtsystem.' . gethostname() . '>';
            $messagebody = trans('Assigned event ($a) was modified.', $a = $event['id']);

            $helpdesk_manager->TicketMessageAdd(array(
                'ticketid' => $event['helpdesk'],
                'messageid' => $messageid,
                'body' => $messagebody,
                'type' => RTMESSAGE_ASSIGNED_EVENT_CHANGE,
            ));
        }

        $this->db->CommitTrans();
    }

    public function EventDelete($id)
    {
        $event = $this->db->GetRow('SELECT id, customerid, address_id, nodeid, ticketid FROM events WHERE id = ?', array($id));
        if ($event) {
            if ($this->syslog) {
                $args = array(
                    SYSLOG::RES_EVENT => $id,
                    SYSLOG::RES_CUST => $event['customerid'],
                    SYSLOG::RES_ADDRESS => $event['address_id'],
                    SYSLOG::RES_NODE => $event['nodeid'],
                    SYSLOG::RES_TICKET => $event['ticketid'],
                );
                $this->syslog->AddMessage(SYSLOG::RES_EVENT, SYSLOG::OPER_DELETE, $args);
                $users = $this->db->GetCol('SELECT userid FROM eventassignments WHERE eventid = ?', array($id));
                if ($users) {
                    foreach ($users as $userid) {
                        $args = array(
                            SYSLOG::RES_EVENT => $id,
                            SYSLOG::RES_USER => $userid,
                        );
                        $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_DELETE, $args);
                    }
                }
            }
            $this->db->Execute('DELETE FROM events WHERE id = ?', array($id));
            $this->db->Execute('DELETE FROM eventassignments WHERE eventid = ?', array($id));

            if (!empty($event['ticketid'])) {
                $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache);
                $ticketqueue = $helpdesk_manager->GetQueueByTicketId($event['ticketid']);
                $messageid = '<msg.' . $ticketqueue['id'] . '.' . $event['ticketid'] . '.' . time() . '@rtsystem.' . gethostname() . '>';
                $messagebody = trans('Assigned event ($a) was deleted.', $a = $id);

                $helpdesk_manager->TicketMessageAdd(array(
                    'ticketid' => $event['ticketid'],
                    'messageid' => $messageid,
                    'body' => $messagebody,
                    'type' => RTMESSAGE_ASSIGNED_EVENT_DELETE,
                ));
            }
        }
    }

    public function GetEvent($id)
    {
        $event = $this->db->GetRow('SELECT e.id AS id, title, e.description, note, userid, e.creationdate,
			e.customerid, date, begintime, enddate, endtime, private, closed, e.type,'
            . $this->db->Concat('UPPER(c.lastname)', "' '", 'c.name') . ' AS customername,
			e.netnodeid, nn.name AS netnode_name, vd.address AS netnode_location,
			e.netdevid, nd.name AS netdevice_name,
			vusers.name AS username, e.moddate, e.moduserid, e.closeddate, e.closeduserid,
			e.address_id, va.location, e.nodeid, n.name AS node_name, n.location AS node_location, '
            . $this->db->Concat('c.city', "', '", 'c.address') . ' AS customerlocation,
			(SELECT name FROM vusers WHERE id=e.moduserid) AS modusername,
			(SELECT name FROM vusers WHERE id=e.closeduserid) AS closedusername, ticketid
			FROM events e
			LEFT JOIN vaddresses va ON va.id = e.address_id
			LEFT JOIN vnodes n ON (e.nodeid = n.id)
			LEFT JOIN customerview c ON (c.id = customerid)
			LEFT JOIN vusers ON (vusers.id = userid)
			LEFT JOIN rttickets rtt ON (rtt.id = e.ticketid)
			LEFT JOIN netnodes nn ON (nn.id = e.netnodeid)
			LEFT JOIN netdevices nd ON (nd.id = e.netdevid)
			LEFT JOIN vaddresses vd ON (vd.id = nn.address_id)
			WHERE e.id = ?', array($id));

        if (empty($event)) {
            return array();
        }

        if (!empty($event['customerid'])) {
            $customer_manager = new LMSCustomerManager($this->db, $this->auth, $this->cache);
            if (empty($event['node_location'])) {
                $event['node_location'] = $customer_manager->getAddressForCustomerStuff($event['customerid']);
            }
            $event['phones'] = $customer_manager->GetCustomerContacts($event['customerid'], CONTACT_MOBILE | CONTACT_LANDLINE);
            $event['phones'] = array_filter($event['phones'], function ($contact) {
                return !($contact['type'] & CONTACT_DISABLED);
            });
        }

        if (!empty($event['ticketid'])) {
            $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache);
            $event['ticket'] = $helpdesk_manager->getTickets($event['ticketid']);
        }

        $event['wholedays'] = $event['endtime'] == 86400;
        $event['multiday'] = false;

        if ($event['enddate'] && ($event['enddate'] - $event['date'])) {
            $event['multiday'] = round(($event['enddate'] - $event['date']) / 86400) > 0;
        }

        $event['helpdesk'] = !empty($event['ticketid']);

        $event['userlist'] = $this->db->GetAllByKey(
            'SELECT u.id, u.rname, u.name, u.login
            FROM vusers u
            JOIN eventassignments a ON a.userid = u.id
            WHERE a.eventid = ?',
            'id',
            array($id)
        );
        if (empty($event['userlist'])) {
            $event['userlist'] = array();
        }

        return $event;
    }

    /**
     * @param array $params associative array of parameters described below:
     *      year - start date year (default: null = today's year): single integer value,
     *      month - start date month (default: null = today's month): single interget value,
     *      day - start date day (default: null = today's day): single integer value,
     *      forward - for how many days get events (default: 0 = undefined): single integer value,
     *          -1 = open overdued events till midnight,
     *      customerid - customer id assigned to events (default: 0 = any): single integer value,
     *      userand - if all users should be assigned simultaneously (default: 0 = OR): single integer value:
     *          1 = AND,
     *      userid - user id assigned to events (default: 0 or null = any):
     *          array() of integer values or single integer value,
     *          -1 = events not assigned to any user,
     *      type - event type (default: 0 = any):
     *          array() of integer values or single integer value,
     *      privacy - event privacy flag (default: 0 = public events or private ones assigned to current user):
     *          single integer value,
     *          1 = public events,
     *          2 = private events assigned to current user,
     *      singleday - only one day presentation - helpfull for event print for given day
     *      closed - event close flag (default: '' = any value): single integer value or empty string,
     *      netnodeid - event with assigned network node,
     *      netdevid - event with assigned network device,
     *      count - count records only or return selected record interval
     *          true - count only,
     *          false - get records,
     *      offset - first returned record (null = 0),
     *      limit - returned record count (null = unlimited),
     * @return mixed
     */
    public function GetEventList(array $params)
    {
        extract($params);
        foreach (array('year', 'month', 'day') as $var) {
            if (!isset(${$var})) {
                ${$var} = null;
            }
        }
        foreach (array('forward', 'customerid', 'userid', 'type', 'privacy') as $var) {
            if (!isset(${$var})) {
                ${$var} = 0;
            }
        }
        if (!isset($closed)) {
            $closed = '';
        }
        if (!isset($count)) {
            $count = false;
        }
        $singleday = isset($singleday) && $singleday;

        $t = time();

        if (!isset($year)) {
            $year = date('Y', $t);
        }
        if (!isset($month)) {
            $month = date('n', $t);
        }
        if (!isset($day)) {
            $day = date('j', $t);
        }

        $current_user_id = intval(Auth::GetCurrentUser());

        $event_user_assignments = ConfigHelper::checkConfig('timetable.use_event_assignments_for_privacy_flag');

        switch ($privacy) {
            case 0:
                if ($event_user_assignments) {
                    $privacy_condition = ' AND (private = 0 OR (private = 1 AND (userid = ' . $current_user_id . ' OR EXISTS (SELECT 1 FROM eventassignments WHERE eventassignments.eventid = events.id AND eventassignments.userid = ' . $current_user_id . '))))';
                } else {
                    $privacy_condition = ' AND (private = 0 OR (private = 1 AND userid = ' . $current_user_id . '))';
                }
                break;
            case 1:
                $privacy_condition = ' AND private = 0';
                break;
            case 2:
                if ($event_user_assignments) {
                    $privacy_condition = ' AND private = 1 AND (userid = ' . $current_user_id . ' OR EXISTS (SELECT 1 FROM eventassignments WHERE eventassignments.eventid = events.id AND eventassignments.userid = ' . $current_user_id . '))';
                } else {
                    $privacy_condition = ' AND private = 1 AND userid = ' . $current_user_id;
                }
                break;
            default:
                $privacy_condition = '';
                break;
        }

        if ($forward=='-1') {
            $closed = 0;
            $overduefilter = ' AND closed = 0 ';
            $startdate = 0;
            $enddate = strtotime("midnight", $t);
        } else {
            $overduefilter = '';
            $startdate = mktime(0, 0, 0, $month, $day, $year);
            $enddate = mktime(0, 0, 0, $month, $day+$forward, $year);
        }

        if ($closed != '') {
            $closedfilter = ' AND closed = '.intval($closed);
        } else {
            $closedfilter = '';
        }

        $netdevfilter = empty($netdevid) ? '' : ' AND events.netdevid = ' . intval($netdevid);
        $netnodefilter = empty($netnodeid) ? '' : ' AND events.netnodeid = ' . intval($netnodeid);

        if (empty($userid)) {
            $userfilter = '';
        } else {
            if (is_array($userid)) {
                if (!empty($userand)) {
                    $userfilter = ' AND (EXISTS (SELECT COUNT(userid), eventid FROM eventassignments WHERE eventid = events.id AND userid IN ('
                        . implode(',', $userid) . ') GROUP BY eventid HAVING(COUNT(eventid) = ' . count($userid) . '))
                        ' . (in_array('-1', $userid) ? ' AND NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)' : '') . ')';
                } else {
                    $userfilter = ' AND (EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id AND userid IN (' . implode(',', $userid) . '))
                        ' . (in_array('-1', $userid) ? ' OR NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)' : '') . ')';
                }
            } else {
                $userid = intval($userid);
                if ($userid == -1) {
                    $userfilter = ' AND NOT EXISTS (SELECT 1 FROM eventassignments WHERE eventid = events.id)';
                } else {
                    $userfilter = ' AND EXISTS ( SELECT 1 FROM eventassignments WHERE eventid = events.id AND userid = ' . $userid . ')';
                }
            }
        }

        if ($count) {
            return $this->db->GetOne(
                'SELECT COUNT(events.id)
				FROM events
				LEFT JOIN vaddresses va ON va.id = events.address_id
				LEFT JOIN vnodes as vn ON (nodeid = vn.id)
				LEFT JOIN customerview c ON (events.customerid = c.id)
				LEFT JOIN vusers ON (userid = vusers.id)
				WHERE ((date >= ? AND date < ?) OR (enddate != 0 AND date < ? AND enddate >= ?))'
                . $privacy_condition
                . ($customerid ? ' AND events.customerid = '.intval($customerid) : '')
                . $userfilter
                . $netnodefilter
                . $netdevfilter
                . $overduefilter
                . (!empty($type) ? ' AND events.type ' . (is_array($type) ? 'IN (' . implode(',', Utils::filterIntegers($type)) . ')' : '=' . intval($type)) : '')
                . $closedfilter,
                array($startdate, $enddate, $enddate, $startdate)
            );
        }

        $list = $this->db->GetAll(
            'SELECT events.id AS id, title, note, events.description, date, begintime, enddate, endtime, events.customerid AS customerid, closed, events.type, '
                . $this->db->Concat('UPPER(c.lastname)', "' '", 'c.name').' AS customername, events.netnodeid, nn.name AS netnode_name, vd.address AS netnode_location,
				userid, vusers.name AS username, ' . $this->db->Concat('c.city', "', '", 'c.address').' AS customerlocation, closeddate,
				events.address_id, va.location, events.nodeid as nodeid, vn.location AS nodelocation, ticketid, events.netdevid, nd.name AS netdev_name, cc.customerphone
			FROM events
			LEFT JOIN vaddresses va ON va.id = events.address_id
			LEFT JOIN vnodes as vn ON (nodeid = vn.id)
			LEFT JOIN customerview c ON (events.customerid = c.id)
			LEFT JOIN vusers ON (userid = vusers.id)
			LEFT JOIN rttickets rtt ON (rtt.id = events.ticketid)
			LEFT JOIN netnodes nn ON (nn.id = events.netnodeid)
			LEFT JOIN netdevices nd ON (nd.id = events.netdevid)
			LEFT JOIN vaddresses vd ON (vd.id = nn.address_id)
            LEFT JOIN (
                SELECT ' . $this->db->GroupConcat('contact', ', ') . ' AS customerphone, customerid
                FROM customercontacts
                WHERE type & ? > 0 AND type & ? = 0
                GROUP BY customerid
            ) cc ON cc.customerid = c.id
			WHERE ((date >= ? AND date < ?) OR (enddate != 0 AND date < ? AND enddate >= ?))'
            . $privacy_condition
            .($customerid ? ' AND events.customerid = '.intval($customerid) : '')
            . $userfilter
            . $netnodefilter
            . $netdevfilter
            . $overduefilter
            . (!empty($type) ? ' AND events.type ' . (is_array($type) ? 'IN (' . implode(',', Utils::filterIntegers($type)) . ')' : '=' . intval($type)) : '')
            . $closedfilter
            .' ORDER BY date, begintime, events.type'
            . (isset($limit) ? ' LIMIT ' . $limit : '')
            . (isset($offset) ? ' OFFSET ' . $offset : ''),
            array(
                CONTACT_MOBILE | CONTACT_FAX | CONTACT_LANDLINE, CONTACT_DISABLED,
                $startdate,
                $enddate,
                $enddate,
                $startdate
            )
        );
        $list2 = array();
        $customerstuffaddresses = array();
        if ($list) {
            foreach ($list as $idx => $row) {
                if (!empty($row['nodeid']) && empty($row['nodelocation'])) {
                    if (!isset($customer_manager)) {
                        $customer_manager = new LMSCustomerManager($this->db, $this->auth, $this->cache);
                    }
                    if (!isset($customerstuffaddresses[$row['customerid']])) {
                        $customerstuffaddresses[$row['customerid']] = $customer_manager->getAddressForCustomerStuff($row['customerid']);
                    }
                    $row['nodelocation'] = $customerstuffaddresses[$row['customerid']];
                }

                $row['userlist'] = $this->db->GetAll(
                    'SELECT userid AS id, vusers.name,
                    vusers.access, vusers.deleted, vusers.accessfrom, vusers.accessto
                    FROM eventassignments, vusers
                    WHERE userid = vusers.id AND eventid = ? ',
                    array($row['id'])
                );

                $endtime = $row['endtime'];

                $row['wholeday'] = $endtime == 86400;
                $row['multiday'] = false;

                if ($row['enddate'] && ($row['enddate'] - $row['date'])) {
                    $days = round(($row['enddate'] - $row['date']) / 86400);
                    $row['multiday'] = $days > 0;
                    $row['enddate'] = $row['date'] + 86400;
                    //$row['endtime'] = 0;
                    $list2[] = $row;
                    if (!$singleday) {
                        $dst = date('I', $row['date']);
                        while ($days) {
                            //if ($days == 1) {
                                $row['endtime'] = $endtime;
                            //}
                            $row['date'] += 86400;
                            $newdst = date('I', $row['date']);
                            if ($newdst != $dst) {
                                if ($newdst < $dst) {
                                    $row['date'] += 3600;
                                } else {
                                    $row['date'] -= 3600;
                                }
                                $newdst = date('I', $row['date']);
                            }
                            [$year, $month, $day] = explode('/', date('Y/n/j', $row['date']));
                            $row['date'] = mktime(0, 0, 0, $month, $day, $year);
                            $row['enddate'] = $row['date'] + 86400;
                            if ($days > 1 || $endtime) {
                                $list2[] = $row;
                            }
                            $days--;
                            $dst = $newdst;
                        }
                    }
                } else {
                    $list2[] = $row;
                }
            }
        }
        unset($t);
        return $list2;
    }

    public function EventSearch($search, $order = 'date,asc', $simple = false)
    {
        [$order, $direction] = sscanf($order, '%[^,],%s');

        (strtolower($direction) != 'desc') ? $direction = 'ASC' : $direction = 'DESC';

        switch ($order) {
            default:
                $sqlord = ' ORDER BY date ' . $direction . ', begintime ' . $direction . ', events.type';
                break;
        }

        $datefrom = isset($search['datefrom']) ? intval($search['datefrom']) : 0;
        $dateto = isset($search['dateto']) ? intval($search['dateto']) : 0;
        $ticketid = isset($search['ticketid']) ? intval($search['ticketid']) : 0;

        if (empty($search['type'])) {
            $types = array();
        } elseif (is_array($search['type'])) {
            $types = $search['type'];
        } else {
            $types = array($search['type']);
        }
        $types = Utils::filterIntegers($types);

        $list = $this->db->GetAll(
            'SELECT events.id AS id, title, description, date, begintime, enddate, endtime, customerid, closed, events.type, events.ticketid, events.note, '
                . $this->db->Concat('customers.lastname', "' '", 'customers.name') . ' AS customername,
                (endtime - begintime) AS total_time
            FROM events
            LEFT JOIN customers ON (customerid = customers.id)
            WHERE (private = 0 OR (private = 1 AND userid = ?)) '
                . ($datefrom ? " AND (date >= $datefrom OR (enddate <> 0 AND enddate >= $datefrom))" : '')
                . ($dateto ? " AND (date <= $dateto OR (enddate <> 0 AND enddate <= $dateto))" : '')
                . (!empty($search['customerid']) ? ' AND customerid = ' . intval($search['customerid']) : '')
                . (empty($types) ? '' : ' AND events.type IN (' . implode(',', $types) . ')')
                . ($ticketid ? " AND ticketid = " . $ticketid : '')
                . (isset($search['closed']) ? ($search['closed'] == '' ? '' : ' AND closed = ' . intval($search['closed'])) : ' AND closed = 0')
                . (!empty($search['title']) ? ' AND title ?LIKE? ' . $this->db->Escape('%' . $search['title'] . '%') : '')
                . (!empty($search['description']) ? ' AND description ?LIKE? ' . $this->db->Escape('%' . $search['description'] . '%') : '')
                . (!empty($search['note']) ? ' AND note ?LIKE? ' . $this->db->Escape('%' . $search['note'] . '%') : '')
            . $sqlord,
            array(Auth::GetCurrentUser())
        );

        if (isset($search['userid'])) {
            if (is_array($search['userid'])) {
                $users = array_filter($search['userid'], 'is_natural');
            } else {
                $users = array(intval($search['userid']));
            }
        } else {
            $users = array();
        }

        $list2 = $list3 = array();
        if ($list) {
            foreach ($list as $row) {
                if (!$simple) {
                    $row['userlist'] = $this->db->GetAll(
                        'SELECT
                            userid AS id,
                            vusers.name
                        FROM eventassignments
                        JOIN vusers ON vusers.id = userid
                        WHERE eventid = ? ',
                        array($row['id'])
                    );
                }
                $endtime = $row['endtime'];

                $userfilter = false;
                if (!empty($users) && !empty($row['userlist'])) {
                    foreach ($row['userlist'] as $user) {
                        if (in_array($user['id'], $users)) {
                            $userfilter = true;
                        }
                    }
                }

                if ($row['enddate']) {
                    $days = intval(($row['enddate'] - $row['date']) / 86400);
                    //$row['endtime'] = 0;
                    if ((!$datefrom || $row['date'] >= $datefrom) &&
                        (!$dateto || $row['date'] <= $dateto)) {
                        $list2[] = $row;
                        if ($userfilter) {
                            $list3[] = $row;
                        }
                    }

                    while ($days) {
                        //if ($days == 1)
                        $row['endtime'] = $endtime;
                        $row['date'] += 86400;

                        if ((!$datefrom || $row['date'] >= $datefrom) &&
                            (!$dateto || $row['date'] <= $dateto)) {
                            $list2[] = $row;
                            if ($userfilter) {
                                $list3[] = $row;
                            }
                        }

                        $days--;
                    }
                } else if ((!$datefrom || $row['date'] >= $datefrom) &&
                    (!$dateto || $row['date'] <= $dateto)) {
                    $list2[] = $row;
                    if ($userfilter) {
                        $list3[] = $row;
                    }
                }
            }

            if (isset($search['userid'])) {
                return $list3;
            } else {
                return $list2;
            }
        }
    }

    public function GetCustomerIdByTicketId($id)
    {
        return $this->db->GetOne('SELECT customerid FROM rttickets WHERE id=?', array($id));
    }

    /**
     * @param array $params associative array of parameters described below:
     *      users - event user assignments: array() of integer values,
     *          empty array() means empty overlapping user set,
     *      begindate - event start date in unix timestamp format,
     *      enddate - event end date in unix timestamp format,
     *      begintime - event start time in HHMM format,
     *      endtime - event end time in HHMM format,
     * @return mixed - users assigned to events taking $params into account;
     *      users parameter means user set to test
     */
    public function EventOverlaps(array $params)
    {
        $users = array();

        if (empty($params['users'])) {
            return $users;
        }

        extract($params);
        if (empty($enddate)) {
            $enddate = $date;
        }
        $users = Utils::filterIntegers($users);

        return $this->db->GetCol(
            'SELECT DISTINCT a.userid FROM events e
                        JOIN eventassignments a ON a.eventid = e.id
                        WHERE ' . (isset($params['ignoredevent']) ? 'e.id <> ' . intval($params['ignoredevent']) . ' AND ' : '')
                            . 'a.userid IN (' . implode(',', $users) . ')
                                AND (date < ? OR (date = ? AND begintime < ?))
                                AND (enddate > ? OR (enddate = ? AND endtime > ?))',
            array($enddate, $enddate, $endtime, $date, $date, $begintime)
        );
    }

    public function AssignUserToEvent($id, $userid)
    {
        if (!$this->db->GetOne('SELECT eventid FROM eventassignments WHERE eventid = ? AND userid = ?', array($id, $userid))) {
            $this->db->Execute('INSERT INTO eventassignments (eventid, userid) VALUES (?, ?)', array($id, $userid));
            if ($this->syslog) {
                $args = array(
                    SYSLOG::RES_EVENT => $id,
                    SYSLOG::RES_USER => $userid,
                );
                $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_ADD, $args);
            }
        }
    }

    public function UnassignUserFromEvent($id, $userid)
    {
        $this->db->Execute('DELETE FROM eventassignments WHERE eventid = ? AND userid = ?', array($id, $userid));
        if ($this->syslog) {
            $args = array(
                SYSLOG::RES_EVENT => $id,
                SYSLOG::RES_USER => $userid,
            );
            $this->syslog->AddMessage(SYSLOG::RES_EVENTASSIGN, SYSLOG::OPER_ADD, $args);
        }
    }

    public function MoveEvent($id, $delta)
    {
        $res = $this->db->Execute('UPDATE events SET date = date + ? WHERE id = ?', array($delta, $id));
        return $res && $this->db->Execute('UPDATE events SET enddate = enddate + ? WHERE id = ? AND enddate > 0', array($delta, $id));
    }
}
