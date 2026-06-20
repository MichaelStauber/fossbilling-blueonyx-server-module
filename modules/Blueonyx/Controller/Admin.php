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
namespace Box\Mod\Blueonyx\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'system',
                    'index' => 141,
                    'label' => __trans('BlueOnyx'),
                    'uri' => $this->di['url']->adminLink('blueonyx'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/blueonyx', 'get_index', [], static::class);
        $app->get('/blueonyx/plan/new', 'get_plan_new', [], static::class);
        $app->get('/blueonyx/plan/:id', 'get_plan', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        $plans = $this->di['mod_service']('Blueonyx')->getPlanList();

        return $app->render('mod_blueonyx_index', [
            'plans' => $plans,
            'manager_available' => $this->di['mod_service']('Blueonyx')->isManagerAvailable(),
        ]);
    }

    public function get_plan_new(\Box_App $app): string
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        $service = $this->di['mod_service']('Blueonyx');

        return $app->render('mod_blueonyx_plan', [
            'plan' => $service->getNewPlanSnapshot(),
            'sections' => $service->getFieldSections(),
            'mode' => 'create',
        ]);
    }

    public function get_plan(\Box_App $app, $id): string
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('blueonyx', 'manage');

        $service = $this->di['mod_service']('Blueonyx');
        $plan = $service->getPlanSnapshot((int) $id);

        return $app->render('mod_blueonyx_plan', [
            'plan' => $plan,
            'sections' => $service->getFieldSections(),
            'mode' => $plan['state'] === 'unmarked' ? 'adopt' : 'edit',
        ]);
    }
}
