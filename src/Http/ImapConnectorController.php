<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;

/**
 * Optional publishable controller for the IMAP connector's HTTP layer.
 *
 * Routes are only registered when `connectors.providers.imap.routes.enabled`
 * is true. Hosts that manage their own admin UI can leave the config off and
 * wire `initiateOAuth` / `handleOAuthCallback` themselves.
 *
 * This controller intentionally contains NO auth logic — it only delegates
 * to `ImapConnector` which owns all credential verification and storage.
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
            return redirect()->away($url);
        }

        // Parse the state out of the credential_form_url query string.
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($queryString) ? $queryString : '', $query);
        $rawState = $query['state'] ?? '';
        $state = is_string($rawState) ? $rawState : '';

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
        $connector = app(ImapConnector::class);

        $connector->handleOAuthCallback($inst->id, $request);

        return redirect()->back()->with('success', 'IMAP account connected successfully.');
    }

    /**
     * Resolve the ConnectorInstallation for the current tenant, abort 404 if missing.
     */
    private function findInstallation(int $id): ConnectorInstallation
    {
        /** @var ConnectorInstallation|null $inst */
        $inst = ConnectorInstallation::query()
            ->where('id', $id)
            ->where('connector_name', 'imap')
            ->first();

        if ($inst === null) {
            abort(404, 'IMAP connector installation not found.');
        }

        return $inst;
    }
}
