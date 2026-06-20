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
 */
namespace Box\Mod\Blueonyx\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    public function plan_get_list($data): array
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->getPlanList();
    }

    #[RequiredParams(['id' => 'Plan ID was not passed'])]
    public function plan_get($data): array
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->getPlanSnapshot((int) $data['id']);
    }

    public function plan_create($data): int
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->createPlan($data);
    }

    #[RequiredParams(['id' => 'Plan ID was not passed'])]
    public function plan_update($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->updatePlan((int) $data['id'], $data);
    }

    #[RequiredParams(['id' => 'Server ID was not passed'])]
    public function server_test_connection($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('servicehosting', 'manage_servers');

        return $this->getService()->testServicehostingConnection((int) $data['id']);
    }

    #[RequiredParams(['id' => 'Plan ID was not passed'])]
    public function plan_adopt($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->adoptPlan((int) $data['id'], $data);
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_context($data): array
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'view');

        return $this->getService()->getOrderContext((int) $data['id']);
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_renew($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $period = (string) ($order->period ?? '');
        if ($period !== '') {
            $fromTime = ($order->expires_at === null) ? time() : strtotime((string) $order->expires_at);
            $logic = (string) ($this->getDi()['mod_config']('order')['order_renewal_logic'] ?? '');

            if ($logic === 'from_today') {
                $fromTime = time();
            } elseif ($logic === 'from_greater') {
                $fromTime = strtotime((string) $order->expires_at) > time() ? strtotime((string) $order->expires_at) : time();
            }

            $expiration = $this->getDi()['period']($period)->getExpirationTime((int) $fromTime);
            $order->expires_at = date('Y-m-d H:i:s', $expiration);
        }

        $order->status = \Model_ClientOrder::STATUS_ACTIVE;
        $order->suspended_at = null;
        $order->unsuspended_at = null;
        $order->canceled_at = null;
        $order->updated_at = date('Y-m-d H:i:s');
        $this->getDi()['db']->store($order);

        $this->getDi()['mod_service']('order')->saveStatusChange($order, 'BlueOnyx order renewed');

        return true;
    }

    #[RequiredParams(['order_id' => 'Order ID was not passed', 'plan_id' => 'Plan ID was not passed'])]
    public function order_change_plan($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $plan = $this->getDi()['db']->getExistingModelById('ServiceHostingHp', (int) $data['plan_id'], 'Hosting plan not found');
        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\Exception('Order has no hosting service attached.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountPlan($order, $service, $plan),
                'change_plan',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx change plan completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    #[RequiredParams(['order_id' => 'Order ID was not passed'])]
    public function order_change_password($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\Exception('Order has no hosting service attached.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountPassword($order, $service, $data),
                'change_password',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx change password completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    #[RequiredParams(['order_id' => 'Order ID was not passed'])]
    public function order_change_domain($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\Exception('Order has no hosting service attached.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountDomain($order, $service, $data),
                'change_domain',
                (int) $order->id
            );
            $this->refreshBlueOnyxOrderTitle($order, $service, (string) $data['sld'] . (string) $data['tld']);
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx change domain completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function refreshBlueOnyxOrderTitle(\Model_ClientOrder $order, \Model_ServiceHosting $service, string $domain): void
    {
        $domain = trim($domain);
        if ($domain === '') {
            return;
        }

        $currentTitle = trim((string) $order->title);
        $baseTitle = $currentTitle;
        $pos = strrpos($currentTitle, ' for ');
        if ($pos !== false) {
            $baseTitle = trim(substr($currentTitle, 0, $pos));
        }

        if ($baseTitle === '') {
            $plan = $this->getDi()['db']->load('ServiceHostingHp', $service->service_hosting_hp_id);
            $baseTitle = $plan instanceof \Model_ServiceHostingHp ? (string) ($plan->name ?? 'BlueOnyx Vsite') : 'BlueOnyx Vsite';
        }

        $newTitle = sprintf('%s for %s', $baseTitle, $domain);
        if ($newTitle === $currentTitle) {
            return;
        }

        $order->title = $newTitle;
        $order->updated_at = date('Y-m-d H:i:s');
        $this->getDi()['db']->store($order);
    }

    #[RequiredParams(['order_id' => 'Order ID was not passed'])]
    public function order_change_ip($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\Exception('Order has no hosting service attached.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountIp($order, $service, $data),
                'change_ip',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx change IP completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    #[RequiredParams(['order_id' => 'Order ID was not passed'])]
    public function order_sync($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\Exception('Order has no hosting service attached.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->sync($order, $service),
                'sync',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx sync completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function runBlueOnyxLifecycleAction(callable $action, string $actionName, int $orderId): void
    {
        $attempts = 2;
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $action();
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->getDi()['logger']->warning('BlueOnyx lifecycle action failed; retrying if possible.', [
                    'order_id' => $orderId,
                    'action' => $actionName,
                    'attempt' => $i,
                    'exception' => $e->getMessage(),
                ]);

                if ($i < $attempts) {
                    usleep(250000);
                }
            }
        }

        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_suspend($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->action_suspend($order),
                'suspend',
                (int) $order->id
            );

            $order->status = \Model_ClientOrder::STATUS_SUSPENDED;
            $order->suspended_at = date('Y-m-d H:i:s');
            $order->unsuspended_at = null;
            $order->updated_at = date('Y-m-d H:i:s');
            $this->getDi()['db']->store($order);
            $note = $reason === '' ? 'BlueOnyx order suspended' : 'BlueOnyx order suspended: ' . $reason;
            $this->getDi()['mod_service']('order')->saveStatusChange($order, $note);
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx suspend completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);

            try {
                $order->status = \Model_ClientOrder::STATUS_SUSPENDED;
                $order->suspended_at = date('Y-m-d H:i:s');
                $order->unsuspended_at = null;
                $order->updated_at = date('Y-m-d H:i:s');
                $this->getDi()['db']->store($order);
            } catch (\Throwable) {
                // best effort only
            }
        }

        return true;
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_unsuspend($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->action_unsuspend($order),
                'unsuspend',
                (int) $order->id
            );

            $order->status = \Model_ClientOrder::STATUS_ACTIVE;
            $order->unsuspended_at = date('Y-m-d H:i:s');
            $order->suspended_at = null;
            $order->updated_at = date('Y-m-d H:i:s');
            $this->getDi()['db']->store($order);
            $this->getDi()['mod_service']('order')->saveStatusChange($order, 'BlueOnyx order unsuspended');
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx unsuspend completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);

            try {
                $order->status = \Model_ClientOrder::STATUS_ACTIVE;
                $order->unsuspended_at = date('Y-m-d H:i:s');
                $order->suspended_at = null;
                $order->updated_at = date('Y-m-d H:i:s');
                $this->getDi()['db']->store($order);
            } catch (\Throwable) {
                // best effort only
            }
        }

        return true;
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_cancel($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $this->getDi()['mod_service']('servicehosting')->action_cancel($order);
        $order->status = \Model_ClientOrder::STATUS_CANCELED;
        $order->canceled_at = date('Y-m-d H:i:s');
        $order->updated_at = date('Y-m-d H:i:s');
        $this->getDi()['db']->store($order);
        $this->getDi()['mod_service']('order')->saveStatusChange($order, 'BlueOnyx order canceled');

        return true;
    }

    #[RequiredParams(['id' => 'Order ID was not passed'])]
    public function order_delete($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('order', 'manage');

        $order = $this->getDi()['db']->getExistingModelById('ClientOrder', (int) $data['id'], 'Order not found');
        $context = $this->getService()->getOrderContext((int) $order->id);
        if (empty($context['is_blueonyx'])) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx orders.');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $productService = $this->getDi()['mod_service']('Product');

        if ($order->status !== \Model_ClientOrder::STATUS_CANCELED) {
            $this->getDi()['mod_service']('servicehosting')->action_cancel($order);
            $order->status = \Model_ClientOrder::STATUS_CANCELED;
            $order->canceled_at = date('Y-m-d H:i:s');
            $order->updated_at = date('Y-m-d H:i:s');
            $this->getDi()['db']->store($order);
        }

        $productService->releaseReservedPromoRedemptionsForOrder($order, 'order_deleted');
        $orderService->rmClientOrderStatusByOrder($order);
        $orderService->rmOrder($order);

        return true;
    }
}
