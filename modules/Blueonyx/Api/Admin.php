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

class Admin extends \Api_Abstract
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

    #[RequiredParams(['id' => 'Plan ID was not passed'])]
    public function plan_adopt($data): bool
    {
        $this->getDi()['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        return $this->getService()->adoptPlan((int) $data['id'], $data);
    }
}
