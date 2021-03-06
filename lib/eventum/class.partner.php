<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

use Eventum\Db\DatabaseException;

/**
 * Handles the interactions between Eventum and partner backends.
 */
class Partner
{
    /**
     * Includes the appropriate partner backend class associated with the
     * given project ID, instantiates it and returns the class.
     *
     * @param   string $par_code The partner code
     * @return  Abstract_Partner_Backend
     */
    private static function &getBackend($par_code)
    {
        static $setup_backends;

        if (empty($setup_backends[$par_code])) {
            $file_name = 'class.' . $par_code . '.php';
            $class_name = $par_code . '_Partner_Backend';

            if (file_exists(APP_LOCAL_PATH . "/partner/$file_name")) {
                /** @noinspection PhpIncludeInspection */
                require_once APP_LOCAL_PATH . "/partner/$file_name";
            } else {
                /** @noinspection PhpIncludeInspection */
                require_once APP_INC_PATH . "/partner/$file_name";
            }

            $setup_backends[$par_code] = new $class_name();
        }

        return $setup_backends[$par_code];
    }

    /**
     * @static
     * @param $iss_id
     * @return Abstract_Partner_Backend[]
     */
    private static function getBackendsByIssue($iss_id)
    {
        $partners = self::getPartnerCodesByIssue($iss_id);
        $backends = [];
        foreach ($partners as $par_code) {
            $backends[] = self::getBackend($par_code);
        }

        return $backends;
    }

    public static function selectPartnersForIssue($iss_id, $partners)
    {
        if (!is_array($partners)) {
            $partners = [];
        }
        $old_partners = self::getPartnersByIssue($iss_id);
        foreach ($partners as $par_code) {
            self::addPartnerToIssue($iss_id, $par_code);
            unset($old_partners[$par_code]);
        }

        // remove any unselected partners
        foreach ($old_partners as $par_code => $partner) {
            self::removePartnerFromIssue($iss_id, $par_code);
        }

        return 1;
    }

    public static function addPartnerToIssue($iss_id, $par_code)
    {
        $current_partners = self::getPartnerCodesByIssue($iss_id);
        if (!in_array($par_code, $current_partners)) {
            $params = [$iss_id, $par_code, Date_Helper::getCurrentDateGMT()];
            $sql = 'INSERT INTO
                        {{%issue_partner}}
                    SET
                        ipa_iss_id = ?,
                        ipa_par_code = ?,
                        ipa_created_date = ?';
            try {
                DB_Helper::getInstance()->query($sql, $params);
            } catch (DatabaseException $e) {
                return false;
            }
            $backend = self::getBackend($par_code);
            $backend->issueAdded($iss_id);

            $usr_id = Auth::getUserID();
            History::add($iss_id, $usr_id, 'partner_added', "Partner '{partner}' added to issue by {user}", [
                'partner' => $backend->getName(),
                'user' => User::getFullName($usr_id)
            ]);
        }

        return true;
    }

    public static function removePartnerFromIssue($iss_id, $par_code)
    {
        $params = [$iss_id, $par_code];
        $sql = 'DELETE FROM
                    {{%issue_partner}}
                WHERE
                    ipa_iss_id = ? AND
                    ipa_par_code = ?';
        try {
            DB_Helper::getInstance()->query($sql, $params);
        } catch (DatabaseException $e) {
            return false;
        }
        $backend = self::getBackend($par_code);
        $backend->issueRemoved($iss_id);

        $usr_id = Auth::getUserID();
        History::add($iss_id, $usr_id, 'partner_removed', "Partner '{partner}' removed from issue by {user}", [
            'partner' => $backend->getName(),
            'user' => User::getFullName($usr_id)
        ]);

        return true;
    }

    public static function getPartnersByProject($prj_id)
    {
        $sql = 'SELECT
                    pap_par_code
                FROM
                    {{%partner_project}}
                WHERE
                    pap_prj_id = ?';

        try {
            $res = DB_Helper::getInstance()->getColumn($sql, [$prj_id]);
        } catch (DatabaseException $e) {
            return [];
        }

        $return = [];
        foreach ($res as $partner) {
            $return[$partner] = [
                'name'  =>  self::getName($partner),
            ];
        }

        return $return;
    }

    public static function getPartnerCodesByIssue($iss_id)
    {
        $prj_id = Issue::getProjectID($iss_id);
        $sql = 'SELECT
                    ipa_par_code
                FROM
                    {{%issue_partner}},
                    {{%partner_project}}
                WHERE
                    ipa_par_code = pap_par_code AND
                    pap_prj_id = ? AND
                    ipa_iss_id = ?';
        try {
            $partners = DB_Helper::getInstance()->getColumn($sql, [$prj_id, $iss_id]);
        } catch (DatabaseException $e) {
            return [];
        }

        return $partners;
    }

    public static function getPartnersByIssue($iss_id)
    {
        $partners = self::getPartnerCodesByIssue($iss_id);

        $return = [];
        foreach ($partners as $par_code) {
            $return[$par_code] = [
                'name'  =>  self::getName($par_code),
                'message'   =>  self::getIssueMessage($par_code, $iss_id),
            ];
        }

        return $return;
    }

