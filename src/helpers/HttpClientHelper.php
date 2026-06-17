<?php

namespace wideweb\aiseoaudit\helpers;

use GuzzleHttp\Client;

class HttpClientHelper
{
    public static function create(array $config = []): Client
    {
        return new Client($config);
    }
}
