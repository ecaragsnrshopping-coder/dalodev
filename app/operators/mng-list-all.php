<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Authors:    Liran Tal <liran@lirantal.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

    include("library/checklogin.php");
    $operator = $_SESSION['operator_user'];

    include('library/check_operator_perm.php');
    include_once('../common/includes/config_read.php');

    include_once("lang/main.php");
    include_once("../common/includes/validation.php");
    include("../common/includes/layout.php");

    // init logging variables
    $log = "visited page: ";
    $logAction = "";
    $logQuery = "performed query for all usernames on page: ";
    $logDebugSQL = "";

    // set session's page variable
    $_SESSION['PREV_LIST_PAGE'] = $_SERVER['REQUEST_URI'];

    // print HTML prologue
    $extra_js = array(
        "static/js/ajax.js",
        "static/js/ajaxGeneric.js",
        "static/js/pages_common.js"
    );

    $title = t('Intro','mnglistall.php');
    $help = t('helpPage','mnglistall');

    print_html_prologue($title, $langCode, array(), $extra_js);

    $hiddenPassword = (strtolower($configValues['CONFIG_IFACE_PASSWORD_HIDDEN']) == "yes");

    // Correct column definition for database sorting/headers
    $cols = array(
        "department" => 'Employee ID',
        "fullname"   => 'Full Name',
        "loc_pos"    => 'Location / Position',
        "username"   => t('all','Username'),
    );

    if (!$hiddenPassword) {
        $cols["auth"] = t('all','Password');
    }

    $colspan = count($cols);
    $half_colspan = intval($colspan / 2);

    $param_cols = array();
    foreach ($cols as $k => $v) { if (!is_int($k)) { $param_cols[$k] = $v; } }

    $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                in_array($_GET['orderBy'], array_keys($param_cols)))
             ? $_GET['orderBy'] : array_keys($param_cols)[0];

    $orderType = (array_key_exists('orderType', $_GET) && isset($_GET['orderType']) &&
                  preg_match(ORDER_TYPE_REGEX, $_GET['orderType']) !== false)
               ? strtolower($_GET['orderType']) : "asc";

    print_title_and_help($title, $help);
    echo '<div id="returnMessages"></div>';

    include('../common/includes/db_open.php');
    include('include/management/pages_common.php');

    $nested_condition1 = array( "rc.attribute='Auth-Type'", "rc.attribute LIKE '%%-Password'" );
    $sql_WHERE = array( "rc.username=ui.username" );
    $sql_WHERE[] = sprintf("(%s)", implode(" OR ", $nested_condition1));

    $_SESSION['reportTable'] = sprintf("%s AS rc LEFT JOIN %s AS ra ON ra.username=rc.username, %s AS ui",
                                       $configValues['CONFIG_DB_TBL_RADCHECK'], $configValues['CONFIG_DB_TBL_RADACCT'],
                                       $configValues['CONFIG_DB_TBL_DALOUSERINFO']);
    $_SESSION['reportQuery'] = " WHERE " . implode(" AND ", $sql_WHERE);
    $_SESSION['reportType'] = "usernameListGeneric";
    
    $_SESSION['reportQueryColumns'] = "ui.department AS 'Employee ID', CONCAT(ui.lastname, ', ', ui.firstname) AS 'Full Name', CONCAT(ui.city, ' / ', ui.company) AS 'Location / Position', rc.username AS 'Username', rc.value AS 'Password'";

    // SELECT query including ui.department and ui.city
    $sql = sprintf("SELECT ui.id AS id, ui.department, ui.firstname, ui.lastname, ui.city, ui.company,
                           rc.username AS username, rc.value AS auth, rc.attribute
                     FROM %s %s
                     GROUP BY rc.username", $_SESSION['reportTable'], $_SESSION['reportQuery']);
    $res = $dbSocket->query($sql);
    $logDebugSQL .= "$sql;\n";
    $numrows = $res->numRows();

    if ($numrows > 0) {
        include('include/management/pages_numbering.php');
        $drawNumberLinks = strtolower($configValues['CONFIG_IFACE_TABLES_LISTING_NUM']) == "yes" && $maxPage > 1;

        $sql .= sprintf(" ORDER BY %s %s LIMIT %s, %s", $orderBy, $orderType, $offset, $rowsPerPage);
        $res = $dbSocket->query($sql);
        $logDebugSQL .= "$sql;\n";

        $records = array();
        $usernamelist = array();

        while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
            $this_username = $row['username'];

            if (array_key_exists($this_username, $records)) {
                continue;
            }

            if ($row['attribute'] == 'Auth-Type' && $row['auth'] == 'Accept') {
                if (preg_match(MACADDR_REGEX, $this_username) || preg_match(IP_REGEX, $this_username)) {
                    $type = 'MAC';
                } else {
                    $type = 'PIN';
                }
            } else {
                $type = 'USER';
            }

            // Storing 'department' and 'city' into $records
            $records[$this_username] = array(
                'auth'       => $row['auth'],
                'department' => $row['department'],
                'firstname'  => $row['firstname'],
                'lastname'   => $row['lastname'],
                'city'       => $row['city'],
                'company'    => $row['company'],
                'enabled'    => true,
                'groups'     => array(),
                'type'       => $type,
                'id'         => $row['id']
            );
            $usernamelist[] = sprintf("'%s'", $dbSocket->escapeSimple($this_username));
        }

        $per_page_numrows = count($usernamelist);

        if ($per_page_numrows > 0) {
            $sql = sprintf("SELECT username, groupname FROM %s WHERE username IN (%s)",
                           $configValues['CONFIG_DB_TBL_RADUSERGROUP'], implode(", ", $usernamelist));
            $res = $dbSocket->query($sql);
            $logDebugSQL .= "$sql;\n";

            while ($row = $res->fetchRow(DB_FETCHMODE_ASSOC)) {
                $this_username = $row['username'];
                $this_groupname = $row['groupname'];

                if ($this_groupname === 'daloRADIUS-Disabled-Users') {
                    $records[$this_username]['enabled'] = false;
                } else {
                    array_push($records[$this_username]['groups'],
                               htmlspecialchars($this_groupname, ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        $action = "mng-del.php";

        $additional_controls = array();
        $additional_controls[] = array('onclick' => "javascript:removeCheckbox('listall','mng-del.php')", 'label' => 'Delete', 'class' => 'btn-danger');
        $additional_controls[] = array('onclick' => "disableCheckbox('listall','library/ajax/user_actions.php')", 'label' => 'Disable', 'class' => 'btn-primary');
        $additional_controls[] = array('onclick' => "enableCheckbox('listall','library/ajax/user_actions.php')", 'label' => 'Enable', 'class' => 'btn-secondary');
        $additional_controls[] = array('onclick' => "mailCheckbox('listall','library/ajax/user_actions.php')", 'label' => 'Send Mail', 'class' => 'btn-primary');

        $descriptors = array();
        $descriptors['start'] = array( 'common_controls' => 'username[]', 'additional_controls' => $additional_controls );

        $params = array(
            'num_rows' => $numrows,
            'rows_per_page' => $rowsPerPage,
            'page_num' => $pageNum,
            'order_by' => $orderBy,
            'order_type' => $orderType,
        );
        $descriptors['center'] = array( 'draw' => $drawNumberLinks, 'params' => $params );

        $descriptors['end'] = array();
        $descriptors['end'][] = array(
            'onclick' => "location.href='include/management/fileExport.php?reportFormat=csv'",
            'label' => 'CSV Export',
            'class' => 'btn-light',
        );
        print_table_prologue($descriptors);

        $form_descriptor = array( 'form' => array( 'action' => $action, 'method' => 'POST', 'name' => 'listall' ), );

        print_table_top($form_descriptor);
        printTableHead($cols, $orderBy, $orderType);
        print_table_middle();

        $count = 0;
        foreach ($records as $username => $data) {
            $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $type     = $data['type'];

            $img_format = '<i class="bi bi-%s-circle-fill text-%s me-1" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="%s"></i>';
            $img = (!$data['enabled'])
                 ? sprintf($img_format, 'dash', 'danger', 'disabled')
                 : sprintf($img_format, 'check', 'success', 'enabled');

            $badge_icon = ($type == 'PIN') ? "123" : (($type == 'MAC') ? "ethernet" : "person-fill");
            $badge = sprintf('<i class="bi bi-%s me-1" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="%s"></i>',
                             $badge_icon, strtolower($type));

            // Extract values and construct display fields
            $emp_id    = htmlspecialchars($data['department'] ?? '', ENT_QUOTES, 'UTF-8');
            $firstname = htmlspecialchars($data['firstname'] ?? '', ENT_QUOTES, 'UTF-8');
            $lastname  = htmlspecialchars($data['lastname'] ?? '', ENT_QUOTES, 'UTF-8');
            $location  = htmlspecialchars($data['city'] ?? '', ENT_QUOTES, 'UTF-8');
            $position  = htmlspecialchars($data['company'] ?? '', ENT_QUOTES, 'UTF-8');
            $auth      = htmlspecialchars($data['auth'], ENT_QUOTES, 'UTF-8');

            $fullname = trim($lastname . ', ' . $firstname, ', ');
            $location_position = trim($location . ' / ' . $position, ' /');

            $ajax_id = "divContainerUserInfo_" . $count;
            $param = sprintf('username=%s', urlencode($username));
            $onclick = "ajaxGeneric('library/ajax/user_info.php','retBandwidthInfo','$ajax_id','$param')";
            $tooltip = array(
                'subject' => sprintf('%s%s<span class="badge bg-primary ms-1">%s</span>', $img, $badge, $username),
                'onclick' => $onclick,
                'ajax_id' => $ajax_id,
                'actions' => array(
                    array('href' => sprintf('mng-edit.php?username=%s', urlencode($username)), 'label' => t('Tooltip','UserEdit')),
                    array('href' => sprintf('acct-username.php?username=%s', urlencode($username)), 'label' => t('all','Accounting'))
                )
            );
            $tooltip = get_tooltip_list_str($tooltip);

            // Generate Checkbox string
            $d = array( 'name' => 'username[]', 'value' => $username );
            $checkbox = get_checkbox_str($d);

            // Explicitly join Checkbox HTML with the Employee ID text
            $emp_id_column = $checkbox . ' ' . $emp_id;

            // Construct row with Employee ID as column 1
            $table_row = array(
                $emp_id_column,     // Checkbox + Employee ID Text
                $fullname,          // Full Name
                $location_position, // Location / Position
                $tooltip            // Username
            );

            if (!$hiddenPassword) {
                $table_row[] = ($type == 'USER') ? $auth : "(n/a)";
            }

            print_table_row($table_row);
            $count++;
        }

        $table_foot = array(
            'num_rows' => $numrows,
            'rows_per_page' => $per_page_numrows,
            'colspan' => $colspan,
            'multiple_pages' => $drawNumberLinks
        );
        $descriptor = array( 'form' => $form_descriptor, 'table_foot' => $table_foot );
        print_table_bottom($descriptor);

        $links = setupLinks_str($pageNum, $maxPage, $orderBy, $orderType);
        printLinks($links, $drawNumberLinks);

    } else {
        $failureMsg = "Nothing to display";
        include_once("include/management/actionMessages.php");
    }

    include('../common/includes/db_close.php');
    include('include/config/logging.php');

    print_footer_and_html_epilogue();
?>