    public static function isPartnerEnabledForIssue($par_code, $iss_id)
    {
        if (in_array($par_code, self::getPartnerCodesByIssue($iss_id))) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @static
     * Scans the directories for partner backends.
     *
     * @return  array
     */
    public static function getList()
    {
        $backends = self::getBackendList();
        $partners = [];
        foreach ($backends as $par_code) {
            $backend = self::getBackend($par_code);
            $partners[] = [
                'code'  =>  $par_code,
                'name'  =>  $backend->getName(),
                'projects'  =>  self::getProjectsForPartner($par_code),
            ];
        }

        return $partners;
    }

    public static function getAssocList()
    {
        $returns = [];
        foreach (self::getList() as $partner) {
            $returns[$partner['code']] = $partner['name'];
        }

        return $returns;
    }

    public static function getDetails($par_code)
    {
        return [
            'code'  =>  $par_code,
            'name'  =>  self::getBackend($par_code)->getName(),
            'projects'  =>  self::getProjectsForPartner($par_code),
        ];
    }

    public static function update($par_code, $projects)
    {

        // delete all first, then re-insert
        $sql = 'DELETE FROM
                    {{%partner_project}}
                WHERE
                    pap_par_code = ?';
        try {
            DB_Helper::getInstance()->query($sql, [$par_code]);
        } catch (DatabaseException $e) {
            return -1;
        }

        if (is_array($projects)) {
            foreach ($projects as $prj_id) {
                $sql = 'INSERT INTO
                            {{%partner_project}}
                        SET
                            pap_par_code = ?,
                            pap_prj_id = ?';
                try {
                    DB_Helper::getInstance()->query($sql, [$par_code, $prj_id]);
                } catch (DatabaseException $e) {
                    return -1;
                }
            }
        }

        return 1;
    }

    public static function getProjectsForPartner($par_code)
    {
        $sql = 'SELECT
                    pap_prj_id,
                    prj_title
                FROM
                    {{%partner_project}},
                    {{%project}}
                WHERE
                    pap_prj_id = prj_id AND
                    pap_par_code = ?';
        try {
            $res = DB_Helper::getInstance()->getPair($sql, [$par_code]);
        } catch (DatabaseException $e) {
            return [];
        }

        return $res;
    }

    /**
     * Returns a list of backends available
     *
     * @return  array An array of workflow backends
     */
    public static function getBackendList()
    {
        $files = Misc::getFileList(APP_INC_PATH . '/partner');
        $files = array_merge($files, Misc::getFileList(APP_LOCAL_PATH. '/partner'));
        $list = [];
        foreach ($files as $file) {
            // display a prettyfied backend name in the admin section
            if (preg_match('/^class\.(.*)\.php$/', $file, $matches)) {
                if (substr($matches[1], 0, 8) == 'abstract') {
                    continue;
                }
                $list[$file] = $matches[1];
            }
        }

        return $list;
    }

    public static function getName($par_code)
    {
        $backend = self::getBackend($par_code);

        return $backend->getName();
    }

    public static function getIssueMessage($par_code, $iss_id)
    {
        $backend = self::getBackend($par_code);

        return $backend->getIssueMessage($iss_id);
    }

    public static function handleNewEmail($iss_id, $sup_id)
    {
        foreach (self::getBackendsByIssue($iss_id) as $backend) {
            $backend->handleNewEmail($iss_id, $sup_id);
        }
    }

    public static function handleNewNote($iss_id, $not_id)
    {
        foreach (self::getBackendsByIssue($iss_id) as $backend) {
            $backend->handleNewNote($iss_id, $not_id);
        }
    }

    public static function handleIssueChange($iss_id, $usr_id, $old_details, $changes)
    {
        foreach (self::getBackendsByIssue($iss_id) as $backend) {
            $backend->handleIssueChange($iss_id, $usr_id, $old_details, $changes);
        }
    }

    /**
     * @static
     * @param $usr_id
     * @param string $feature create_issue, associate_emails, reports, export
     * @return bool
     */
    public static function canUserAccessFeature($usr_id, $feature)
    {
        $usr_details = User::getDetails($usr_id);
        if (!empty($usr_details['usr_par_code'])) {
            $backend = self::getBackend($usr_details['usr_par_code']);

            return $backend->canUserAccessFeature($usr_id, $feature);
        }

        return null;
    }

    /**
     * @param $usr_id
     * @param string $section partners, drafts, files, time, notes, phone, history, notification_list, authorized_repliers
     * @return bool
     */
    public static function canUserAccessIssueSection($usr_id, $section)
    {
        $usr_details = User::getDetails($usr_id);
        if (!empty($usr_details['usr_par_code'])) {
            $backend = self::getBackend($usr_details['usr_par_code']);

            return $backend->canUserAccessIssueSection($usr_id, $section);
        }

        return null;
    }

    /**
     * If the partner can edit the issue.
     *
     * @param integer   $issue_id
     * @param integer   $usr_id
     * @return bool
     */
    public static function canUpdateIssue($issue_id, $usr_id)
    {
        $usr_details = User::getDetails($usr_id);
        if (!empty($usr_details['usr_par_code'])) {
            $backend = self::getBackend($usr_details['usr_par_code']);

            return $backend->canUpdateIssue($issue_id, $usr_id);
        }

        return null;
    }
}
