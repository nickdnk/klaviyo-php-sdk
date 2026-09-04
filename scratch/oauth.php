<?php
/**
 * OAuth helper for the smoke run.
 *   php scratch/oauth.php link      → prints the authorize URL (PKCE verifier + state saved to .oauth-pending.json)
 *   php scratch/oauth.php exchange  → reads the callback from the webhook sink log (or argv[2] = code), exchanges it,
 *                                     saves credentials to .oauth.json
 *   php scratch/oauth.php status    → shows stored credentials (masked) and calls GET /api/accounts with them
 *   php scratch/oauth.php refresh   → forces a refresh through APIClient::refreshCredentials()
 *   php scratch/oauth.php revoke    → revokes the refresh token and deletes .oauth.json
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\OAuthScope;
use nickdnk\Klaviyo\TokenExchange;

$env = parse_ini_file(__DIR__ . '/.env');
$pendingFile = __DIR__ . '/.oauth-pending.json';
$credFile = __DIR__ . '/.oauth.json';

$save = function (OAuthCredentials $c) use ($credFile): void {
    file_put_contents($credFile, json_encode([
        'access_token'  => $c->accessToken,
        'refresh_token' => $c->refreshToken,
        'expires_at'    => $c->expiresAt,
        'scope'         => $c->scope,
        'saved_at'      => date('c'),
    ], JSON_PRETTY_PRINT));
    fwrite(STDERR, "credentials saved (expires " . date('c', $c->expiresAt) . ")\n");
};
$refresh = function (OAuthCredentials $current, TokenExchange $exchange) use ($save): OAuthCredentials {
    $fresh = $exchange($current);
    $save($fresh);
    return $fresh;
};
$load = function () use ($credFile): OAuthCredentials {
    $j = json_decode((string)@file_get_contents($credFile), true) ?: throw new RuntimeException("no {$credFile}; run link + exchange first");
    return new OAuthCredentials($j['access_token'], $j['refresh_token'], (int)$j['expires_at'], $j['scope'] ?? null);
};
$client = fn() => APIClient::withOAuth($load(), $env['KLAVIYO_CLIENT_ID'], $env['KLAVIYO_CLIENT_SECRET'], $refresh);

switch ($argv[1] ?? '') {
    case 'link':
        $verifier = APIClient::generateCodeVerifier();
        $state = bin2hex(random_bytes(12));
        file_put_contents($pendingFile, json_encode(['verifier' => $verifier, 'state' => $state, 'created' => date('c')]));
        echo APIClient::getOAuthLink($env['KLAVIYO_CLIENT_ID'], $state, $verifier, OAuthScope::cases(), $env['OAUTH_REDIRECT_URI']), "\n";
        break;

    case 'exchange':
        $pending = json_decode((string)file_get_contents($pendingFile), true) ?: throw new RuntimeException('run link first');
        $code = $argv[2] ?? null;
        if ($code === null) {
            // find the callback in the sink log: GET /oauth/callback?code=...&state=...
            foreach (array_reverse(file(__DIR__ . '/webhooks/logs/webhooks.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
                $j = json_decode($line, true);
                if (($j['method'] ?? '') === 'GET' && str_starts_with($j['uri'] ?? '', '/oauth/callback')) {
                    parse_str((string)parse_url($j['uri'], PHP_URL_QUERY), $q);
                    if (($q['state'] ?? null) !== $pending['state']) {
                        fwrite(STDERR, "callback found but state mismatch (" . ($q['state'] ?? '-') . "), skipping\n");
                        continue;
                    }
                    $code = $q['code'] ?? null;
                    if (isset($q['error'])) {
                        throw new RuntimeException("authorization denied: {$q['error']} " . ($q['error_description'] ?? ''));
                    }
                    break;
                }
            }
        }
        $code ?: throw new RuntimeException('no callback with matching state in the sink log yet');
        $creds = APIClient::exchangeCodeForToken($env['KLAVIYO_CLIENT_ID'], $env['KLAVIYO_CLIENT_SECRET'], $code, $pending['verifier'], $env['OAUTH_REDIRECT_URI']);
        $save($creds);
        echo "scopes: ", count($creds->scopes()), " known / raw: ", $creds->scope, "\n";
        unlink($pendingFile);
        break;

    case 'status':
        $c = $load();
        echo "access ", substr($c->accessToken, 0, 8), "… refresh ", substr($c->refreshToken, 0, 8), "… expires ", date('c', $c->expiresAt), ($c->isExpired() ? ' (EXPIRED)' : ''), "\n";
        $acc = $client()->accounts->list()['data'][0];
        echo "GET /api/accounts OK: ", $acc->id, "\n";
        break;

    case 'refresh':
        $cl = $client();
        $new = $cl->refreshCredentials();
        echo "refreshed: access ", substr($new->accessToken, 0, 8), "… refresh ", substr($new->refreshToken, 0, 8), "… expires ", date('c', $new->expiresAt), "\n";
        break;

    case 'revoke':
        APIClient::revokeToken($env['KLAVIYO_CLIENT_ID'], $env['KLAVIYO_CLIENT_SECRET'], $load()->refreshToken);
        unlink($credFile);
        echo "revoked\n";
        break;

    default:
        fwrite(STDERR, "usage: link|exchange [code]|status|refresh|revoke\n");
        exit(1);
}
