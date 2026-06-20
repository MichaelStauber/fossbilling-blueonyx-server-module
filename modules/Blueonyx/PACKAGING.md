# BlueOnyx FOSSBilling module packaging notes

## Package contents

The release ZIP should contain:

- `README.md`
- `CHANGELOG.md`
- `HANDOVER.md`
- `build.sh`
- `docs/`
- `SUN-modified-BSD-License.txt`
- `modules/Blueonyx/`
- `library/Server/Manager/Blueonyx.php`
- the module manifest and icon
- the BlueOnyx admin/client override templates and tests

It must not contain secrets, live customer data, or environment-specific
values.

BlueOnyx PHP source files also carry the requested BlueOnyx license header.
Templates are intentionally excluded from that notice.

The module ships its own `templates/overrides/...` sources and its
`install()` / `uninstall()` logic deploys or removes the corresponding
`html_custom` files automatically. BlueOnyx must remain module-owned and
must not rely on replacing the built-in Servicehosting server page. That
includes:

- `admin/mod_order_manage.html.twig`
- `admin/mod_servicehosting_manage.html.twig`
- `admin/partial_bb_meta.html.twig`
- `client/mod_servicehosting_manage.html.twig`
- `client/mod_orderbutton_checkout.html.twig`
- `client/mod_orderbutton_js.html.twig`

The customer-facing Let’s Encrypt action is intentionally asynchronous.
The browser request only enqueues the job; the module processes queued
requests from the normal FOSSBilling admin cron run.

## Install

Copy the module into `modules/Blueonyx/` and place the Server Manager at
`library/Server/Manager/Blueonyx.php`. The module install hook will then
copy the required BlueOnyx-specific template overrides into the active
theme's `html_custom` directories. After that, clear the FOSSBilling cache
and enable the module in the admin UI. The built-in Servicehosting pages are
left untouched.

## Upgrade

Replace only the module and manager files that belong to the package.
Preserve `ServiceHostingHp` records and existing plan config.

## Deactivation / removal

Deactivation should remove the admin navigation and routes only. Removal
should not delete hosting plans, services, or plan config values. The
module uninstall hook removes the BlueOnyx-specific `html_custom`
overrides that were deployed during install. It must not attempt to rewrite
files inside the FOSSBilling ZIP.
