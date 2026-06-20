<?php

declare(strict_types=1);

use function Tests\Helpers\container;

afterEach(function (): void {
    Mockery::close();
});

function blueonyxManager(): Server_Manager_Blueonyx
{
    return new Server_Manager_Blueonyx([
        'host' => 'rain.smd.net',
        'username' => 'admin',
        'password' => 'secret',
        'config' => ['api_mode' => 'local'],
    ]);
}

test('resolves apex domains to www and compound public suffixes correctly', function (): void {
    $manager = blueonyxManager();
    $ref = new ReflectionMethod($manager, 'resolveProvisioningDomain');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'example.com'))->toBe(['www', 'example.com', 'www.example.com', true]);
    expect($ref->invoke($manager, 'example.co.uk'))->toBe(['www', 'example.co.uk', 'www.example.co.uk', true]);
});

test('keeps one-label subdomains as hostnames and leaves aliases off', function (): void {
    $manager = blueonyxManager();
    $ref = new ReflectionMethod($manager, 'resolveProvisioningDomain');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'mail.example.com'))->toBe(['mail', 'example.com', 'mail.example.com', false]);
});

test('builds blueonyx alias scalars in scalar format', function (): void {
    $manager = blueonyxManager();
    $ref = new ReflectionMethod($manager, 'aliasScalarList');
    $ref->setAccessible(true);

    expect($ref->invoke($manager, 'example.com'))->toBe([
        'webAliases' => '&example.com&',
        'mailAliases' => '&example.com&mail.example.com&',
    ]);
});

test('round-trips web alias scalar lists', function (): void {
    $manager = blueonyxManager();
    $toList = new ReflectionMethod($manager, 'scalarAliasesToList');
    $toList->setAccessible(true);
    $toScalar = new ReflectionMethod($manager, 'listToScalarAliases');
    $toScalar->setAccessible(true);

    $list = $toList->invoke($manager, '&example.com&autoconfig.example.com&autodiscover.example.com&');

    expect($list)->toBe([
        'example.com',
        'autoconfig.example.com',
        'autodiscover.example.com',
    ]);
    expect($toScalar->invoke($manager, $list))->toBe('&example.com&autoconfig.example.com&autodiscover.example.com&');
});

test('builds site admin email aliases from the client first name', function (): void {
    $manager = blueonyxManager();
    $ref = new ReflectionMethod($manager, 'siteAdminEmailAliases');
    $ref->setAccessible(true);

    $client = new class () {
        public function getFullName(): string
        {
            return 'example Tester';
        }
    };

    $account = Mockery::mock(Server_Account::class);
    $account->shouldReceive('getClient')->andReturn($client);
    $account->shouldReceive('getUsername')->andReturn('exampleco1');

    expect($ref->invoke($manager, $account))->toBe('&webmaster&info&example&');
});
