<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Authenticators;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Data\EncryptedField;
use Pimcore\Model\DataObject\TorqStoreExporterShopifyCredentials;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;

class ShopifyAuthenticator extends AbstractAuthenticator
{
    protected $Host;
    protected $APIAccessToken;
    protected $APIKey;
    protected $APISecret;

    public function connect(): array
    {
        $host = $this->Host;
        Context::initialize(
            $this->APIKey->getPlain(),
            $this->APISecret->getPlain(),
            ["read_products", "write_products"],
            $host,
            new FileSessionStorage('/tmp/php_sessions'),
            apiVersion: '2025-04',
        );
        $offlineSession = new Session("offline_$host", $host, false, 'state');
        $offlineSession->setScope(Context::$SCOPES->toString());
        $offlineSession->setAccessToken($this->APIAccessToken->getPlain());
        $session = $offlineSession;
        $client = new Graphql($session->getShop(), $session->getAccessToken());
        return [
            'host' => $this->Host,
            'secret' => $this->APISecret->getPlain(),
            'key' => $this->APIKey->getPlain(),
            'token' => $this->APIAccessToken->getPlain(),
            'session' => $session,
            'client' => $client
        ];
    }
}
