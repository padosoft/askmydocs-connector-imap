<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;

/**
 * Optional publishable controller for the IMAP connector's HTTP layer.
 *
 * Routes are only registered when `connectors.providers.imap.routes.enabled`
 * is true. Hosts that manage their own admin UI can leave the config off and
 * wire `initiateOAuth` / `handleOAuthCallback` themselves.
 *
 * This controller delegates credential handling to `ImapConnector`.
 * Authentication and authorization are enforced by the route middleware
 * configured in `connectors.providers.imap.routes.middleware`. Tenant
 * scoping is applied by `findInstallation()` using the active TenantContext,
 * and OAuth state is bound to the user session to prevent CSRF / replay.
 *
 * Publish: php artisan vendor:publish --tag=connector-imap-http
 */
class ImapConnectorController extends Controller
{
    /**
     * Show the credential form (basic-auth) or redirect to the OAuth provider (xoauth2).
     */
    public function form(Request $request, int $installation): Response|RedirectResponse
    {
        $inst = $this->findInstallation($installation);
        $connector = app(ImapConnector::class);

        // initiateOAuth issues a state and returns either:
        //   • a credential form URL (basic)  →  we render the Blade form
        //   • an OAuth provider URL (xoauth2) → we redirect away
        $url = $connector->initiateOAuth($inst->id);

        $config = (array) ($inst->config_json ?? []);
        $authMode = (string) ($config['auth_mode'] ?? 'basic');

        if ($authMode === 'xoauth2') {
            // For xoauth2, extract the state from the authorize URL before redirecting.
            $xoauthQueryString = parse_url($url, PHP_URL_QUERY);
            parse_str(is_string($xoauthQueryString) ? $xoauthQueryString : '', $xoauthQuery);
            $xoauthRawState = $xoauthQuery['state'] ?? '';
            $xoauthState = is_string($xoauthRawState) ? $xoauthRawState : '';

            // Bind the state to the authenticated user's session (replay protection).
            $request->session()->put("imap_oauth_state.{$inst->id}", $xoauthState);

            return redirect()->away($url);
        }

        // Parse the state out of the credential_form_url query string.
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($queryString) ? $queryString : '', $query);
        $rawState = $query['state'] ?? '';
        $state = is_string($rawState) ? $rawState : '';

        // Bind the state to the authenticated user's session (replay / CSRF protection).
        $request->session()->put("imap_oauth_state.{$inst->id}", $state);

        return response()->view('connector-imap::credentials', [
            'installation' => $inst,
            'state' => $state,
            'storeRoute' => route('connector-imap.credentials.store', ['installation' => $inst->id]),
        ]);
    }

    /**
     * Store basic-auth credentials submitted from the credential form.
     */
    public function store(Request $request, int $installation): RedirectResponse
    {
        $inst = $this->findInstallation($installation);

        // Defense-in-depth: verify the OAuth state is bound to this user's session.
        // pull() removes it atomically — single-use.
        $expected = $request->session()->pull("imap_oauth_state.{$inst->id}");
        if ($expected === null || ! hash_equals((string) $expected, (string) $request->input('state'))) {
            abort(403, 'Invalid or expired OAuth state.');
        }

        $connector = app(ImapConnector::class);

        // The connector validates the state, pings the server, and persists credentials.
        // It will throw ConnectorAuthException on failure — let it bubble as a 422/500.
        $connector->handleOAuthCallback($inst->id, $request);

        return redirect()->back()->with('success', 'IMAP credentials saved successfully.');
    }

    /**
     * Handle the OAuth provider redirect back (xoauth2 flow).
     */
    public function callback(Request $request, int $installation): RedirectResponse
    {
        $inst = $this->findInstallation($installation);

        // Defense-in-depth: verify the OAuth state matches what was stored at form/redirect time.
        // pull() removes it atomically — single-use.
        $expected = $request->session()->pull("imap_oauth_state.{$inst->id}");
        if ($expected === null || ! hash_equals((string) $expected, (string) $request->input('state'))) {
            abort(403, 'Invalid or expired OAuth state.');
        }

        $connector = app(ImapConnector::class);

        $connector->handleOAuthCallback($inst->id, $request);

        return redirect()->back()->with('success', 'IMAP account connected successfully.');
    }

    /**
     * Resolve the ConnectorInstallation for the current tenant, abort 404 if missing.
     *
     * Scopes by tenant_id (IDOR prevention) in addition to id + connector_name.
     * A valid installation belonging to a different tenant returns 404 — not a 403 —
     * to avoid leaking whether the installation id exists at all.
     */
    private function findInstallation(int $id): ConnectorInstallation
    {
        $tenantId = app(TenantContext::class)->current();

        /** @var ConnectorInstallation|null $inst */
        $inst = ConnectorInstallation::query()
            ->where('id', $id)
            ->where('connector_name', 'imap')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($inst === null) {
            abort(404, 'IMAP connector installation not found.');
        }

        return $inst;
    }
}
