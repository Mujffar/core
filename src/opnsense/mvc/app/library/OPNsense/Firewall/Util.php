<?php

/*
 * Copyright (C) 2017 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Firewall;

use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;

/**
 * Class Util, common static firewall support functions
 * @package OPNsense\Firewall
 */
class Util
{
    /**
     * @var null|Alias reference to alias object
     */
    private static $aliasObject = null;

    /**
     * @var null|array cached alias descriptions
     */
    private static $aliasDescriptions = [];

    /**
     * @var array cached getservbyname results
     */
    private static $servbynames = [];

    /**
     * is provided address an ip address.
     * @param string $network address
     * @return boolean
     */
    public static function isIpAddress($address)
    {
        return !empty(filter_var($address, FILTER_VALIDATE_IP));
    }

    /**
     * is provided network valid
     * @param string $network network
     * @return boolean
     */
    public static function isSubnet($network)
    {
        $tmp = explode('/', $network);
        if (count($tmp) == 2) {
            if (self::isIpAddress($tmp[0]) && ctype_digit($tmp[1]) && abs($tmp[1]) == $tmp[1]) {
                if (strpos($tmp[0], ':') !== false && $tmp[1] <= 128) {
                    // subnet v6
                    return true;
                } elseif ($tmp[1] <= 32) {
                    // subnet v4
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * is provided network a valid wildcard (https://en.wikipedia.org/wiki/Wildcard_mask)
     * @param string $network network
     * @return boolean
     */
    public static function isWildcard($network)
    {
        $tmp = explode('/', $network);
        if (count($tmp) == 2) {
            if (self::isIpAddress($tmp[0]) && self::isIpAddress($tmp[1])) {
                if (strpos($tmp[0], ':') !== false && strpos($tmp[1], ':') !== false) {
                    return true;
                } elseif (strpos($tmp[0], ':') === false && strpos($tmp[1], ':') === false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * use provided alias object instead of creating one. When modifying multiple aliases referencing each other
     * we need to use the same object for validations.
     * @param Alias $alias object to link
     */
    public static function attachAliasObject($alias)
    {
        self::$aliasObject = $alias;
        if ($alias != null) {
            $alias->flushCache();
        }
    }

    /**
     * check if name exists in alias config section
     * @param string $name name
     * @param boolean $valid check if the alias can safely be used
     * @return boolean
     * @throws \OPNsense\Base\ModelException
     */
    public static function isAlias($name, $valid = false)
    {
        if (self::$aliasObject == null) {
            // Cache the alias object to avoid object creation overhead.
            self::$aliasObject = new Alias();
            self::$aliasObject->flushCache();
        }
        if (!empty($name)) {
            $alias = self::$aliasObject->getByName($name);
            if ($alias != null) {
                if ($valid) {
                    // check validity for port type aliases
                    if (preg_match("/port/i", (string)$alias->type) && empty((string)$alias->content)) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * return alias descriptions
     * @param string $name name
     * @return string
     */
    public static function aliasDescription($name)
    {
        if (empty(self::$aliasDescriptions)) {
            // read all aliases at once, and cache descriptions.
            foreach ((new Alias())->aliasIterator() as $alias) {
                if (empty(self::$aliasDescriptions[$alias['name']])) {
                    if (!empty($alias['description'])) {
                        self::$aliasDescriptions[$alias['name']] = '<strong>' . $alias['description'] . '</strong><br/>';
                    } else {
                        self::$aliasDescriptions[$alias['name']] = "";
                    }

                    if (!empty($alias['content'])) {
                        $tmp = array_slice($alias['content'], 0, 10);
                        asort($tmp, SORT_NATURAL | SORT_FLAG_CASE);
                        if (count($alias['content']) > 10) {
                            $tmp[] = '[...]';
                        }
                        self::$aliasDescriptions[$alias['name']] .= implode("<br/>", $tmp);
                    }
                }
            }
        }
        if (!empty(self::$aliasDescriptions[$name])) {
            return self::$aliasDescriptions[$name];
        } else {
            return null;
        }
    }

    /**
     * Fetch port alias contents, other alias types are handled using tables so there usually no need
     * to know the contents within any of the scripts.
     * @param string $name name
     * @param array $aliases aliases already parsed (prevent deadlock)
     * @return array containing all ports or addresses
     * @throws \OPNsense\Base\ModelException when unable to create alias model
     */
    public static function getPortAlias($name, $aliases = array())
    {
        if (self::$aliasObject == null) {
            // Cache the alias object to avoid object creation overhead.
            self::$aliasObject = new Alias();
        }
        $result = array();
        foreach (self::$aliasObject->aliasIterator() as $node) {
            if (!empty($name) && (string)$node['name'] == $name && $node['type'] == 'port') {
                foreach ($node['content'] as $address) {
                    if (Util::isAlias($address)) {
                        if (!in_array($address, $aliases)) {
                            foreach (Util::getPortAlias($address, $aliases) as $port) {
                                if (!in_array($port, $result)) {
                                    $result[] = $port;
                                }
                            }
                        }
                    } elseif (!in_array($address, $result)) {
                        $result[] = $address;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * cached version of getservbyname()
     * @param string $service service name
     * @param string $protocol protocol name
     * @return boolean
     */
    private static function getservbyname($service, $protocol)
    {
        if (!isset(self::$servbynames[$protocol])) {
            self::$servbynames[$protocol] = [];
        }
        if (!isset(self::$servbynames[$protocol][$service])) {
            self::$servbynames[$protocol][$service] = getservbyname($service, $protocol);
        }
        return self::$servbynames[$protocol][$service];
    }

    /**
     * check if name exists in alias config section
     * @param string $number port number or range
     * @param boolean $allow_range ranges allowed
     * @return boolean
     */
    public static function isPort($number, $allow_range = true)
    {
        $tmp = explode(':', $number);
        foreach ($tmp as $port) {
            if (
                (filter_var($port, FILTER_VALIDATE_INT, array(
                  "options" => array("min_range" => 1, "max_range" => 65535))) === false || !ctype_digit($port)) &&
                !self::getservbyname($port, "tcp") && !self::getservbyname($port, "udp")
            ) {
                return false;
            }
        }
        if (($allow_range && count($tmp) <= 2) || count($tmp) == 1) {
            return true;
        }
        return false;
    }

    /**
     * Check if provided string is a valid domain name
     * @param string $domain
     * @return false|int
     */
    public static function isDomain($domain)
    {
        $pattern = '/^(?:(?:[a-z\pL0-9]|[a-z\pL0-9][a-z\pL0-9\-]*[a-z\pL0-9])\.)*(?:[a-z\pL0-9]|[a-z\pL0-9][a-z\pL0-9\-]*[a-z\pL0-9])$/iu';
        $parts = explode(".", $domain);
        if (ctype_digit($parts[0]) && ctype_digit($parts[count($parts) - 1])) {
            // according to rfc1123 2.1
            //   a valid host name can never have the dotted-decimal form #.#.#.#, since at least the highest-level
            //   component label will be alphabetic.
            return false;
        } elseif (preg_match($pattern, $domain)) {
            return true;
        }
        return false;
    }

    /**
     * calculate rule hash value
     * @param array rule
     * @return string
     */
    public static function calcRuleHash($rule)
    {
        // remove irrelevant fields
        foreach (array('updated', 'created', 'descr') as $key) {
            unset($rule[$key]);
        }
        ksort($rule);
        foreach ($rule as &$value) {
            if (is_array($value)) {
                ksort($value);
            }
        }
        return md5(json_encode($rule));
    }
}
