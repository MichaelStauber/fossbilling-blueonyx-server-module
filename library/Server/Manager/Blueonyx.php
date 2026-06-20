<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
 * Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
 * All Rights Reserved.
 *
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in
 *    the documentation and/or other materials provided with the
 *    distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its
 *    contributors may be used to endorse or promote products derived
 *    from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * You acknowledge that this software is not designed or intended for
 * use in the design, construction, operation or maintenance of any
 * nuclear facility.
 *
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0
 *
 * BlueOnyx APIv2 Server Manager.
 */
class Server_Manager_Blueonyx extends Server_Manager
{
    private const LOCAL_PORT = 9092;
    private const REMOTE_PORT = 81;
    private const MYSQL_RETRIES = 3;
    private const API_TIMEOUT = 300;

    private ?string $token = null;
    private ?int $tokenExpires = null;
    private ?string $sessionId = null;
    private int $lastTrigger = 0;

    public static function getForm(): array
    {
        return [
            'label' => 'BlueOnyx APIv2',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => 'Administrator username',
                            'placeholder' => 'admin',
                            'required' => true,
                        ],
                        [
                            'name' => 'password',
                            'type' => 'password',
                            'label' => 'Administrator password',
                            'placeholder' => 'Required for local AUTH',
                            'required' => false,
                        ],
                        [
                            'name' => 'accesshash',
                            'type' => 'password',
                            'label' => 'Client-Secret',
                            'placeholder' => 'Required for remote LOGIN',
                            'required' => false,
                        ],
                    ],
                ],
                'config' => [
                    'fields' => [
                        [
                            'name' => 'api_mode',
                            'type' => 'text',
                            'label' => 'API mode',
                            'placeholder' => 'local or remote',
                            'required' => true,
                        ],
                        [
                            'name' => 'tls_verify',
                            'type' => 'checkbox',
                            'label' => 'Verify TLS certificate',
                            'default' => true,
                        ],
                        [
                            'name' => 'default_ip',
                            'type' => 'text',
                            'label' => 'Default provisioning IP',
                            'placeholder' => 'Optional; falls back to the server IP',
                            'required' => false,
                        ],
                        [
                            'name' => 'debug',
                            'type' => 'checkbox',
                            'label' => 'Sanitized debug logging',
                            'default' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function init(): void
    {
        $mode = $this->getApiMode();
        if (!in_array($mode, ['local', 'remote'], true)) {
            throw new Server_Exception('BlueOnyx API mode must be "local" or "remote".');
        }

        if (empty($this->_config['username'])) {
            throw new Server_Exception('BlueOnyx administrator username is required.');
        }

        if ($mode === 'local' && empty($this->_config['password'])) {
            throw new Server_Exception('BlueOnyx local API mode requires an administrator password.');
        }

        if ($mode === 'remote') {
            if (empty($this->_config['host'])) {
                throw new Server_Exception('BlueOnyx remote API mode requires a hostname.');
            }
            if (empty($this->_config['accesshash'])) {
                throw new Server_Exception('BlueOnyx remote API mode requires a Client-Secret.');
            }
        }
    }

    public function __destruct()
    {
        if ($this->token === null && $this->sessionId === null) {
            return;
        }

        try {
            $this->sendAuthenticated(['cmd' => 'ENDKEY'], false);
        } catch (\Throwable) {
            // Session teardown is best effort and must not hide the real result.
        }
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        $host = trim((string) ($this->_config['host'] ?: $this->_config['ip']));
        if ($host === '') {
            throw new Server_Exception('BlueOnyx hostname is not configured.');
        }

        return sprintf('https://%s:%d/', $host, self::REMOTE_PORT);
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->getLoginUrl($account);
    }

    public function getLetsEncryptStatus(string $fqdn): array
    {
        $fqdn = $this->normalizeFqdn($fqdn);
        $oid = $this->findOneOid('Vsite', ['fqdn' => $fqdn], true);
        $vsite = $this->getObject($oid);
        $ssl = $this->getObject($oid, 'SSL');

        return [
            'fqdn' => $fqdn,
            'enabled' => \FOSSBilling\Tools::normalizeBoolean($ssl['enabled'] ?? false, false),
            'uses_letsencrypt' => \FOSSBilling\Tools::normalizeBoolean($ssl['uses_letsencrypt'] ?? false, false),
            'expires' => (string) ($ssl['expires'] ?? ''),
            'issuer' => (string) ($ssl['orgName'] ?? ''),
            'email' => (string) ($ssl['email'] ?? ''),
            'autoRenew' => \FOSSBilling\Tools::normalizeBoolean($ssl['autoRenew'] ?? false, false),
            'autoRenewDays' => is_numeric($ssl['autoRenewDays'] ?? null) ? (int) $ssl['autoRenewDays'] : null,
            'wantedAliases' => (string) ($ssl['LEwantedAliases'] ?? ''),
            'webAliases' => (string) ($vsite['webAliases'] ?? ''),
        ];
    }

    public function resolveHostingFqdn(string $domain): string
    {
        [, , $fqdn] = $this->resolveProvisioningDomain($domain);

        return $fqdn;
    }

    public function requestLetsEncrypt(string $fqdn, string $email, ?string $wantedAliases = null, int $autoRenewDays = 60, bool $autoRenew = true): array
    {
        $fqdn = $this->normalizeFqdn($fqdn);
        $oid = $this->findOneOid('Vsite', ['fqdn' => $fqdn], true);
        $vsite = $this->getObject($oid);

        $email = strtolower(trim($email));
        if ($email === '') {
            throw new Server_Exception('BlueOnyx Let\'s Encrypt email address is required.');
        }

        $aliases = trim((string) ($wantedAliases ?? ''));
        if ($aliases === '') {
            $aliases = trim((string) ($vsite['webAliases'] ?? ''));
        }
        if ($aliases === '' && isset($vsite['domain']) && is_string($vsite['domain']) && $vsite['domain'] !== '') {
            $aliases = $this->arrayScalar([(string) $vsite['domain']]);
        }

        $days = max(30, min(85, $autoRenewDays));

        $this->setObject($oid, 'SSL', [
            'LEemail' => $email,
            'autoRenew' => $autoRenew ? '1' : '0',
            'autoRenewDays' => (string) $days,
            'LEwantedAliases' => $aliases,
            'uses_letsencrypt' => '1',
            'performLEinstall' => (string) time(),
            'enabled' => '1',
            'LEclientRet' => '',
        ]);

        $ssl = $this->getObject($oid, 'SSL');
        $clientRet = $this->parseLetsEncryptClientResult($ssl['LEclientRet'] ?? '');
        if ($clientRet !== null && ($clientRet['Status'] ?? null) === '1') {
            throw new Server_Exception($this->formatLetsEncryptFailure($clientRet));
        }

        return $this->getLetsEncryptStatus($fqdn) + ['queued' => true, 'autoRenewDays' => $days];
    }

    public function getPort(): int
    {
        $default = $this->getApiMode() === 'local' ? self::LOCAL_PORT : self::REMOTE_PORT;

        return FOSSBilling\Tools::normalizePort($this->_config['port'] ?? null, $default);
    }

    public function testConnection(): bool
    {
        $response = $this->sendAuthenticated(['cmd' => 'WHOAMI']);
        $oid = $response['data']['oid'] ?? $response['data']['OID'] ?? null;

        if (!is_numeric($oid) || (int) $oid < 0) {
            throw new Server_Exception('BlueOnyx WHOAMI did not return a valid administrator OID.');
        }

        return true;
    }

    public function createAccount(Server_Account $account): bool
    {
        [$hostname, $domain, $fqdn, $isApexDomain] = $this->resolveProvisioningDomain($account->getDomain());
        if ($this->findOneOid('Vsite', ['fqdn' => $fqdn], false) !== null) {
            throw new Server_Exception(sprintf('A BlueOnyx Vsite already exists for %s.', $fqdn));
        }

        $username = trim((string) $account->getUsername());
        $password = (string) $account->getPassword();
        if ($username === '' || $password === '') {
            throw new Server_Exception('BlueOnyx provisioning requires an account username and password.');
        }

        $ip = $this->provisioningIp($account);
        $package = $account->getPackage();
        $settings = $this->packageSettings($package);
        $createdVsiteOid = null;

        try {
            $createdVsiteOid = $this->createObject('Vsite', [
                'hostname' => $hostname,
                'domain' => $domain,
                'fqdn' => $fqdn,
                'ipaddr' => $ip,
                'ipaddrIPv6' => '',
                'createdUser' => (string) $this->_config['username'],
                'webAliases' => '',
                'webAliasRedirects' => $settings['web_alias_redirects'],
                'emailDisabled' => $settings['email_disabled'],
                'mailAliases' => '',
                'mailCatchAll' => '',
                'volume' => '/home',
                'maxusers' => $settings['maxusers'],
                'dns_auto' => $settings['dns_auto'],
                'prefix' => '',
                'userPrefixEnabled' => '0',
                'userPrefixField' => '',
                'site_preview' => '0',
            ]);

            $vsite = $this->getObject($createdVsiteOid);
            $group = trim((string) ($vsite['name'] ?? ''));
            if ($group === '') {
                throw new Server_Exception('BlueOnyx did not assign a group name to the new Vsite.');
            }

            if ($isApexDomain) {
                $aliases = $this->aliasScalarList($domain);
                $this->setObject($createdVsiteOid, '', [
                    'webAliases' => $aliases['webAliases'],
                    'mailAliases' => $aliases['mailAliases'],
                ]);
            }
            $this->applyVsitePackage($createdVsiteOid, $settings, false);
            $this->createObject('User', [
                'volume' => '/home',
                'enabled' => '1',
                'emailDisabled' => '0',
                'description' => '',
                'fullName' => $this->clientFullName($account),
                'ftpDisabled' => '0',
                'localePreference' => 'browser',
                'stylePreference' => 'BlueOnyx',
                'site' => $group,
                'name' => $username,
                'sortName' => '',
                'password' => $password,
                'capLevels' => '&siteAdmin&',
            ]);
            $userOid = $this->findOneOid('User', ['name' => $username], true);

            $this->setObject($userOid, 'Disk', ['quota' => $settings['quota']]);
            $this->setObject($userOid, 'Shell', ['enabled' => $settings['shell']]);
            $this->setObject($userOid, 'Email', ['aliases' => $this->siteAdminEmailAliases($account)]);
            $this->setObject($createdVsiteOid, 'PHP', ['prefered_siteAdmin' => $username]);
            $this->applyEmailAutoconfigAliases($createdVsiteOid, $hostname, $domain, $settings['email_autoconfig']);
            $this->setObject($createdVsiteOid, 'PHPVsite', ['force_update' => $this->triggerValue()]);

            $verifiedVsite = $this->getObject($createdVsiteOid);
            $verifiedUser = $this->getObject($userOid);
            if (($verifiedVsite['fqdn'] ?? null) !== $fqdn || ($verifiedUser['name'] ?? null) !== $username) {
                throw new Server_Exception('BlueOnyx provisioning verification failed.');
            }
        } catch (\Throwable $e) {
            if ($createdVsiteOid !== null) {
                try {
                    $this->destroyObject($createdVsiteOid);
                } catch (\Throwable $rollbackError) {
                    throw new Server_Exception(sprintf(
                        'BlueOnyx provisioning failed: %s Rollback of Vsite OID %s also failed: %s',
                        $e->getMessage(),
                        $createdVsiteOid,
                        $rollbackError->getMessage()
                    ));
                }
            }

            if ($e instanceof Server_Exception) {
                throw $e;
            }

            throw new Server_Exception('BlueOnyx provisioning failed: ' . $e->getMessage());
        }

        return true;
    }

    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $fqdn = $this->provisioningFqdn($account);
        $oid = $this->findOneOid('Vsite', ['fqdn' => $fqdn], false);
        $copy = clone $account;

        if ($oid === null) {
            $copy->setSuspended(true);

            return $copy;
        }

        $vsite = $this->getObject($oid);
        $copy->setSuspended($this->booleanValue($vsite['suspend'] ?? '0') === '1');

        return $copy;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        $oid = $this->requiredVsiteOid($account);
        $this->setObject($oid, '', ['suspend' => '1']);

        return true;
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        $oid = $this->requiredVsiteOid($account);
        $this->setObject($oid, '', ['suspend' => '0']);

        return true;
    }

    public function cancelAccount(Server_Account $account): bool
    {
        $oid = $this->findOneOid('Vsite', ['fqdn' => $this->provisioningFqdn($account)], false);
        if ($oid === null) {
            return true;
        }

        $vsite = $this->getObject($oid);
        if (($vsite['fqdn'] ?? null) !== $this->provisioningFqdn($account)) {
            throw new Server_Exception('Refusing to delete a BlueOnyx Vsite whose FQDN does not match exactly.');
        }

        $group = trim((string) ($vsite['name'] ?? ''));
        if ($group === '') {
            throw new Server_Exception('BlueOnyx Vsite has no group name.');
        }

        foreach ($this->findOids('User', ['site' => $group]) as $userOid) {
            $this->destroyObject($userOid);
        }

        $this->destroyObject($oid);

        return true;
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        if ($newPassword === '') {
            throw new Server_Exception('The new BlueOnyx password must not be empty.');
        }

        $vsiteOid = $this->requiredVsiteOid($account);
        $admin = $this->findSiteAdmin($vsiteOid);
        $this->setObject($admin['oid'], '', ['password' => $newPassword]);

        return true;
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception('BlueOnyx version 1 does not support account username changes.');
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception('BlueOnyx version 1 does not support account domain changes.');
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception('BlueOnyx version 1 does not support account IP changes.');
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $vsiteOid = $this->requiredVsiteOid($account);
        $settings = $this->packageSettings($package);
        $admin = $this->findSiteAdmin($vsiteOid);

        // Deliberately no Vsite rollback or destruction in this method.
        $this->applyVsitePackage($vsiteOid, $settings, true);
        $this->setObject($admin['oid'], 'Disk', ['quota' => $settings['quota']]);
        $this->setObject($admin['oid'], 'Shell', ['enabled' => $settings['shell']]);
        $this->setObject($admin['oid'], 'Email', ['aliases' => $this->siteAdminEmailAliases($account)]);
        $this->setObject($vsiteOid, 'PHP', ['prefered_siteAdmin' => $admin['name']]);
        [$hostname, $domain] = $this->resolveProvisioningDomain($account->getDomain());
        $this->applyEmailAutoconfigAliases($vsiteOid, $hostname, $domain, $settings['email_autoconfig']);
        $this->setObject($vsiteOid, 'PHPVsite', ['force_update' => $this->triggerValue()]);

        return true;
    }

    private function applyVsitePackage(string $oid, array $settings, bool $existing): void
    {
        $this->setObject($oid, '', [
            'maxusers' => $settings['maxusers'],
            'dns_auto' => $settings['dns_auto'],
            'emailDisabled' => $settings['email_disabled'],
            'webAliasRedirects' => $settings['web_alias_redirects'],
        ]);
        $this->setObject($oid, 'Disk', ['quota' => $settings['quota']]);
        $this->validatePhpVersion($settings['php_version']);
        $this->setObject($oid, 'PHP', [
            'enabled' => '1',
            'fpm_enabled' => $settings['php_handler'] === 'FPM' ? '1' : '0',
            'suPHP_enabled' => $settings['php_handler'] === 'suPHP' ? '1' : '0',
            'mod_ruid_enabled' => '0',
            'version' => $settings['php_version'],
        ]);
        $this->setObject($oid, 'CGI', ['enabled' => $settings['cgi']]);
        $this->setObject($oid, 'SSI', ['enabled' => $settings['ssi']]);
        $this->setObject($oid, 'Shell', ['enabled' => $settings['shell']]);
        $this->setObject($oid, 'FTPNONADMIN', ['enabled' => $settings['ftp_nonadmin']]);
        $this->setObject($oid, 'subdomains', [
            'enabled' => $settings['subdomains_enabled'],
            'vsite_enabled' => $settings['subdomains_enabled'],
            'max_subdomains' => $settings['max_subdomains'],
        ]);
        $this->applyMysqlPackage($oid, $settings['max_databases'], $existing);
    }

    private function applyMysqlPackage(string $oid, int $maxDatabases, bool $existing): void
    {
        $current = $existing ? $this->getObject($oid, 'MYSQL_Vsite') : [];
        $enabled = $this->booleanValue($current['enabled'] ?? '0') === '1';

        if ($maxDatabases < 1) {
            if ($enabled) {
                $this->setObject($oid, 'MYSQL_Vsite', [
                    'enabled' => '0',
                    'destroy' => $this->triggerValue(),
                ]);
            }

            return;
        }

        if ($enabled) {
            $this->setObject($oid, 'MYSQL_Vsite', ['maxDBs' => (string) $maxDatabases]);

            return;
        }

        $mysqlOid = $this->findOneOid('MySQL', [], true);
        $mysql = $this->getObject($mysqlOid);
        $host = (string) ($mysql['sql_host'] ?? '127.0.0.1');
        $port = (string) ($mysql['sql_port'] ?? '3306');

        for ($attempt = 1; $attempt <= self::MYSQL_RETRIES; ++$attempt) {
            $username = 'vsite_' . $this->randomAlphaNumeric(7);
            try {
                $this->setObject($oid, 'MYSQL_Vsite', [
                    'enabled' => '1',
                    'username' => $username,
                    'pass' => $this->randomAlphaNumeric(8),
                    'DB' => $username . '_db',
                    'host' => $host,
                    'port' => $port,
                    'maxDBs' => (string) $maxDatabases,
                    'hidden' => $this->triggerValue(),
                    'create' => $this->triggerValue(),
                ]);

                $stored = $this->getObject($oid, 'MYSQL_Vsite');
                if ($this->booleanValue($stored['enabled'] ?? '0') !== '1'
                    || (int) ($stored['maxDBs'] ?? 0) !== $maxDatabases
                ) {
                    throw new Server_Exception('BlueOnyx did not retain the requested MariaDB state.');
                }

                return;
            } catch (Server_Exception $e) {
                if (!$this->isMysqlCollisionError($e->getMessage()) || $attempt === self::MYSQL_RETRIES) {
                    throw new Server_Exception(sprintf(
                        'BlueOnyx MariaDB initialization failed after %d attempts: %s',
                        $attempt,
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    private function packageSettings(Server_Package $package): array
    {
        $quota = $this->positiveInteger($package->getQuota(), 200);
        $maxUsers = $this->positiveInteger(
            $package->getCustomValue('maxusers') ?: $package->getMaxPop(),
            1
        );
        $maxDatabases = $this->nonNegativeInteger($package->getMaxSql(), 0);
        if (!$this->packageBoolean($package, 'mysql_enabled', $maxDatabases > 0)) {
            $maxDatabases = 0;
        }

        $maxSubdomains = $this->nonNegativeInteger($package->getMaxSubdomains(), 0);
        $shell = $this->nonNegativeInteger($package->getCustomValue('shell'), 0);
        if ($shell > 3) {
            throw new Server_Exception('BlueOnyx shell level must be between 0 and 3.');
        }

        $handler = trim((string) ($package->getCustomValue('php_handler') ?: 'FPM'));
        if (!in_array($handler, ['FPM', 'suPHP'], true)) {
            throw new Server_Exception('BlueOnyx php_handler must be FPM or suPHP.');
        }

        $phpVersion = strtoupper(trim((string) ($package->getCustomValue('php_version') ?: 'PHPOS')));
        if (!preg_match('/^PHP(?:OS|[0-9]{2})$/', $phpVersion)) {
            throw new Server_Exception('BlueOnyx php_version must be PHPOS or a PHP namespace such as PHP85.');
        }

        return [
            'quota' => (string) $quota,
            'maxusers' => (string) $maxUsers,
            'max_databases' => $maxDatabases,
            'php_handler' => $handler,
            'php_version' => $phpVersion,
            'cgi' => $this->booleanValue($package->getCustomValue('cgi') ?? '0'),
            'ssi' => $this->booleanValue($package->getCustomValue('ssi') ?? '0'),
            'shell' => (string) $shell,
            'ftp_nonadmin' => $this->booleanValue($package->getCustomValue('ftp_nonadmin') ?? '0'),
            'email_disabled' => $this->booleanValue($package->getCustomValue('email_disabled') ?? '0'),
            'web_alias_redirects' => $this->booleanValue($package->getCustomValue('web_alias_redirects') ?? '1'),
            'dns_auto' => $this->booleanValue($package->getCustomValue('dns_auto') ?? '0'),
            'email_autoconfig' => $this->packageBoolean($package, 'email_autoconfig', false) ? '1' : '0',
            'subdomains_enabled' => $this->packageBoolean(
                $package,
                'subdomains_enabled',
                $maxSubdomains > 0
            ) ? '1' : '0',
            'max_subdomains' => (string) $maxSubdomains,
        ];
    }

    private function validatePhpVersion(string $namespace): void
    {
        if ($namespace === 'PHPOS') {
            return;
        }

        $phpOid = $this->findOneOid('PHP', [], true);
        $state = $this->getObject($phpOid, $namespace);
        if ($this->booleanValue($state['present'] ?? '0') !== '1'
            || $this->booleanValue($state['enabled'] ?? '0') !== '1'
        ) {
            throw new Server_Exception(sprintf('BlueOnyx PHP namespace %s is not installed and enabled.', $namespace));
        }
    }

    private function findSiteAdmin(string $vsiteOid): array
    {
        $vsite = $this->getObject($vsiteOid);
        $group = trim((string) ($vsite['name'] ?? ''));
        if ($group === '') {
            throw new Server_Exception('BlueOnyx Vsite has no group name.');
        }

        $admins = [];
        foreach ($this->findOids('User', ['site' => $group]) as $userOid) {
            $user = $this->getObject($userOid);
            if (str_contains((string) ($user['capLevels'] ?? ''), 'siteAdmin')) {
                $admins[] = [
                    'oid' => $userOid,
                    'name' => (string) ($user['name'] ?? ''),
                ];
            }
        }

        if ($admins === []) {
            throw new Server_Exception('No BlueOnyx site administrator was found for the Vsite.');
        }
        if (count($admins) === 1) {
            return $admins[0];
        }

        $php = $this->getObject($vsiteOid, 'PHP');
        $preferred = (string) ($php['prefered_siteAdmin'] ?? '');
        foreach ($admins as $admin) {
            if ($admin['name'] === $preferred) {
                return $admin;
            }
        }

        throw new Server_Exception('BlueOnyx has multiple site administrators but no matching preferred site administrator.');
    }

    private function requiredVsiteOid(Server_Account $account): string
    {
        return $this->findOneOid('Vsite', ['fqdn' => $this->provisioningFqdn($account)], true);
    }

    private function createObject(string $class, array $data): string
    {
        if ($this->getApiMode() === 'local') {
            $payload = ['cmd' => 'CREATE', 'class' => $class, 'data' => $data];
        } else {
            $payload = ['cmd' => sprintf('CREATE %s %s', $class, $this->commandAssignments($data))];
        }

        $response = $this->sendAuthenticated($payload);
        $oids = $this->oidList($response);
        if ($oids === []) {
            throw new Server_Exception(sprintf('BlueOnyx CREATE %s returned no OID.', $class));
        }

        return $oids[0];
    }

    private function destroyObject(string $oid): void
    {
        $payload = $this->getApiMode() === 'local'
            ? ['cmd' => 'DESTROY', 'oid' => $oid]
            : ['cmd' => 'DESTROY ' . $oid];
        $this->sendAuthenticated($payload);
    }

    private function getObject(string $oid, string $namespace = ''): array
    {
        if ($this->getApiMode() === 'local') {
            $payload = ['cmd' => 'GET', 'oid' => $oid];
            if ($namespace !== '') {
                $payload['namespace'] = $namespace;
            }
        } else {
            $payload = ['cmd' => 'GET ' . $oid . ($namespace === '' ? '' : ' . ' . $namespace)];
        }

        $response = $this->sendAuthenticated($payload);
        $data = $response['data']['DATA'] ?? $response['data'] ?? [];
        if (!is_array($data)) {
            throw new Server_Exception(sprintf('BlueOnyx GET %s returned invalid data.', $oid));
        }

        unset($data['errors']);

        return $data;
    }

    private function setObject(string $oid, string $namespace, array $data): void
    {
        $command = 'SET ' . $oid;
        if ($namespace !== '') {
            $command .= ' . ' . $namespace;
        }
        $command .= ' ' . $this->commandAssignments($data);
        $this->sendAuthenticated(['cmd' => trim($command)]);
    }

    private function findOids(string $class, array $args): array
    {
        if ($this->getApiMode() === 'local') {
            $payload = ['cmd' => 'FIND', 'class' => $class];
            if ($args !== []) {
                $payload['args'] = $args;
            }
        } else {
            $payload = ['cmd' => trim('FIND ' . $class . ' ' . $this->commandAssignments($args))];
        }

        return $this->oidList($this->sendAuthenticated($payload));
    }

    private function findOneOid(string $class, array $args, bool $required): ?string
    {
        $oids = $this->findOids($class, $args);
        if ($oids === []) {
            if ($required) {
                throw new Server_Exception(sprintf('BlueOnyx %s object was not found.', $class));
            }

            return null;
        }
        if (count($oids) > 1 && $args !== []) {
            throw new Server_Exception(sprintf('BlueOnyx lookup for %s was not unique.', $class));
        }

        return $oids[0];
    }

    private function sendAuthenticated(array $payload, bool $retryToken = true): array
    {
        $this->authenticate();
        if ($this->getApiMode() === 'local') {
            $payload['user'] = (string) $this->_config['username'];
            $payload['sessionid'] = $this->sessionId;
        } else {
            $payload['token'] = $this->token;
        }

        try {
            return $this->request($payload);
        } catch (Server_Exception $e) {
            if ($this->getApiMode() === 'remote' && $retryToken && $this->isTokenError($e->getMessage())) {
                $this->token = null;
                $this->tokenExpires = null;
                $this->authenticate();
                $payload['token'] = $this->token;

                return $this->request($payload);
            }

            throw $e;
        }
    }

    private function authenticate(): void
    {
        if ($this->getApiMode() === 'local') {
            if ($this->sessionId !== null) {
                return;
            }
            $response = $this->request([
                'cmd' => 'AUTH',
                'user' => (string) $this->_config['username'],
                'password' => (string) $this->_config['password'],
            ]);
            $session = $response['data']['sessionid'] ?? $response['data']['sessionId'] ?? null;
            if (!is_string($session) || $session === '') {
                throw new Server_Exception('BlueOnyx AUTH returned no session ID.');
            }
            $this->sessionId = $session;

            return;
        }

        if ($this->token !== null && ($this->tokenExpires === null || time() < $this->tokenExpires)) {
            return;
        }

        $payload = ['cmd' => 'LOGIN'];
        if (!empty($this->_config['username'])) {
            $payload['username'] = (string) $this->_config['username'];
        }
        if (!empty($this->_config['password'])) {
            $payload['password'] = (string) $this->_config['password'];
        }
        $response = $this->request($payload);
        $token = $response['data']['token'] ?? $response['data']['sessionId'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new Server_Exception('BlueOnyx LOGIN returned no token.');
        }
        $this->token = $token;
        $expires = $response['data']['expires'] ?? null;
        $parsed = is_string($expires) ? strtotime($expires) : false;
        $this->tokenExpires = $parsed === false ? null : $parsed;
    }

    private function request(array $payload): array
    {
        $command = (string) ($payload['cmd'] ?? 'UNKNOWN');
        $sensitiveValues = $this->sensitiveValues($payload);
        $client = $this->getHttpClient()->withOptions([
            'verify_peer' => $this->tlsVerify(),
            'verify_host' => $this->tlsVerify(),
            'timeout' => self::API_TIMEOUT,
        ]);
        $headers = ['Content-Type' => 'application/json'];
        if ($this->getApiMode() === 'remote') {
            $headers['X-Client-Secret'] = (string) $this->_config['accesshash'];
        }

        $this->debug('request', ['command' => $this->commandName($command), 'endpoint' => $this->endpoint()]);

        try {
            $response = $client->request('POST', $this->endpoint(), [
                'headers' => $headers,
                'json' => $payload,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new Server_Exception(sprintf(
                'BlueOnyx API transport failure during %s: %s',
                $this->commandName($command),
                $e->getMessage()
            ));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Server_Exception(sprintf(
                'BlueOnyx API returned invalid JSON during %s (HTTP %d).',
                $this->commandName($command),
                $statusCode
            ));
        }
        if (!is_array($decoded)) {
            throw new Server_Exception('BlueOnyx API returned an invalid response structure.');
        }

        $this->debug('response', [
            'command' => $this->commandName($command),
            'http_status' => $statusCode,
            'api_status' => $decoded['status'] ?? null,
        ]);

        if ($statusCode < 200 || $statusCode >= 300 || !$this->isSuccessfulResponse($decoded)) {
            throw new Server_Exception(sprintf(
                'BlueOnyx %s failed: %s',
                $this->commandName($command),
                $this->errorMessage($decoded, $statusCode, $sensitiveValues)
            ));
        }

        return $decoded;
    }

    private function isSuccessfulResponse(array $response): bool
    {
        if (isset($response['success']) && $response['success'] === false) {
            return false;
        }
        if (isset($response['error']) && $response['error'] !== '' && $response['error'] !== null) {
            return false;
        }
        $status = isset($response['status']) ? (int) $response['status'] : 0;

        return in_array($status, [200, 201], true);
    }

    private function errorMessage(array $response, int $httpStatus, array $sensitiveValues = []): string
    {
        $messages = [];
        foreach (($response['data']['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['message'])) {
                $messages[] = (string) $error['message'];
            } elseif (is_string($error)) {
                $messages[] = $error;
            }
        }
        foreach (['message', 'error'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $response[$key] !== '') {
                $messages[] = $response[$key];
            }
        }
        $messages = array_map([$this, 'redact'], $messages);
        foreach ($sensitiveValues as $secret) {
            $messages = array_map(
                static fn (string $message): string => str_replace($secret, '[REDACTED]', $message),
                $messages
            );
        }
        $messages = array_values(array_unique($messages));

        return $messages === []
            ? sprintf('unexpected response (HTTP %d, API status %s)', $httpStatus, (string) ($response['status'] ?? 'unknown'))
            : implode('; ', $messages);
    }

    private function endpoint(): string
    {
        $host = $this->getApiMode() === 'local'
            ? '127.0.0.1'
            : trim((string) $this->_config['host']);

        return sprintf('https://%s:%d/v2/cce', $host, $this->getPort());
    }

    private function getApiMode(): string
    {
        $configuredMode = strtolower(trim((string) $this->configValue('api_mode')));
        if ($configuredMode !== '') {
            return $configuredMode;
        }

        // FOSSBilling 0.8.2 only persists its built-in server configuration
        // fields. It currently drops manager-specific fields such as api_mode.
        // A configured Client-Secret therefore identifies remote API mode.
        return empty($this->_config['accesshash']) ? 'local' : 'remote';
    }

    private function configValue(string $name): mixed
    {
        if (is_array($this->_config['config'] ?? null) && array_key_exists($name, $this->_config['config'])) {
            return $this->_config['config'][$name];
        }

        return $this->_config[$name] ?? null;
    }

    private function tlsVerify(): bool
    {
        return FOSSBilling\Tools::normalizeBoolean($this->configValue('tls_verify') ?? true, true);
    }

    private function debug(string $event, array $context): void
    {
        if (!FOSSBilling\Tools::normalizeBoolean($this->configValue('debug') ?? false, false)) {
            return;
        }

        $this->getLog()->debug('BlueOnyx API ' . $event . ': ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }

    private function oidList(array $response): array
    {
        $oids = $response['data']['oidlist'] ?? $response['data']['oids'] ?? [];
        if (!is_array($oids)) {
            return [];
        }

        return array_values(array_map('strval', $oids));
    }

    private function commandAssignments(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                throw new Server_Exception('Invalid BlueOnyx CCE property name.');
            }
            $parts[] = sprintf('%s="%s"', $key, $this->escapeCce((string) $value));
        }

        return implode(' ', $parts);
    }

    private function escapeCce(string $value): string
    {
        return str_replace(
            ["\\", "\"", "\n", "\r", "\t", "\f", "\x08", "\x07"],
            ["\\\\", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b", "\\a"],
            $value
        );
    }

    private function splitFqdn(?string $value): array
    {
        [$hostname, $domain, $fqdn] = $this->resolveProvisioningDomain($value);

        return [$hostname, $domain, $fqdn];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: bool}
     */
    private function resolveProvisioningDomain(?string $value): array
    {
        $fqdn = $this->normalizeFqdn($value);
        $labels = explode('.', $fqdn);
        $registrableLabels = $this->registrableLabelCount($labels);

        if (count($labels) < $registrableLabels) {
            throw new Server_Exception('BlueOnyx requires a complete FQDN with hostname and domain.');
        }

        if (count($labels) === $registrableLabels) {
            return ['www', $fqdn, 'www.' . $fqdn, true];
        }

        $extraLabels = count($labels) - $registrableLabels;
        if ($extraLabels !== 1) {
            throw new Server_Exception('BlueOnyx only supports one hostname label in front of the domain.');
        }

        $hostname = $labels[0];
        $domain = implode('.', array_slice($labels, 1));

        return [$hostname, $domain, $fqdn, false];
    }

    /**
     * @param string[] $labels
     */
    private function registrableLabelCount(array $labels): int
    {
        if (count($labels) < 2) {
            throw new Server_Exception('BlueOnyx requires a complete FQDN with hostname and domain.');
        }

        $suffix = $labels[count($labels) - 2] . '.' . $labels[count($labels) - 1];
        if (in_array($suffix, $this->compoundPublicSuffixes(), true) && count($labels) >= 3) {
            return 3;
        }

        return 2;
    }

    /**
     * @return string[]
     */
    private function compoundPublicSuffixes(): array
    {
        return [
            'ac.uk',
            'co.uk',
            'gov.uk',
            'ltd.uk',
            'me.uk',
            'net.uk',
            'org.uk',
            'plc.uk',
            'sch.uk',
            'ac.jp',
            'ad.jp',
            'co.jp',
            'ed.jp',
            'go.jp',
            'gr.jp',
            'lg.jp',
            'ne.jp',
            'or.jp',
            'com.au',
            'net.au',
            'org.au',
            'edu.au',
            'gov.au',
            'asn.au',
            'id.au',
            'co.nz',
            'net.nz',
            'org.nz',
            'ac.nz',
            'govt.nz',
            'school.nz',
            'maori.nz',
            'co.in',
            'firm.in',
            'net.in',
            'org.in',
            'gen.in',
            'ind.in',
            'res.in',
            'com.br',
            'net.br',
            'org.br',
            'gov.br',
            'edu.br',
            'mil.br',
            'co.kr',
            'ne.kr',
            'or.kr',
            're.kr',
            'pe.kr',
            'go.kr',
            'mil.kr',
            'ac.kr',
        ];
    }

    private function aliasScalarList(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return [
                'webAliases' => '',
                'mailAliases' => '',
            ];
        }

        $webAliases = '&' . $domain . '&';
        $mailAliases = '&' . $domain . '&mail.' . $domain . '&';

        return [
            'webAliases' => $webAliases,
            'mailAliases' => $mailAliases,
        ];
    }

    /**
     * @return string[]
     */
    private function scalarAliasesToList(string $aliases): array
    {
        $aliases = trim($aliases);
        if ($aliases === '') {
            return [];
        }

        $aliases = trim($aliases, '&');
        if ($aliases === '') {
            return [];
        }

        $parts = array_map(static fn (string $value): string => strtolower(trim($value)), explode('&', $aliases));
        $parts = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));

        return array_values(array_unique($parts));
    }

    /**
     * @param string[] $aliases
     */
    private function listToScalarAliases(array $aliases): string
    {
        $aliases = array_values(array_unique(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $aliases
        )));
        $aliases = array_values(array_filter($aliases, static fn (string $value): bool => $value !== ''));

        if ($aliases === []) {
            return '';
        }

        return '&' . implode('&', $aliases) . '&';
    }

    private function applyEmailAutoconfigAliases(string $oid, string $hostname, string $domain, mixed $enabled): void
    {
        $domain = strtolower(trim($domain));
        $hostname = strtolower(trim($hostname));
        $enabled = $this->booleanValue($enabled) === '1';

        $current = $this->getObject($oid);
        $aliases = $this->scalarAliasesToList((string) ($current['webAliases'] ?? ''));
        $autoconfigAliases = [
            'autoconfig.' . $domain,
            'autodiscover.' . $domain,
        ];

        if ($enabled && $hostname === 'www') {
            $aliases = array_values(array_filter(
                $aliases,
                static fn (string $value): bool => $value !== $domain
            ));
            array_unshift($aliases, $domain);
        }

        foreach ($autoconfigAliases as $autoconfigAlias) {
            if ($enabled) {
                if (!in_array($autoconfigAlias, $aliases, true)) {
                    $aliases[] = $autoconfigAlias;
                }

                continue;
            }

            $aliases = array_values(array_filter(
                $aliases,
                static fn (string $value) => $value !== $autoconfigAlias
            ));
        }

        $this->setObject($oid, '', [
            'email_autoconfig' => $enabled ? '1' : '0',
            'webAliases' => $this->listToScalarAliases($aliases),
        ]);
    }

    private function siteAdminEmailAliases(Server_Account $account): string
    {
        $aliases = ['webmaster', 'info'];
        $firstName = $this->clientFirstName($account);
        if ($firstName !== '') {
            $aliases[] = $firstName;
        }

        return $this->arrayScalar($aliases);
    }

    private function provisioningFqdn(Server_Account $account): string
    {
        [, , $fqdn] = $this->resolveProvisioningDomain($account->getDomain());

        return $fqdn;
    }

    private function normalizeFqdn(?string $value): string
    {
        $fqdn = strtolower(rtrim(trim((string) $value), '.'));
        if ($fqdn === '' || strlen($fqdn) > 253
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $fqdn)
        ) {
            throw new Server_Exception('Invalid BlueOnyx Vsite FQDN.');
        }

        return $fqdn;
    }

    private function parseLetsEncryptClientResult(mixed $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            $raw = substr($raw, 1, -1);
        }

        $decoded = json_decode(stripcslashes($raw), true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function formatLetsEncryptFailure(array $clientRet): string
    {
        $error = trim((string) ($clientRet['Error'] ?? 'BlueOnyx Let\'s Encrypt request failed.'));
        $message = trim((string) ($clientRet['ErrMsg'] ?? ''));

        if ($message !== '' && is_file($message) && is_readable($message)) {
            $logContents = trim((string) file_get_contents($message));
            if ($logContents !== '') {
                $message = $logContents;
            }
        }

        if ($message !== '') {
            $error .= ': ' . $message;
        }

        return $error;
    }

    private function provisioningIp(Server_Account $account): string
    {
        $ip = trim((string) ($account->getIp() ?: $this->configValue('default_ip') ?: $this->_config['ip']));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new Server_Exception('BlueOnyx provisioning IP is missing or invalid.');
        }

        return $ip;
    }

    private function clientFullName(Server_Account $account): string
    {
        $client = $account->getClient();
        $name = trim((string) ($client?->getFullName() ?: $account->getUsername()));

        return $name === '' ? (string) $account->getUsername() : $name;
    }

    private function clientFirstName(Server_Account $account): string
    {
        $fullName = trim($this->clientFullName($account));
        if ($fullName === '') {
            return $this->sanitizeAliasToken((string) $account->getUsername());
        }

        $parts = preg_split('/\s+/', $fullName, 2);
        $firstName = $parts !== false ? (string) ($parts[0] ?? '') : $fullName;
        $firstName = $this->sanitizeAliasToken($firstName);

        if ($firstName === '') {
            return $this->sanitizeAliasToken((string) $account->getUsername());
        }

        return $firstName;
    }

    private function sanitizeAliasToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/i', '', $value) ?? '';

        return $value;
    }

    private function positiveInteger(?string $value, int $default): int
    {
        if ($value === null || $value === '' || strtolower($value) === 'unlimited') {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new Server_Exception('BlueOnyx package value must be a positive integer.');
        }

        return (int) $value;
    }

    private function nonNegativeInteger(?string $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (strtolower($value) === 'unlimited') {
            throw new Server_Exception('BlueOnyx version 1 does not support unlimited numeric package values.');
        }
        if (!ctype_digit($value)) {
            throw new Server_Exception('BlueOnyx package value must be a non-negative integer.');
        }

        return (int) $value;
    }

    private function packageBoolean(Server_Package $package, string $name, bool $default): bool
    {
        $value = $package->getCustomValue($name);
        if ($value === null || $value === '') {
            return $default;
        }

        return $this->booleanValue($value) === '1';
    }

    private function booleanValue(mixed $value): string
    {
        return FOSSBilling\Tools::normalizeBoolean($value, false) ? '1' : '0';
    }

    private function randomAlphaNumeric(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    private function arrayScalar(array $values): string
    {
        $clean = array_values(array_filter(array_map('strval', $values), static fn ($value) => $value !== ''));

        return $clean === [] ? '' : '&' . implode('&', $clean) . '&';
    }

    private function triggerValue(): string
    {
        $this->lastTrigger = max(time(), $this->lastTrigger + 1);

        return (string) $this->lastTrigger;
    }

    private function commandName(string $command): string
    {
        return strtoupper(strtok(trim($command), " \t\r\n") ?: 'UNKNOWN');
    }

    private function isTokenError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'invalid or expired token')
            || str_contains($message, 'token expired')
            || str_contains($message, 'invalid token');
    }

    private function isMysqlCollisionError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'collision')
            || str_contains($message, 'exist_user_and_db');
    }

    private function redact(string $message): string
    {
        foreach ([
            (string) ($this->_config['password'] ?? ''),
            (string) ($this->_config['accesshash'] ?? ''),
            (string) ($this->token ?? ''),
            (string) ($this->sessionId ?? ''),
        ] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return preg_replace(
            '/\b(password|pass|token|sessionid)\s*=\s*"[^"]*"/i',
            '$1="[REDACTED]"',
            $message
        ) ?? $message;
    }

    private function sensitiveValues(array $payload): array
    {
        $values = [];
        $walk = static function (array $items) use (&$walk, &$values): void {
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                    continue;
                }
                if (in_array(strtolower((string) $key), ['password', 'pass', 'token', 'sessionid'], true)
                    && is_scalar($value)
                    && (string) $value !== ''
                ) {
                    $values[] = (string) $value;
                }
            }
        };
        $walk($payload);

        $command = (string) ($payload['cmd'] ?? '');
        if (preg_match_all('/\b(?:password|pass|token|sessionid)\s*=\s*"([^"]+)"/i', $command, $matches)) {
            array_push($values, ...$matches[1]);
        }

        return array_values(array_unique(array_filter($values, static fn ($value) => $value !== '')));
    }
}
