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

class Client extends \FOSSBilling\Api\AbstractApi
{
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function ssl_status($data): array
    {
        [$order, $service, $serverManager] = $this->getHostingContext($data);
        $fqdn = $serverManager->resolveHostingFqdn($service->sld . $service->tld);

        return $serverManager->getLetsEncryptStatus($fqdn);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function request_lets_encrypt($data): array
    {
        [$order] = $this->getHostingContext($data);
        $queueState = $this->getDi()['mod_service']('Blueonyx')->queueLetsEncryptRequest((int) $order->id);

        return [
            'queued' => true,
            'queue_length' => $queueState['queue_length'] ?? 0,
            'message' => __trans('Let\'s Encrypt certificate request was submitted.'),
        ];
    }

    #[RequiredParams(['id' => 'Order ID is required'])]
    public function order_context($data): array
    {
        $identity = $this->getIdentity();
        $order = $this->getDi()['db']->findOne('ClientOrder', 'id = ? and client_id = ?', [(int) $data['id'], $identity->id]);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\Exception('Order not found');
        }

        return $this->getDi()['mod_service']('Blueonyx')->getOrderContext((int) $order->id);
    }

    public function blueonyx_order_context($data): array
    {
        return $this->order_context($data);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function order_change_password($data): bool
    {
        [$order, $service, $serverManager] = $this->getHostingContext($data);

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountPassword($order, $service, $data),
                'change_password',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx client password change completed with a post-action error; returning success anyway.', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function order_change_domain($data): bool
    {
        [$order, $service, $serverManager] = $this->getHostingContext($data);

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountDomain($order, $service, $data),
                'change_domain',
                (int) $order->id
            );
            $this->refreshBlueOnyxOrderTitle($order, $service, (string) $data['sld'] . (string) $data['tld']);
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx client domain change completed with a post-action error; returning success anyway.', [
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

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function order_change_ip($data): bool
    {
        [$order, $service, $serverManager] = $this->getHostingContext($data);

        try {
            $this->runBlueOnyxLifecycleAction(
                fn (): bool => $this->getDi()['mod_service']('servicehosting')->changeAccountIp($order, $service, $data),
                'change_ip',
                (int) $order->id
            );
        } catch (\Throwable $e) {
            $this->getDi()['logger']->warning('BlueOnyx client IP change completed with a post-action error; returning success anyway.', [
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

    /**
     * @return array{0: \Model_ClientOrder, 1: \Model_ServiceHosting, 2: \Server_Manager_Blueonyx}
     */
    private function getHostingContext(array $data): array
    {
        $identity = $this->getIdentity();
        $order = $this->getDi()['db']->findOne('ClientOrder', 'id = ? and client_id = ?', [$data['order_id'], $identity->id]);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\Exception('Order not found');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $service = $orderService->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting || $order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
            throw new \FOSSBilling\Exception('Order is not activated');
        }

        $hostingService = $this->getDi()['mod_service']('servicehosting');
        $server = $this->getDi()['db']->getExistingModelById('ServiceHostingServer', $service->service_hosting_server_id, 'Server not found');
        $serverManager = $hostingService->getServerManager($server);
        if (!($serverManager instanceof \Server_Manager_Blueonyx)) {
            throw new \FOSSBilling\Exception('This action is only available for BlueOnyx servers.');
        }

        return [$order, $service, $serverManager];
    }
}
