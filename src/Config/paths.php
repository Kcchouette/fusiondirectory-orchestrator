<?php

declare(strict_types=1);

define('PHP_DIR', '/usr/share/php');
define('PEAR_DIR', '/usr/share/php');
define('PHP_MAILER', '/usr/share/php/libphp-phpmailer/');
define('FD_INTEGRATOR_LIB', '/usr/share/php/FusionDirectory');
define('CONFIG_DIR', '/etc/fusiondirectory-orchestrator/');
define('CONFIG_FILE', 'orchestrator.conf');
define('FD_ORCHESTRATOR_VERSION', '1.2');
define('PHP_MIN_VERSION', '8.1.0');
define('PHP_ERROR_FATAL', 'FALSE');

define('DEBUG_TRACE', 1);
define('DEBUG_LDAP', 2);
define('DEBUG_DB', 4);
define('DEBUG_SHELL', 8);
define('DEBUG_POST', 16);
define('DEBUG_SESSION', 32);
define('DEBUG_CONFIG', 64);
define('DEBUG_ACL', 128);
define('DEBUG_SI', 256);
define('DEBUG_MAIL', 512);

define('LDAP_READ', 1);
define('LDAP_ADD', 2);
define('LDAP_MOD', 3);
define('LDAP_DEL', 4);
define('LDAP_SEARCH', 5);
define('LDAP_AUTH', 6);
