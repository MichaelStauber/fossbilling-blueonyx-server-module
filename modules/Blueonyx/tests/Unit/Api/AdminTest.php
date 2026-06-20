<?php

declare(strict_types=1);

use Box\Mod\Blueonyx\Api\Admin;

use function Tests\Helpers\container;

afterEach(function (): void {
    Mockery::close();
});

test('delegates list and edit operations to service', function (): void {
    $api = new Admin();
    $serviceMock = Mockery::mock(Box\Mod\Blueonyx\Service::class);
    $serviceMock->shouldReceive('getPlanList')->andReturn([]);
    $serviceMock->shouldReceive('getPlanSnapshot')->with(1)->andReturn(['id' => 1]);
    $serviceMock->shouldReceive('createPlan')->andReturn(99);
    $serviceMock->shouldReceive('updatePlan')->andReturn(true);
    $serviceMock->shouldReceive('adoptPlan')->andReturn(true);

    $staffMock = Mockery::mock(stdClass::class);
    $staffMock->shouldReceive('checkPermissionsAndThrowException')->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function (string $module) use ($serviceMock, $staffMock) {
        return $module === 'Staff' ? $staffMock : $serviceMock;
    });
    $api->setDi($di);
    $api->setService($serviceMock);

    expect($api->plan_get_list([]))->toBeArray();
    expect($api->plan_get(['id' => 1]))->toMatchArray(['id' => 1]);
    expect($api->plan_create(['name' => 'x']))->toBe(99);
    expect($api->plan_update(['id' => 1]))->toBeTrue();
    expect($api->plan_adopt(['id' => 1]))->toBeTrue();
});
