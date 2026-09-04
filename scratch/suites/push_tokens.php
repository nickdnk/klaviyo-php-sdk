<?php
/**
 * PushTokenService: create (server-side push token registration with device metadata), get/list with
 * fields+include+filter, profile/profileId relationships, delete. Uses only a profile created here.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreatePushToken;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\PushToken;
use nickdnk\Klaviyo\Resources\Shared\DeviceMetadata;
use Smoke\Harness;

return function (Harness $h): void {

    $email = $h->email('push');
    $token = 'sdk-smoke-' . $h->runId . '-' . bin2hex(random_bytes(24)); // 64 hex-ish chars like an APNs token

    // Create the profile up front: push-token listings can only be filtered by profile.id, not profile.email.
    $owner = $h->step('profiles', 'create', 'create owner profile for push tokens', function (APIClient $c) use ($email) {
        $p = new \nickdnk\Klaviyo\Resources\Request\CreateProfile();
        $p->email = $email;
        $p->first_name = 'Push';
        return $c->profiles->create($p);
    });
    if ($owner) {
        $h->cleanup('profiles', 'deleteProfile', 'data-privacy deletion of push profile', fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($owner->id))));
        $h->mutation("Data-privacy deletion requested for {$email} (profile {$owner->id}).");
    }
    $ownerId = $owner?->id ?? 'NOPE01';

    $h->step('pushTokens', 'create', 'create push token (ios/apns, AUTHORIZED, background AVAILABLE, full device metadata, profile by email)', function (APIClient $c) use ($h, $email, $token) {
        $profile = new ImportProfile();
        $profile->email = $email;
        $profile->first_name = 'Push';
        $meta = new DeviceMetadata();
        $meta->device_id = $h->name('device');
        $meta->klaviyo_sdk = 'swift';
        $meta->sdk_version = '4.0.0';
        $meta->device_model = 'iPhone15,2';
        $meta->os_name = 'ios';
        $meta->os_version = '18.0';
        $meta->manufacturer = 'Apple';
        $meta->app_name = 'SmokeApp';
        $meta->app_version = '1.2.3';
        $meta->app_build = '456';
        $meta->app_id = 'com.nickdnk.smoke';
        $meta->environment = 'debug';
        return $c->pushTokens->create(new CreatePushToken($token, 'ios', 'AUTHORIZED', 'apns', $profile, 'AVAILABLE', $meta));
    }, fn($r) => $h->assert($r === null, '202 void'));

    $created = $h->step('pushTokens', 'create', 'create push token (android/fcm, PROVISIONAL, no metadata) for same profile', function (APIClient $c) use ($h, $email, $token) {
        $profile = new ImportProfile();
        $profile->email = $email;
        return $c->pushTokens->create(new CreatePushToken($token . '-android', 'android', 'PROVISIONAL', 'fcm', $profile));
    });

    $pushEnabled = $created !== null; // create returned 202 → account can process push tokens
    $mine = $h->step('pushTokens', 'list', 'list push tokens w/ filter equals(profile.id) + fields + include(profile)' . ($pushEnabled ? ' (poll until visible)' : ' (expect empty: account cannot process push tokens)'), fn(APIClient $c) => $h->waitFor(function () use ($c, $ownerId) {
        $r = $c->pushTokens->list((new Query())
            ->filter(Filter::equals('profile.id', $ownerId))
            ->fields('push-token', 'token', 'platform', 'enablement_status', 'background', 'metadata', 'created', 'recorded_date')
            ->fields('profile', 'email')
            ->include('profile')
            ->pageSize(10));
        return count($r['data']) >= 1 ? $r : null;
    }, $pushEnabled ? 120 : 1, 5, 'push tokens listed'), fn($r) => $h->assert($r['data'][0] instanceof PushToken && $r['data'][0]->platform !== null && $r['data'][0]->getRelationship('profile') !== null, 'hydrated with profile relationship'));
    if (!$pushEnabled) {
        $h->reclassify('account-limitation', 'no tokens to list: POST /api/push-tokens answered 403 "Company is not able to process push tokens"');
    }

    if (!$mine) {
        $h->note('No push tokens visible; remaining push token steps depend on it.');
    }
    $tokens = $mine['data'] ?? [];
    /** @var PushToken|null $pt */
    $pt = $tokens[0] ?? null;

    $h->step('pushTokens', 'list', 'list w/ filter equals(platform,"ios") AND equals(profile.id) + page[size]=1 (filterable: enablement_status, id, platform, profile.id)', function (APIClient $c) use ($h, $ownerId, $pushEnabled) {
        $p1 = $c->pushTokens->list((new Query())->filter(Filter::all(Filter::equals('profile.id', $ownerId), Filter::equals('platform', 'ios')))->pageSize(1));
        $h->assert(count($p1['data']) === ($pushEnabled ? 1 : 0), $pushEnabled ? 'ios token' : 'request accepted, no tokens on this account');
        return $p1;
    });
    $h->step('pushTokens', 'list', 'list w/ filter equals(enablement_status,"AUTHORIZED") + equals(profile.id)', fn(APIClient $c) => $c->pushTokens->list((new Query())->filter(Filter::all(Filter::equals('enablement_status', 'AUTHORIZED'), Filter::equals('profile.id', $ownerId)))),
        fn($r) => $h->assert(is_array($r['data']), 'request accepted'));

    if ($pt) {
        $h->step('pushTokens', 'get', 'get push token w/ fields + include(profile)', fn(APIClient $c) => $c->pushTokens->get($pt->id, (new Query())->fields('push-token', 'token', 'platform', 'device_metadata')->fields('profile', 'email', 'first_name')->include('profile')),
            fn($r) => $h->assert($r instanceof PushToken && $r->id === $pt->id && ($r->device_metadata === null || is_array($r->device_metadata) || is_object($r->device_metadata)), 'token hydrated'));
        $h->step('pushTokens', 'profile', 'profile for push token w/ fields + additional-fields', fn(APIClient $c) => $c->pushTokens->profile($pt->id, (new Query())->fields('profile', 'email')->additionalFields('profile', 'subscriptions')),
            fn($r) => $h->assert($r instanceof Profile && $r->email === $email, 'our profile'));
        $h->step('pushTokens', 'profileId', 'profile id for push token', fn(APIClient $c) => $c->pushTokens->profileId($pt->id),
            fn($r) => $h->assert($r instanceof Profile && $r->id !== null, 'id only'));
        $h->step('pushTokens', 'executePool', 'executePool: get both tokens concurrently', function (APIClient $c) use ($h, $tokens) {
            $res = $c->executePool(array_map(fn(PushToken $t) => $c->pushTokens->get($t->id, returnRequest: true), $tokens), 2);
            APIClient::assertNoExceptions($res);
            $h->assert(count($res) === count($tokens) && $res[0] instanceof PushToken, 'all hydrated');
            return $res;
        });
        foreach ($tokens as $i => $t) {
            $h->cleanup('pushTokens', 'delete', "push token {$i}", fn(APIClient $c) => $c->pushTokens->delete($t->id));
        }
    } else {
        foreach (['get', 'profile', 'profileId', 'delete'] as $m) {
            $h->skip('pushTokens', $m, "{$m} on a real token", 'no token exists: account cannot process push tokens (403 on create)');
        }
    }
    $h->step('pushTokens', 'get', 'unknown push token → null', fn(APIClient $c) => $c->pushTokens->get('NOPE01'), fn($r) => $h->assert($r === null, 'null'));
    $h->step('pushTokens', 'profile', 'profile for unknown token → null', fn(APIClient $c) => $c->pushTokens->profile('NOPE01'), fn($r) => $h->assert($r === null, 'null'));
};
