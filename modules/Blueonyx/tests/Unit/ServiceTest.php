<?php

declare(strict_types=1);

use Box\Mod\Blueonyx\Service;

use function Tests\Helpers\container;

if (!class_exists('Server_Manager_Blueonyx')) {
    class Server_Manager_Blueonyx
    {
        public static int $instantiated = 0;

        public function __construct()
        {
            self::$instantiated++;
        }
    }
}

afterEach(function (): void {
    Mockery::close();
});

test('returns module permissions', function (): void {
    $service = new Service();

    expect($service->getModulePermissions())->toHaveKey('manage');
});

test('returns field sections', function (): void {
    $service = new Service();

    $sections = $service->getFieldSections();

    expect($sections)->toHaveKeys(['core', 'runtime', 'database']);
    expect($sections['core']['fields'])->toBeArray();
    expect($sections['database']['fields'][1]['depends_on'])->toBe('mysql_enabled');
    expect(array_column($sections['runtime']['fields'], 'name'))->toContain('email_autoconfig');
});

test('returns a trimmed default snapshot', function (): void {
    $service = new Service();
    $snapshot = $service->getNewPlanSnapshot();

    expect($snapshot['hidden_native']['bandwidth'])->toBe(0);
    expect($snapshot['values']['quota'])->toBe(1000);
    expect($snapshot['values']['maxusers'])->toBe(25);
});

test('creates a blueonyx plan with defaults and marker', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->andReturn($model);
    $dbMock->shouldReceive('store')->once()->andReturn(77);

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    $id = $service->createPlan([
        'name' => 'BlueOnyx Test',
        'quota' => 1000,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'cgi' => '0',
        'ssi' => '0',
        'shell' => '0',
        'ftp_nonadmin' => '0',
        'email_disabled' => '0',
        'web_alias_redirects' => '1',
        'dns_auto' => '1',
        'email_autoconfig' => '1',
        'mysql_enabled' => '1',
        'subdomains_enabled' => '1',
        'maxusers' => 25,
        'max_sql' => 1,
        'max_sub' => 1,
    ]);

    expect($id)->toBe(77);
    expect($model->name)->toBe('BlueOnyx Test');
    expect($model->bandwidth)->toBe(0);
    expect($model->quota)->toBe(1000);
    expect($model->max_pop)->toBe(25);
    expect($model->max_ftp)->toBe(25);
    expect(json_decode((string) $model->config, true))->toMatchArray([
        'server_manager' => 'Blueonyx',
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'maxusers' => '25',
        'email_autoconfig' => '1',
    ]);
});

test('stores managed numeric config values as strings', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->andReturn($model);
    $dbMock->shouldReceive('store')->once()->andReturn(80);

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    $service->createPlan([
        'name' => 'BlueOnyx String Config',
        'quota' => 1000,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
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
        'max_sql' => 1,
        'max_sub' => 1,
    ]);

    $config = json_decode((string) $model->config, true);
    expect($config['maxusers'])->toBe('25');
});

test('accepts shell labels during plan creation', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->andReturn($model);
    $dbMock->shouldReceive('store')->once()->andReturn(78);

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    $service->createPlan([
        'name' => 'BlueOnyx Shell Label',
        'quota' => 1000,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'cgi' => '0',
        'ssi' => '0',
        'shell' => 'None',
        'ftp_nonadmin' => '0',
        'email_disabled' => '0',
        'web_alias_redirects' => '1',
        'dns_auto' => '1',
        'email_autoconfig' => '0',
        'mysql_enabled' => '1',
        'subdomains_enabled' => '1',
        'maxusers' => 25,
        'max_sql' => 1,
        'max_sub' => 1,
    ]);

    expect(json_decode((string) $model->config, true)['shell'])->toBe('0');
});

test('accepts php version labels during plan creation', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $dbMock->shouldReceive('dispense')->andReturn($model);
    $dbMock->shouldReceive('store')->once()->andReturn(79);

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    $service->createPlan([
        'name' => 'BlueOnyx PHP Label',
        'quota' => 1000,
        'php_handler' => 'FPM',
        'php_version' => 'PHP 8.5',
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
        'max_sql' => 1,
        'max_sub' => 1,
    ]);

    expect(json_decode((string) $model->config, true)['php_version'])->toBe('PHP85');
});

test('adopts an unmarked plan', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->name = 'Existing Plan';
    $model->bandwidth = 1024;
    $model->quota = 1024;
    $model->max_addon = 1;
    $model->max_ftp = 1;
    $model->max_sql = 1;
    $model->max_pop = 25;
    $model->max_sub = 1;
    $model->max_park = 1;
    $model->config = json_encode(['php_handler' => 'FPM']);

    $dbMock->shouldReceive('getExistingModelById')->andReturn($model);
    $dbMock->shouldReceive('store')->once()->andReturnTrue();

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->adoptPlan(1, [
        'name' => 'Existing Plan',
        'quota' => 1024,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'cgi' => '0',
        'ssi' => '0',
        'shell' => '0',
        'ftp_nonadmin' => '0',
        'email_disabled' => '0',
        'web_alias_redirects' => '1',
        'dns_auto' => '1',
        'mysql_enabled' => '1',
        'subdomains_enabled' => '1',
        'maxusers' => 25,
        'max_sql' => 1,
        'max_sub' => 1,
    ]))->toBeTrue();

    $config = json_decode((string) $model->config, true);
    expect($config['server_manager'])->toBe('Blueonyx');
    expect($config['php_version'])->toBe('PHP85');
});

