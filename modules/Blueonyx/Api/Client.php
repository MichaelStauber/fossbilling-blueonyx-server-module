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

class Client extends \Api_Abstract
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
        [$order, $service, $serverManager] = $this->getHostingContext($data);
        $fqdn = $serverManager->resolveHostingFqdn($service->sld . $service->tld);
        $client = $this->getDi()['db']->getExistingModelById('Client', $order->client_id, 'Client not found');
        $email = trim((string) ($client->email ?? ''));
        if ($email === '') {
            throw new \FOSSBilling\Exception('Client email address is required to request a Let\'s Encrypt certificate.');
        }

        $status = $serverManager->requestLetsEncrypt(
            $fqdn,
            $email,
            null,
            60,
            true
        );

        return [
            'status' => $status,
            'message' => __trans('Let\'s Encrypt certificate request was submitted.'),
        ];
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
