<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2013 LMS Developers
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
 * ConfigHelper
 *
 */
class ConfigHelper
{
    public static function setFilter($divisionid = null, $userid = null)
    {
        LMSConfig::getConfig(array(
            'force' => true,
            'force_ui_only' => true,
            'user_id' => $userid,
            'division_id' => $divisionid,
        ));
    }

    /**
     * Returns config cariable value
     *
     * @param string $name Config variable name in section.variable format
     * @param string $default Default value
     * @return string
     */
    public static function getConfig($name, $default = null, $allow_empty_value = false)
    {
        $components = explode('.', $name, 2);
        if (count($components) != 2) {
            return $default;
        }
        $section_name = $components[0];
        $variable_name = $components[1];

        if (empty($variable_name)) {
            return $default;
        }

        if (!LMSConfig::getConfig()->hasSection($section_name)) {
            return $default;
        }

        if (!LMSConfig::getConfig()->getSection($section_name)->hasVariable($variable_name)) {
            return $default;
        }

        $value = LMSConfig::getConfig()->getSection($section_name)->getVariable($variable_name)->getValue();

        return $value == '' && !$allow_empty_value ? $default : $value;
    }

    /**
     * Checks if config variable exists
     *
     * @param string $name Config variable name in section.variable format
     * @return boolean
     */
    public static function checkConfig($name, $default = false)
    {
        [$section_name, $variable_name] = explode('.', $name, 2);

        if (empty($variable_name)) {
            return $default;
        }

        if ($section_name === 'privileges') {
            $value = self::getConfig($name);
            return $value;
        }

        if (!LMSConfig::getConfig()->hasSection($section_name)) {
            return $default;
        }

        if (!LMSConfig::getConfig()->getSection($section_name)->hasVariable($variable_name)) {
            return $default;
        }

        return self::checkValue(LMSConfig::getConfig()->getSection($section_name)->getVariable($variable_name)->getValue());
    }

    /**
     * Determines if value equals true or false
     *
     * @param string $value Value to check
     * @param boolean $default Default flag
     * @return boolean
     */
    public static function checkValue($value, $default = false)
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === '' || is_null($value)) {
            return $default;
        }

        if (preg_match('/^(1|y|on|yes|true|tak|t|enabled)$/i', $value)) {
            return true;
        }

        if (preg_match('/^(0|n|no|off|false|nie|disabled)$/i', $value)) {
            return false;
        }

        Utils::triggerError('Incorrect option value: ' . $value, E_USER_NOTICE, 15);
    }

    public static function variableExists($name)
    {
        [$section_name, $variable_name] = explode('.', $name, 2);

        if (empty($variable_name)) {
            return false;
        }

        if (!LMSConfig::getConfig()->hasSection($section_name)) {
            return false;
        }

        if (!LMSConfig::getConfig()->getSection($section_name)->hasVariable($variable_name)) {
            return false;
        }

        return true;
    }

    public static function getSubSections($section_name)
    {
        return LMSConfig::getConfig()->getSubSections($section_name);
    }

    public static function parseSubSection($sub_section_name)
    {
        if (preg_match('/[a-z0-9_-]+-(?<type>[[:alnum:]]+):(?<name>[a-z0-9_-]+)$/', $sub_section_name, $m)) {
            return array_filter(
                $m,
                function ($key) {
                    return preg_match('/^(type|name)$/', $key);
                },
                ARRAY_FILTER_USE_KEY
            );
        } else {
            return null;
        }
    }

    /**
     * Determines if user has got access privilege
     *
     * @param string $privilege privilege to check
     * @param boolean $checkIfSuperUser check if full access privilege should be taken into account
     * @return boolean
    */
    public static function checkPrivilege($privilege, $checkIfSuperUser = true)
    {
        if ($checkIfSuperUser && self::checkConfig('privileges.superuser')) {
            return !preg_match('/^hide_/', $privilege);
        }
        return self::checkConfig("privileges.$privilege");
    }

    public static function checkPrivileges()
    {
        $args = func_get_args();

        if (empty($args)) {
            return false;
        }

        if (is_bool($args[count($args) - 1])) {
            $checkIfSuperUser = $args[count($args) - 1];
        } else {
            $checkIfSuperUser = true;
        }

        if (!empty($args)) {
            foreach ($args as $arg) {
                if ($checkIfSuperUser && self::checkConfig('privileges.superuser')) {
                    return !preg_match('/^hide_/', $arg);
                }
                if (self::checkConfig('privileges.' . $arg)) {
                    return true;
                }
            }
        }

        return false;
    }
}