test('rejects foreign manager edits', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->config = json_encode(['server_manager' => 'Whm']);
    $dbMock->shouldReceive('getExistingModelById')->andReturn($model);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect(fn () => $service->updatePlan(1, ['name' => 'Forbidden']))
        ->toThrow(FOSSBilling\InformationException::class);
});

test('preserves unrelated config values on update', function (): void {
    Server_Manager_Blueonyx::$instantiated = 0;

    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 12;
    $model->name = 'Existing Plan';
    $model->bandwidth = 0;
    $model->quota = 1024;
    $model->max_addon = 0;
    $model->max_ftp = 25;
    $model->max_sql = 1;
    $model->max_pop = 25;
    $model->max_sub = 1;
    $model->max_park = 0;
    $model->config = json_encode([
        'server_manager' => 'Blueonyx',
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'custom_key' => 'keep-me',
    ]);

    $dbMock->shouldReceive('getExistingModelById')->andReturn($model);
    $dbMock->shouldReceive('store')->atLeast()->once()->andReturnTrue();

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $servicehostingMock->shouldReceive('toHostingHpApiArray')
        ->atLeast()->once()
        ->andReturnUsing(function (Model_ServiceHostingHp $model): array {
            return [
                'id' => $model->id,
                'name' => $model->name,
                'bandwidth' => $model->bandwidth,
                'quota' => $model->quota,
                'max_ftp' => $model->max_ftp,
                'max_sql' => $model->max_sql,
                'max_pop' => $model->max_pop,
                'max_sub' => $model->max_sub,
                'max_park' => $model->max_park,
                'max_addon' => $model->max_addon,
                'config' => json_decode((string) $model->config, true),
                'created_at' => $model->created_at,
                'updated_at' => $model->updated_at,
            ];
        });

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    expect($service->updatePlan(12, [
        'name' => 'Existing Plan Updated',
        'quota' => 2048,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'cgi' => '0',
        'ssi' => '0',
        'shell' => '0',
        'ftp_nonadmin' => '0',
        'email_disabled' => '0',
        'web_alias_redirects' => '1',
        'dns_auto' => '1',
        'mysql_enabled' => '1',
        'subdomains_enabled' => '1',
        'maxusers' => 30,
        'max_sql' => 3,
        'max_sub' => 2,
    ]))->toBeTrue();

    $config = json_decode((string) $model->config, true);
    expect($config['custom_key'])->toBe('keep-me');
    expect($config['server_manager'])->toBe('Blueonyx');
    expect(Server_Manager_Blueonyx::$instantiated)->toBe(0);
});

test('does not instantiate the blueonyx manager during plan editing', function (): void {
    Server_Manager_Blueonyx::$instantiated = 0;

    $service = new Service();
    $dbMock = Mockery::mock('\Box_Database');
    $model = new Model_ServiceHostingHp();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 14;
    $model->name = 'Existing Plan';
    $model->bandwidth = 0;
    $model->quota = 1024;
    $model->max_addon = 0;
    $model->max_ftp = 25;
    $model->max_sql = 1;
    $model->max_pop = 25;
    $model->max_sub = 1;
    $model->max_park = 0;
    $model->config = json_encode([
        'server_manager' => 'Blueonyx',
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
    ]);

    $dbMock->shouldReceive('getExistingModelById')->andReturn($model);
    $dbMock->shouldReceive('store')->atLeast()->once()->andReturnTrue();

    $servicehostingMock = Mockery::mock(Box\Mod\Servicehosting\Service::class);
    $servicehostingMock->shouldReceive('toHostingHpApiArray')
        ->atLeast()->once()
        ->andReturnUsing(function (Model_ServiceHostingHp $model): array {
            return [
                'id' => $model->id,
                'name' => $model->name,
                'bandwidth' => $model->bandwidth,
                'quota' => $model->quota,
                'max_ftp' => $model->max_ftp,
                'max_sql' => $model->max_sql,
                'max_pop' => $model->max_pop,
                'max_sub' => $model->max_sub,
                'max_park' => $model->max_park,
                'max_addon' => $model->max_addon,
                'config' => json_decode((string) $model->config, true),
                'created_at' => $model->created_at,
                'updated_at' => $model->updated_at,
            ];
        });

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($servicehostingMock) {
        return $module === 'servicehosting' ? $servicehostingMock : null;
    });
    $service->setDi($di);

    expect($service->updatePlan(14, [
        'name' => 'Existing Plan Updated Again',
        'quota' => 3072,
        'php_handler' => 'FPM',
        'php_version' => 'PHP85',
        'cgi' => '0',
        'ssi' => '0',
        'shell' => '0',
        'ftp_nonadmin' => '0',
        'email_disabled' => '0',
        'web_alias_redirects' => '1',
        'dns_auto' => '1',
        'mysql_enabled' => '1',
        'subdomains_enabled' => '1',
        'maxusers' => 30,
        'max_sql' => 3,
        'max_sub' => 2,
    ]))->toBeTrue();

    expect(Server_Manager_Blueonyx::$instantiated)->toBe(0);
});
