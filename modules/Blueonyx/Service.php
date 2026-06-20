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
 * SPDX-License-Identifier: Apache-2.0.
 *
 * BlueOnyx hosting plan editor companion module.
 */
namespace Box\Mod\Blueonyx;

use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\Tools;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Service implements InjectionAwareInterface
{
    private const SERVER_MANAGER = 'Blueonyx';

    private const PHP_HANDLERS = [
        'FPM' => 'FPM',
        'suPHP' => 'suPHP',
    ];

    private const PHP_VERSIONS = [
        'PHPOS' => 'PHPOS',
        'PHP74' => 'PHP74',
        'PHP80' => 'PHP80',
        'PHP81' => 'PHP81',
        'PHP82' => 'PHP82',
        'PHP83' => 'PHP83',
        'PHP84' => 'PHP84',
        'PHP85' => 'PHP85',
    ];

    private const SHELL_LEVELS = [
        '0' => 'None',
        '1' => 'Chrooted SFTP, SCP and RSYNC',
        '2' => 'Chrooted Shell, SFTP, SCP and RSYNC',
        '3' => 'Full Shell Access',
    ];

    private const BOOLEAN_FIELDS = [
        'cgi',
        'ssi',
        'ftp_nonadmin',
        'email_disabled',
        'web_alias_redirects',
        'dns_auto',
        'email_autoconfig',
        'mysql_enabled',
        'subdomains_enabled',
    ];

    private const INTEGER_FIELDS = [
        'bandwidth',
        'quota',
        'max_addon',
        'max_ftp',
        'max_sql',
        'max_pop',
        'max_sub',
        'max_park',
    ];

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage BlueOnyx hosting plans'),
                'description' => __trans('Allows the staff member to create, edit, adopt, and inspect BlueOnyx hosting plans.'),
            ],
        ];
    }

    public function getFieldSections(): array
    {
        return [
            'core' => [
                'title' => __trans('Core limits'),
                'description' => __trans('These fields define the plan size and the BlueOnyx user cap.'),
                'fields' => [
                    [
                        'name' => 'name',
                        'type' => 'text',
                        'label' => __trans('Plan name'),
                        'help' => __trans('Administrative label for this hosting plan.'),
                        'required' => true,
                    ],
                    [
                        'name' => 'quota',
                        'type' => 'number',
                        'label' => __trans('Disk quota'),
                        'help' => __trans('Enter the quota in MB. BlueOnyx renders 1000 MB as 1 GB, so use 1000 for a 1 GB plan.'),
                        'required' => true,
                        'min' => 0,
                    ],
                    [
                        'name' => 'maxusers',
                        'type' => 'number',
                        'label' => __trans('Maximum users'),
                        'help' => __trans('BlueOnyx uses this as the plan-wide user cap. The editor maps it into the internal FOSSBilling fields automatically.'),
                        'required' => true,
                        'min' => 0,
                    ],
                    [
                        'name' => 'subdomains_enabled',
                        'type' => 'checkbox',
                        'label' => __trans('Enable subdomains'),
                        'help' => __trans('When disabled, the subdomain limit is forced to 0.'),
                    ],
                    [
                        'name' => 'max_sub',
                        'type' => 'number',
                        'label' => __trans('Maximum subdomains'),
                        'help' => __trans('Shown only when subdomains are enabled.'),
                        'required' => true,
                        'min' => 0,
                        'depends_on' => 'subdomains_enabled',
                    ],
                ],
            ],
            'runtime' => [
                'title' => __trans('Runtime settings'),
                'description' => __trans('These settings control PHP and site capabilities.'),
                'fields' => [
                    [
                        'name' => 'php_handler',
                        'type' => 'select',
                        'label' => __trans('PHP handler'),
                        'required' => true,
                        'options' => $this->getPhpHandlerOptions(),
                    ],
                    [
                        'name' => 'php_version',
                        'type' => 'select',
                        'label' => __trans('PHP version'),
                        'required' => true,
                        'options' => $this->getPhpVersionOptions(),
                    ],
                    [
                        'name' => 'shell',
                        'type' => 'select',
                        'label' => __trans('Shell level access'),
                        'required' => true,
                        'options' => $this->getShellOptions(),
                    ],
                    [
                        'name' => 'cgi',
                        'type' => 'checkbox',
                        'label' => __trans('Enable CGI'),
                    ],
                    [
                        'name' => 'ssi',
                        'type' => 'checkbox',
                        'label' => __trans('Enable SSI'),
                    ],
                    [
                        'name' => 'ftp_nonadmin',
                        'type' => 'checkbox',
                        'label' => __trans('Allow FTP for non-admin users'),
                    ],
                    [
                        'name' => 'email_disabled',
                        'type' => 'checkbox',
                        'label' => __trans('Disable email'),
                    ],
                    [
                        'name' => 'web_alias_redirects',
                        'type' => 'checkbox',
                        'label' => __trans('Enable web alias redirects'),
                    ],
                    [
                        'name' => 'dns_auto',
                        'type' => 'checkbox',
                        'label' => __trans('Create DNS records automatically'),
                    ],
                    [
                        'name' => 'email_autoconfig',
                        'type' => 'checkbox',
                        'label' => __trans('Enable email autoconfiguration'),
                        'help' => __trans('Adds autoconfig. and autodiscover. web aliases for BlueOnyx mail clients.'),
                    ],
                ],
            ],
            'database' => [
                'title' => __trans('Database settings'),
                'description' => __trans('MariaDB is optional and expands the database limit when enabled.'),
                'fields' => [
                    [
                        'name' => 'mysql_enabled',
                        'type' => 'checkbox',
                        'label' => __trans('Enable MariaDB'),
                        'help' => __trans('When disabled, the database limit is forced to 0.'),
                    ],
                    [
                        'name' => 'max_sql',
                        'type' => 'number',
                        'label' => __trans('Maximum databases'),
                        'help' => __trans('Shown only when MariaDB is enabled.'),
                        'required' => true,
                        'min' => 0,
                        'depends_on' => 'mysql_enabled',
                    ],
                ],
            ],
        ];
    }

    public function getDefaultValues(): array
    {
        return [
            'name' => 'BlueOnyx Vsite Plan',
            'bandwidth' => 0,
            'quota' => 1000,
            'max_addon' => 0,
            'max_ftp' => 25,
            'max_sql' => 1,
            'max_pop' => 25,
            'max_sub' => 1,
            'max_park' => 0,
            'php_handler' => 'FPM',
            'php_version' => 'PHPOS',
            'cgi' => '0',
            'ssi' => '0',
            'shell' => '0',
            'ftp_nonadmin' => '0',
            'email_disabled' => '0',
            'web_alias_redirects' => '1',
            'dns_auto' => '1',
            'email_autoconfig' => '0',
            'mysql_enabled' => '1',
            'subdomains_enabled' => '1',
            'maxusers' => 25,
        ];
    }

    public function getPlanList(): array
    {
        $this->ensureHookListeners();
        $rows = $this->di['db']->getAll('SELECT id FROM service_hosting_hp ORDER BY id ASC');
        $plans = [];

        foreach ($rows as $row) {
            $plans[] = $this->getPlanSnapshot((int) $row['id']);
        }

        return $plans;
    }

    public function getPlanSnapshot(int $id): array
    {
        $this->ensureHookListeners();
        $model = $this->di['db']->getExistingModelById('ServiceHostingHp', $id, 'Hosting plan not found');

        return $this->buildSnapshot($model);
    }

    public function getNewPlanSnapshot(): array
    {
        $snapshot = $this->defaultSnapshot();
        $snapshot['state'] = 'new';
        $snapshot['can_edit'] = true;
        $snapshot['can_adopt'] = false;
        $snapshot['can_save'] = true;
        $snapshot['title'] = __trans('Create BlueOnyx plan');
        $snapshot['submit_label'] = __trans('Create plan');

        return $snapshot;
    }

    public function createPlan(array $data): int
    {
        $this->ensureHookListeners();
        $this->assertManagerAvailable();
        $normalized = $this->normalizePlanData($data, null, 'create');
        $model = $this->di['db']->dispense('ServiceHostingHp');
        $this->applyNativeFields($model, $normalized);
        $this->applyConfig($model, $normalized['config']);
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = $model->created_at;

        $id = (int) $this->di['db']->store($model);
        $this->verifyPersistedPlan($id, $normalized, 'create');

        return $id;
    }

    public function updatePlan(int $id, array $data): bool
    {
        $this->ensureHookListeners();
        $this->assertManagerAvailable();
        $model = $this->di['db']->getExistingModelById('ServiceHostingHp', $id, 'Hosting plan not found');
        $this->assertEditableState($model, false);

        $normalized = $this->normalizePlanData($data, $model, 'update');
        $this->applyNativeFields($model, $normalized);
        $this->applyConfig($model, $normalized['config']);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $this->verifyPersistedPlan($id, $normalized, 'update');

        return true;
    }

    public function adoptPlan(int $id, array $data): bool
    {
        $this->ensureHookListeners();
        $this->assertManagerAvailable();
        $model = $this->di['db']->getExistingModelById('ServiceHostingHp', $id, 'Hosting plan not found');
        $this->assertEditableState($model, true);

        $normalized = $this->normalizePlanData($data, $model, 'adopt');
        $this->applyNativeFields($model, $normalized);
        $this->applyConfig($model, $normalized['config']);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $this->verifyPersistedPlan($id, $normalized, 'adopt');

        return true;
    }

    private function buildSnapshot(\Model_ServiceHostingHp $model): array
    {
        $servicehosting = $this->di['mod_service']('servicehosting');
        $snapshot = $servicehosting->toHostingHpApiArray($model, true, null);

        $config = $snapshot['config'];
        if (!is_array($config)) {
            $config = [];
        }

        $state = $this->classifyState($config);
        $managedConfig = $this->getManagedConfigValues($config);
        $extraConfig = array_diff_key($config, $managedConfig);

        $snapshot['config'] = $managedConfig;
        $snapshot['extra_config'] = $extraConfig;
        $snapshot['state'] = $state;
        $snapshot['marker'] = $config['server_manager'] ?? null;
        $snapshot['manager_available'] = $this->isManagerAvailable();
        $snapshot['manager_message'] = $snapshot['manager_available'] ? null : __trans('BlueOnyx Server Manager is not installed on this system.');
        $snapshot['can_edit'] = $state !== 'foreign';
        $snapshot['can_adopt'] = $state === 'unmarked';
        $snapshot['can_save'] = $state !== 'foreign' && $snapshot['manager_available'];
        $snapshot['title'] = $state === 'unmarked' ? __trans('Adopt BlueOnyx plan') : $snapshot['name'];
        $snapshot['submit_label'] = $state === 'unmarked' ? __trans('Adopt plan') : __trans('Save plan');
        $snapshot['values'] = array_merge(
            [
                'name' => $snapshot['name'],
                'quota' => $snapshot['quota'],
                'maxusers' => $snapshot['max_pop'],
                'max_sub' => $snapshot['max_sub'],
                'php_handler' => $managedConfig['php_handler'] ?? null,
                'php_version' => $managedConfig['php_version'] ?? null,
                'cgi' => $managedConfig['cgi'] ?? null,
                'ssi' => $managedConfig['ssi'] ?? null,
                'shell' => $managedConfig['shell'] ?? null,
                'ftp_nonadmin' => $managedConfig['ftp_nonadmin'] ?? null,
                'email_disabled' => $managedConfig['email_disabled'] ?? null,
                'web_alias_redirects' => $managedConfig['web_alias_redirects'] ?? null,
                'dns_auto' => $managedConfig['dns_auto'] ?? null,
                'email_autoconfig' => $managedConfig['email_autoconfig'] ?? null,
                'mysql_enabled' => $managedConfig['mysql_enabled'] ?? null,
                'subdomains_enabled' => $managedConfig['subdomains_enabled'] ?? null,
                'max_sql' => $snapshot['max_sql'],
            ],
            $managedConfig
        );
        $snapshot['hidden_native'] = [
            'bandwidth' => $snapshot['bandwidth'],
            'max_addon' => $snapshot['max_addon'],
            'max_ftp' => $snapshot['max_ftp'],
            'max_pop' => $snapshot['max_pop'],
            'max_park' => $snapshot['max_park'],
        ];

        return $snapshot;
    }

    private function defaultSnapshot(): array
    {
        $defaults = $this->getDefaultValues();

        return [
            'id' => null,
            'name' => $defaults['name'],
            'bandwidth' => $defaults['bandwidth'],
            'quota' => $defaults['quota'],
            'max_addon' => $defaults['max_addon'],
            'max_ftp' => $defaults['max_ftp'],
            'max_sql' => $defaults['max_sql'],
            'max_pop' => $defaults['max_pop'],
            'max_sub' => $defaults['max_sub'],
            'max_park' => $defaults['max_park'],
            'config' => $this->getManagedConfigValues($defaults),
            'extra_config' => [],
            'state' => 'new',
            'marker' => self::SERVER_MANAGER,
            'manager_available' => $this->isManagerAvailable(),
            'manager_message' => $this->isManagerAvailable() ? null : __trans('BlueOnyx Server Manager is not installed on this system.'),
            'can_edit' => true,
            'can_adopt' => false,
            'can_save' => $this->isManagerAvailable(),
            'title' => __trans('Create BlueOnyx plan'),
            'submit_label' => __trans('Create plan'),
            'values' => $defaults,
            'hidden_native' => [
                'bandwidth' => $defaults['bandwidth'],
                'max_addon' => $defaults['max_addon'],
                'max_ftp' => $defaults['max_ftp'],
                'max_pop' => $defaults['max_pop'],
                'max_park' => $defaults['max_park'],
            ],
        ];
    }

    private function getManagedConfigValues(array $config): array
    {
        $managed = [];
        $defaults = $this->getDefaultValues();

        $managed['server_manager'] = self::SERVER_MANAGER;
        foreach ($this->getManagedFieldDefaults() as $key => $default) {
            $managed[$key] = $this->normalizeConfigScalar($config[$key] ?? $default);
        }

        if (array_key_exists('maxusers', $config)) {
            $managed['maxusers'] = $this->normalizeConfigScalar($config['maxusers']);
        }

        return $managed;
    }

    private function getManagedFieldDefaults(): array
    {
        $defaults = $this->getDefaultValues();

        return [
            'php_handler' => $defaults['php_handler'],
            'php_version' => $defaults['php_version'],
            'cgi' => $defaults['cgi'],
            'ssi' => $defaults['ssi'],
            'shell' => $defaults['shell'],
            'ftp_nonadmin' => $defaults['ftp_nonadmin'],
            'email_disabled' => $defaults['email_disabled'],
            'web_alias_redirects' => $defaults['web_alias_redirects'],
            'dns_auto' => $defaults['dns_auto'],
            'email_autoconfig' => $defaults['email_autoconfig'],
            'mysql_enabled' => $defaults['mysql_enabled'],
            'subdomains_enabled' => $defaults['subdomains_enabled'],
        ];
    }

    private function classifyState(array $config): string
    {
        $marker = trim((string) ($config['server_manager'] ?? ''));

        if ($marker === '') {
            return 'unmarked';
        }

        if ($marker === self::SERVER_MANAGER) {
            return 'blueonyx';
        }

        return 'foreign';
    }

    private function assertEditableState(\Model_ServiceHostingHp $model, bool $allowUnmarked): void
    {
        $state = $this->classifyState($this->decodeConfig($model->config ?? null));

        if ($state === 'foreign') {
            throw new InformationException('This hosting plan is assigned to another server manager. Adopt it explicitly before editing.');
        }

        if (!$allowUnmarked && $state !== 'blueonyx') {
            throw new InformationException('This hosting plan must be adopted as BlueOnyx before editing.');
        }
    }

    private function decodeConfig(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePlanData(array $data, ?\Model_ServiceHostingHp $model, string $mode): array
    {
        $existingConfig = $model ? $this->decodeConfig($model->config ?? null) : [];
        $defaults = $this->getDefaultValues();

        $normalized = [];
        $normalized['name'] = $this->normalizeName($data['name'] ?? $defaults['name']);
        $normalized['bandwidth'] = $defaults['bandwidth'];
        $normalized['quota'] = $this->normalizeIntegerValue($data['quota'] ?? ($model instanceof \Model_ServiceHostingHp ? $model->quota : $defaults['quota']), 'quota');
        $normalized['max_addon'] = $defaults['max_addon'];
        $normalized['max_ftp'] = null;
        $normalized['max_sql'] = $defaults['max_sql'];
        $normalized['max_pop'] = null;
        $normalized['max_sub'] = $this->normalizeIntegerValue($data['max_sub'] ?? ($model instanceof \Model_ServiceHostingHp ? $model->max_sub : $defaults['max_sub']), 'max_sub');
        $normalized['max_park'] = $defaults['max_park'];

        $config = $existingConfig;
        $config['server_manager'] = self::SERVER_MANAGER;
        $config['php_handler'] = $this->normalizePhpHandler($data, $existingConfig, $defaults['php_handler']);
        $config['php_version'] = $this->normalizePhpVersion($data, $existingConfig, $defaults['php_version']);
        $config['shell'] = $this->normalizeShellLevel($data, $existingConfig, $defaults['shell']);
        $config['mysql_enabled'] = $this->normalizeBooleanField($data, $existingConfig, 'mysql_enabled', $defaults['mysql_enabled']);
        $config['subdomains_enabled'] = $this->normalizeBooleanField($data, $existingConfig, 'subdomains_enabled', $defaults['subdomains_enabled']);
        $config['email_autoconfig'] = $this->normalizeBooleanField($data, $existingConfig, 'email_autoconfig', $defaults['email_autoconfig']);

        foreach (self::BOOLEAN_FIELDS as $field) {
            if ($field === 'mysql_enabled' || $field === 'subdomains_enabled' || $field === 'email_autoconfig') {
                continue;
            }
            $config[$field] = $this->normalizeBooleanField($data, $existingConfig, $field, $defaults[$field]);
        }

        $maxUsers = $this->normalizeMaxUsers($data, $existingConfig, $mode, $defaults['maxusers']);
        if ($maxUsers === null) {
            unset($config['maxusers']);
        } else {
            $config['maxusers'] = (string) $maxUsers;
        }

        if (Tools::normalizeBoolean($config['mysql_enabled'] ?? false, false)) {
            $normalized['max_sql'] = $this->normalizeIntegerValue($data['max_sql'] ?? ($model instanceof \Model_ServiceHostingHp ? $model->max_sql : $defaults['max_sql']), 'max_sql');
        } else {
            $normalized['max_sql'] = 0;
        }

        if (Tools::normalizeBoolean($config['subdomains_enabled'] ?? false, false)) {
            $normalized['max_sub'] = $this->normalizeIntegerValue($data['max_sub'] ?? ($model instanceof \Model_ServiceHostingHp ? $model->max_sub : $defaults['max_sub']), 'max_sub');
        } else {
            $normalized['max_sub'] = 0;
        }

        $normalized['max_ftp'] = $maxUsers;
        $normalized['max_pop'] = $maxUsers;

        $this->validateCrossFieldConstraints($normalized, $config);

        $normalized['config'] = $config;

        return $normalized;
    }

    private function normalizeConfigScalar(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return (string) $value;
    }

    private function normalizeName(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new InformationException('Hosting plan name cannot be empty.');
        }

        return $name;
    }

    private function normalizeIntegerValue(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value < 0) {
            throw new InformationException(sprintf('Field %s must be a non-negative integer.', $field));
        }

        return (int) $value;
    }

    private function normalizeBooleanField(array $data, array $existingConfig, string $field, string $default): string
    {
        if (!array_key_exists($field, $data)) {
            return (string) ($existingConfig[$field] ?? $default);
        }

        return Tools::normalizeBoolean($data[$field], $default === '1') ? '1' : '0';
    }

    private function normalizePhpHandler(array $data, array $existingConfig, string $default): string
    {
        return $this->normalizeSelectValue($data, $existingConfig, 'php_handler', array_keys(self::PHP_HANDLERS), $default);
    }

    private function normalizePhpVersion(array $data, array $existingConfig, string $default): string
    {
        $value = $this->normalizeSelectValue($data, $existingConfig, 'php_version', self::PHP_VERSIONS, $default);

        if ($value === 'PHPOS') {
            return $value;
        }

        $normalized = strtoupper(str_replace([' ', '.'], '', $value));
        if (preg_match('/^PHP([0-9]{2})$/', $normalized, $matches)) {
            $canonical = 'PHP' . $matches[1];
            if (array_key_exists($canonical, self::PHP_VERSIONS)) {
                return $canonical;
            }
        }

        throw new InformationException('PHP version must match PHPOS or PHP followed by two digits.');
    }

    private function normalizeShellLevel(array $data, array $existingConfig, string $default): string
    {
        return $this->normalizeSelectValue($data, $existingConfig, 'shell', self::SHELL_LEVELS, $default);
    }

    private function normalizeMaxUsers(array $data, array $existingConfig, string $mode, int $default): ?int
    {
        if (!array_key_exists('maxusers', $data)) {
            if (array_key_exists('maxusers', $existingConfig)) {
                return (int) $existingConfig['maxusers'];
            }

            return $default;
        }

        $value = trim((string) $data['maxusers']);
        if ($value === '') {
            if (array_key_exists('maxusers', $existingConfig)) {
                return (int) $existingConfig['maxusers'];
            }

            return $default;
        }

        if (!ctype_digit($value)) {
            throw new InformationException('Maximum users override must be a non-negative integer.');
        }

        return (int) $value;
    }

    private function normalizeSelectValue(array $data, array $existingConfig, string $field, array $allowedValues, string $default): string
    {
        $value = array_key_exists($field, $data) ? trim((string) $data[$field]) : (string) ($existingConfig[$field] ?? $default);
        if ($value === '') {
            $value = (string) ($existingConfig[$field] ?? $default);
        }

        if (array_key_exists($value, $allowedValues)) {
            return (string) $value;
        }

        $labelToValue = array_flip($allowedValues);
        if (array_key_exists($value, $labelToValue)) {
            return (string) $labelToValue[$value];
        }

        throw new InformationException(sprintf('Field %s contains an unsupported value.', $field));
    }

    private function validateCrossFieldConstraints(array $native, array $config): void
    {
        $mysqlEnabled = Tools::normalizeBoolean($config['mysql_enabled'] ?? false, false);
        $subdomainsEnabled = Tools::normalizeBoolean($config['subdomains_enabled'] ?? false, false);
        $maxSql = (int) $native['max_sql'];
        $maxSub = (int) $native['max_sub'];

        if ($mysqlEnabled && $maxSql <= 0) {
            throw new InformationException('MariaDB cannot be enabled when the hosting plan allows zero databases.');
        }

        if ($subdomainsEnabled && $maxSub <= 0) {
            throw new InformationException('Subdomains cannot be enabled when the hosting plan allows zero subdomains.');
        }
    }

    private function applyNativeFields(\Model_ServiceHostingHp $model, array $normalized): void
    {
        $model->name = $normalized['name'];
        $model->bandwidth = $normalized['bandwidth'];
        $model->quota = $normalized['quota'];
        $model->max_addon = $normalized['max_addon'];
        $model->max_ftp = $normalized['max_ftp'];
        $model->max_sql = $normalized['max_sql'];
        $model->max_pop = $normalized['max_pop'];
        $model->max_sub = $normalized['max_sub'];
        $model->max_park = $normalized['max_park'];
    }

    private function applyConfig(\Model_ServiceHostingHp $model, array $config): void
    {
        $model->config = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function verifyPersistedPlan(int $id, array $normalized, string $mode): void
    {
        $snapshot = $this->getPlanSnapshot($id);
        $expectedConfig = $normalized['config'];
        $persistedConfig = $snapshot['config'];

        foreach (['name', 'bandwidth', 'quota', 'max_addon', 'max_ftp', 'max_sql', 'max_pop', 'max_sub', 'max_park'] as $field) {
            if ((string) $snapshot[$field] !== (string) $normalized[$field]) {
                throw new InformationException(sprintf('BlueOnyx plan verification failed after %s for %s.', $mode, $field));
            }
        }

        foreach ($expectedConfig as $key => $value) {
            if (!array_key_exists($key, $persistedConfig) || (string) $persistedConfig[$key] !== (string) $value) {
                throw new InformationException(sprintf('BlueOnyx plan verification failed after %s for config key %s.', $mode, $key));
            }
        }
    }

    public function isManagerAvailable(): bool
    {
        return class_exists('Server_Manager_Blueonyx');
    }

    public function install(): bool
    {
        $this->ensureHookListeners();
        $this->deployAdminPartialBbMetaOverride();
        $this->deployAdminOrderManageOverride();
        $this->deployAdminServicehostingManageOverride();
        $this->deployClientServicehostingManageOverride();
        $this->deployClientOrderbuttonCheckoutOverride();
        $this->deployClientOrderbuttonJsOverride();

        return true;
    }

    public function uninstall(): bool
    {
        $this->removeAdminPartialBbMetaOverride();
        $this->removeAdminOrderManageOverride();
        $this->removeAdminServicehostingManageOverride();
        $this->removeClientServicehostingManageOverride();
        $this->removeClientOrderbuttonCheckoutOverride();
        $this->removeClientOrderbuttonJsOverride();

        return true;
    }

    public function syncHooks(): bool
    {
        $this->ensureHookListeners();

        return true;
    }

    private function assertManagerAvailable(): void
    {
        if (!$this->isManagerAvailable()) {
            throw new InformationException('BlueOnyx Server Manager is not installed on this system.');
        }
    }

    private function getPhpHandlerOptions(): array
    {
        return self::PHP_HANDLERS;
    }

    private function getPhpVersionOptions(): array
    {
        return self::PHP_VERSIONS;
    }

    private function getShellOptions(): array
    {
        return self::SHELL_LEVELS;
    }

    private function ensureHookListeners(): void
    {
        if ($this->di === null) {
            return;
        }

        try {
            $this->di['mod_service']('hook')->batchConnect('Blueonyx');
        } catch (\Throwable $e) {
            $this->di['logger']->warning('Unable to synchronize BlueOnyx event hooks.', ['exception' => $e->getMessage()]);
        }
    }

    private function deployAdminOrderManageOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'admin', 'mod_order_manage.html.twig'),
            Path::join('themes', 'admin_default', 'html_custom', 'mod_order_manage.html.twig'),
            'BlueOnyx admin order template override source file is missing.'
        );
    }

    private function removeAdminOrderManageOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'admin_default', 'html_custom', 'mod_order_manage.html.twig'));
    }

    private function deployAdminServicehostingManageOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'admin', 'mod_servicehosting_manage.html.twig'),
            Path::join('themes', 'admin_default', 'html_custom', 'mod_servicehosting_manage.html.twig'),
            'BlueOnyx admin service template override source file is missing.'
        );
    }

    private function removeAdminServicehostingManageOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'admin_default', 'html_custom', 'mod_servicehosting_manage.html.twig'));
    }

    private function deployClientServicehostingManageOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'client', 'mod_servicehosting_manage.html.twig'),
            Path::join('themes', 'huraga', 'html_custom', 'mod_servicehosting_manage.html.twig'),
            'BlueOnyx client service template override source file is missing.'
        );
    }

    private function removeClientServicehostingManageOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'huraga', 'html_custom', 'mod_servicehosting_manage.html.twig'));
    }

    private function deployClientOrderbuttonCheckoutOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'client', 'mod_orderbutton_checkout.html.twig'),
            Path::join('themes', 'huraga', 'html_custom', 'mod_orderbutton_checkout.html.twig'),
            'BlueOnyx client checkout template override source file is missing.'
        );
    }

    private function removeClientOrderbuttonCheckoutOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'huraga', 'html_custom', 'mod_orderbutton_checkout.html.twig'));
    }

    private function deployClientOrderbuttonJsOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'client', 'mod_orderbutton_js.html.twig'),
            Path::join('themes', 'huraga', 'html_custom', 'mod_orderbutton_js.html.twig'),
            'BlueOnyx client checkout JS override source file is missing.'
        );
    }

    private function removeClientOrderbuttonJsOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'huraga', 'html_custom', 'mod_orderbutton_js.html.twig'));
    }

    private function deployAdminPartialBbMetaOverride(): void
    {
        $this->deployTemplateOverride(
            Path::join('templates', 'overrides', 'admin', 'partial_bb_meta.html.twig'),
            Path::join('themes', 'admin_default', 'html_custom', 'partial_bb_meta.html.twig'),
            'BlueOnyx admin pending-message template override source file is missing.'
        );
    }

    private function removeAdminPartialBbMetaOverride(): void
    {
        $this->removeTemplateOverride(Path::join('themes', 'admin_default', 'html_custom', 'partial_bb_meta.html.twig'));
    }

    private function deployTemplateOverride(string $sourceRelative, string $targetRelative, string $missingMessage): void
    {
        $filesystem = new Filesystem();
        $source = Path::join(__DIR__, $sourceRelative);
        $target = Path::join(PATH_ROOT, $targetRelative);
        $targetDir = dirname($target);

        if (!$filesystem->exists($source)) {
            throw new InformationException($missingMessage);
        }

        $filesystem->mkdir($targetDir);
        $filesystem->copy($source, $target, true);
    }

    private function removeTemplateOverride(string $targetRelative): void
    {
        $filesystem = new Filesystem();
        $target = Path::join(PATH_ROOT, $targetRelative);

        if ($filesystem->exists($target)) {
            $filesystem->remove($target);
        }
    }

    public static function onAfterAdminOrderActivate(\Box_Event $event): void
    {
        self::queueOrderLifecycleMessage($event, 'activated');
    }

    public static function onBeforeAdminOrderActivate(\Box_Event $event): void
    {
        self::assertBlueOnyxOrderHasAttachedService($event, 'activate');
    }

    public static function onAfterAdminOrderUncancel(\Box_Event $event): void
    {
        self::queueOrderLifecycleMessage($event, 'reactivated');
    }

    public static function onBeforeAdminOrderUncancel(\Box_Event $event): void
    {
        self::assertBlueOnyxOrderHasAttachedService($event, 'reactivate');
    }

    public static function onAfterAdminOrderSuspend(\Box_Event $event): void
    {
        self::queueOrderLifecycleMessage($event, 'suspended');
    }

    public static function onBeforeAdminOrderSuspend(\Box_Event $event): void
    {
        self::assertBlueOnyxOrderHasAttachedService($event, 'suspend');
    }

    public static function onAfterAdminOrderUnsuspend(\Box_Event $event): void
    {
        self::queueOrderLifecycleMessage($event, 'unsuspended');
    }

    public static function onBeforeAdminOrderUnsuspend(\Box_Event $event): void
    {
        self::assertBlueOnyxOrderHasAttachedService($event, 'unsuspend');
    }

    public static function onAfterAdminOrderCancel(\Box_Event $event): void
    {
        self::queueOrderLifecycleMessage($event, 'canceled');
    }

    public static function onBeforeAdminOrderCancel(\Box_Event $event): void
    {
        self::assertBlueOnyxOrderHasAttachedService($event, 'cancel');
    }

    public static function onBeforeAdminOrderDelete(\Box_Event $event): void
    {
        self::prepareDeleteLifecycleMessage($event);
    }

    public static function onAfterAdminOrderDelete(\Box_Event $event): void
    {
        self::flushDeleteLifecycleMessage($event);
    }

    public static function onAfterAdminActivateExtension(\Box_Event $event): void
    {
        self::syncHooksAfterExtensionChange($event);
    }

    public static function onAfterAdminUpdateExtension(\Box_Event $event): void
    {
        self::syncHooksAfterExtensionChange($event);
    }

    private static function syncHooksAfterExtensionChange(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $extId = (int) ($params['id'] ?? 0);
        if ($extId <= 0) {
            return;
        }

        try {
            $ext = $di['db']->load('extension', $extId);
            if (!is_object($ext) || $ext->type !== 'mod' || $ext->name !== 'Blueonyx') {
                return;
            }

            $di['mod_service']('hook')->batchConnect('Blueonyx');
        } catch (\Throwable $e) {
            $di['logger']->warning('Unable to resynchronize BlueOnyx event hooks after extension change.', [
                'exception' => $e->getMessage(),
                'extension_id' => $extId,
            ]);
        }
    }

    private static function queueOrderLifecycleMessage(\Box_Event $event, string $statusText): void
    {
        $di = $event->getDi();
        $message = self::buildOrderLifecycleMessage($event, $statusText);
        if ($message === null) {
            return;
        }

        try {
            $di['mod_service']('system')->setPendingMessage($message);
        } catch (\Throwable $e) {
            $di['logger']->warning('Unable to store BlueOnyx order lifecycle message.', [
                'exception' => $e->getMessage(),
                'status' => $statusText,
            ]);
        }
    }

    private static function prepareDeleteLifecycleMessage(\Box_Event $event): void
    {
        $di = $event->getDi();
        $message = self::buildOrderLifecycleMessage($event, 'deleted');
        if ($message === null) {
            return;
        }

        try {
            $di['session']->set('blueonyx_pending_delete_message', $message);
        } catch (\Throwable $e) {
            $di['logger']->warning('Unable to stage BlueOnyx delete lifecycle message.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private static function flushDeleteLifecycleMessage(\Box_Event $event): void
    {
        $di = $event->getDi();
        try {
            $message = $di['session']->get('blueonyx_pending_delete_message');
            $di['session']->delete('blueonyx_pending_delete_message');
            if (is_string($message) && $message !== '') {
                $di['mod_service']('system')->setPendingMessage($message);
            }
        } catch (\Throwable $e) {
            $di['logger']->warning('Unable to flush BlueOnyx delete lifecycle message.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private static function buildOrderLifecycleMessage(\Box_Event $event, string $statusText): ?string
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $orderId = (int) ($params['id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        try {
            $orderService = $di['mod_service']('order');
            $servicehosting = $di['mod_service']('servicehosting');
            $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
            $service = $orderService->getOrderService($order);
            if (!$service instanceof \Model_ServiceHosting) {
                return null;
            }

            $server = $di['db']->getExistingModelById('ServiceHostingServer', $service->service_hosting_server_id, 'Server not found');
            $manager = $servicehosting->getServerManager($server);
            if (!$manager instanceof \Server_Manager_Blueonyx) {
                return null;
            }

            $serviceData = $servicehosting->toApiArray($service, true, null);
            $domain = (string) ($serviceData['domain'] ?? $order->title ?? ('#' . $orderId));

            return match ($statusText) {
                'activated' => sprintf('BlueOnyx Vsite %s was created successfully.', $domain),
                'reactivated' => sprintf('BlueOnyx Vsite %s was reactivated successfully.', $domain),
                'suspended' => sprintf('BlueOnyx Vsite %s was suspended successfully.', $domain),
                'unsuspended' => sprintf('BlueOnyx Vsite %s was unsuspended successfully.', $domain),
                'canceled' => sprintf('BlueOnyx Vsite %s was canceled successfully.', $domain),
                'deleted' => sprintf('BlueOnyx Vsite %s was deleted successfully.', $domain),
                default => sprintf('BlueOnyx Vsite %s was updated successfully.', $domain),
            };
        } catch (\Throwable $e) {
            $di['logger']->warning('Unable to build BlueOnyx order lifecycle message.', [
                'exception' => $e->getMessage(),
                'order_id' => $orderId,
                'status' => $statusText,
            ]);

            return null;
        }
    }

    private static function assertBlueOnyxOrderHasAttachedService(\Box_Event $event, string $action): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $orderId = (int) ($params['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        try {
            $orderService = $di['mod_service']('order');
            $servicehosting = $di['mod_service']('servicehosting');
            $order = $di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
            $service = $orderService->getOrderService($order);
            if (!$service instanceof \Model_ServiceHosting) {
                return;
            }

            $server = $di['db']->getExistingModelById('ServiceHostingServer', $service->service_hosting_server_id, 'Server not found');
            $manager = $servicehosting->getServerManager($server);
            if (!$manager instanceof \Server_Manager_Blueonyx) {
                return;
            }
        } catch (\Throwable $e) {
            throw new \FOSSBilling\InformationException(sprintf(
                'BlueOnyx order #%d no longer has an attached service and cannot be %sed.',
                $orderId,
                $action
            ));
        }
    }
}
