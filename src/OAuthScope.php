<?php


namespace nickdnk\Klaviyo;

/**
 * @link https://developers.klaviyo.com/en/docs/authenticate_
 */
enum OAuthScope: string
{

    case accountsRead          = 'accounts:read';
    case campaignsRead         = 'campaigns:read';
    case campaignsWrite        = 'campaigns:write';
    case catalogsRead          = 'catalogs:read';
    case catalogsWrite         = 'catalogs:write';
    case conversationsRead     = 'conversations:read';
    case conversationsWrite    = 'conversations:write';
    case couponCodesRead       = 'coupon-codes:read';
    case couponCodesWrite      = 'coupon-codes:write';
    case couponsRead           = 'coupons:read';
    case couponsWrite          = 'coupons:write';
    case customObjectsRead     = 'custom-objects:read';
    case customObjectsWrite    = 'custom-objects:write';
    case dataPrivacyRead       = 'data-privacy:read';
    case dataPrivacyWrite      = 'data-privacy:write';
    case eventsRead            = 'events:read';
    case eventsWrite           = 'events:write';
    case flowsRead             = 'flows:read';
    case flowsWrite            = 'flows:write';
    case formsRead             = 'forms:read';
    case formsWrite            = 'forms:write';
    case imagesRead            = 'images:read';
    case imagesWrite           = 'images:write';
    case listsRead             = 'lists:read';
    case listsWrite            = 'lists:write';
    case metricsRead           = 'metrics:read';
    case metricsWrite          = 'metrics:write';
    case profilesRead          = 'profiles:read';
    case profilesWrite         = 'profiles:write';
    case pushTokensRead        = 'push-tokens:read';
    case pushTokensWrite       = 'push-tokens:write';
    case reviewsRead           = 'reviews:read';
    case reviewsWrite          = 'reviews:write';
    case segmentsRead          = 'segments:read';
    case segmentsWrite         = 'segments:write';
    case subscriptionsRead     = 'subscriptions:read';
    case subscriptionsWrite    = 'subscriptions:write';
    case tagsRead              = 'tags:read';
    case tagsWrite             = 'tags:write';
    case templatesRead         = 'templates:read';
    case templatesWrite        = 'templates:write';
    case trackingSettingsRead  = 'tracking-settings:read';
    case trackingSettingsWrite = 'tracking-settings:write';
    case webFeedsRead          = 'web-feeds:read';
    case webFeedsWrite         = 'web-feeds:write';
    case webhooksRead          = 'webhooks:read';
    case webhooksWrite         = 'webhooks:write';

}
