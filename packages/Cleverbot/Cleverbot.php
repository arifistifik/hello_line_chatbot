<?php

namespace Packages\Cleverbot;

use GuzzleHttp\Client;

class Cleverbot
{
    const URL= 'http://www.cleverbot.com/';

    private $apiKey;

    private $client;
    
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function request($mess, $prev = [])
    {
        $client = $this->client();
        $res = $client->request('GET', '/getreply', [
            'query' => [
                'key'   => $this->apiKey,
                'input' => $mess,
                'cs'    => array_get($prev, 'cs', null)
            ]
        ]);
        return json_decode($res->getBody(), true);
    }

    public function client()
    {
        if (empty($this->client)) {
            $this->client = new Client([
                'base_uri' => static::URL,
            ]);
        }
        return $this->client;
    }
    
}
