# BlueOnyx / FOSSBilling Handover

## Current direction

BlueOnyx is being treated as a module-owned integration surface, not as a
replacement for FOSSBilling core hosting pages.

Core principles:

- FOSSBilling stays the billing and order system.
- BlueOnyx owns the operational UI for BlueOnyx-specific tasks.
- The module now uses its own `/blueonyx/server/:id` admin screen instead of
  the old core `servicehosting/server/:id` page for BlueOnyx servers.
- No files inside the FOSSBilling ZIP should be modified.
- No auto-prepend or similar bootstrap hacks.
- Template deployment is allowed only through module install/uninstall hooks
  into `html_custom`.

## What is already in place

- `library/Server/Manager/Blueonyx.php`
- `modules/Blueonyx/`
- module-owned BlueOnyx admin server page
- current release baseline: `0.2.2`
- typed BlueOnyx plan editor
- BlueOnyx provisioning, suspend, resume, delete, change package, password
- BlueOnyx provisioning, suspend, resume, delete, change package, password
  update, and SSL request support
- customer-side BlueOnyx status and Let’s Encrypt request flow
- Let’s Encrypt requests are now queued from the customer API and processed
  during the normal FOSSBilling admin cron run; no shell spawning is used
- email autoconfiguration support in the plan editor
- packaging notes and module deployment hooks
- live validation against `rain.smd.net` / `host1.smd.net`

## What still needs attention

- Re-test the full module lifecycle after one deactivate/reactivate cycle.
- Keep the order manage template defensive around optional mod_order config values such as suspend reason lists.
- Verify `/admin/servicehosting` still routes BlueOnyx server entries to
  `/admin/blueonyx/server/:id` after reload.
- Verify the customer service page still hides the username-change control
  for BlueOnyx services.
- Re-test a plan change on an active BlueOnyx service and confirm the success
  message still appears.
- Re-run a full suspend / unsuspend / cancel / delete cycle.
- Re-test Let’s Encrypt with a Vsite whose DNS resolves correctly.
- Confirm the LE queue survives a missed cron run and is drained on the next
  cron execution.
- Re-run syntax and template validation after the next refactor.
- Keep the packaging zip self-contained and free of secrets.

## Deployment reminder

Install path:

- module: `/home/sites/hosting.blueonyx.it/wwwroot/web/modules/Blueonyx/`
- manager: `/home/sites/hosting.blueonyx.it/wwwroot/web/library/Server/Manager/Blueonyx.php`

After deployment:

- activate the module in FOSSBilling
- clear caches if needed
- verify template overrides in `html_custom`

## Rollback reminder

- deactivate the module
- remove the deployed `html_custom` overrides
- restore the manager file from backup if required
- leave billing data and hosting plans intact
