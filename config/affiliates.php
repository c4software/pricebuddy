<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Globally disable or enable all affiliate links
    |--------------------------------------------------------------------------
    */
    'enabled' => env('AFFILIATE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Sites configuration.
    |--------------------------------------------------------------------------
    */
    'sites' => [
        'amazon' => [
            'query_params' => [
                'tag' => env('AFFILIATE_CODE_AMAZON_US', 'pricebuddy07-20'),
            ],
            'domains' => [
                'amazon.com',
                'amazon.co.uk',
                'amazon.ca',
                'amazon.de',
                'amazon.fr',
                'amazon.it',
                'amazon.es',
                'amazon.com.br',
                'amazon.cn',
                'amazon.in',
                'amazon.co.jp',
                'amazon.com.mx',
                'amazon.nl',
                'amazon.pl',
                'amazon.sg',
                'amazon.se',
                'amazon.ae',
                'amazon.sa',
                'amazon.com.tr',
            ],
        ],

        'amazon_au' => [
            'query_params' => [
                'tag' => env('AFFILIATE_CODE_AMAZON_AU', 'pricebuddy-22'),
            ],
            'domains' => [
                'amazon.com.au',
            ],
        ],

        'ebay' => [
            'query_params' => [
                'mkrid' => env('AFFILIATE_CODE_EBAY_MKRID', '705-53470-19255-0'),
                'mkcid' => env('AFFILIATE_CODE_EBAY_MKCID', '1'),
                'campid' => env('AFFILIATE_CODE_EBAY_CAMPID', '5339100273'),
                'siteid' => env('AFFILIATE_CODE_EBAY_SITEID', '15'),
                'toolid' => env('AFFILIATE_CODE_EBAY_TOOLID', '10001'),
                'mkevt' => env('AFFILIATE_CODE_EBAY_MKEVT', '1'),
            ],
            'domains' => [
                'ebay.com',
                'ebay.co.uk',
                'ebay.com.au',
                'ebay.at',
                'ebay.be',
                'ebay.ca',
                'ebay.fr',
                'ebay.de',
                'ebay.ie',
                'ebay.it',
                'ebay.nl',
                'ebay.es',
                'ebay.ch',
                'ebay.com.hk',
                'ebay.in',
                'ebay.com.my',
                'ebay.ph',
                'ebay.pl',
                'ebay.com.sg',
                'ebay.se',
                'ebay.com.tw',
                'ebay.vn',
            ],
        ],
    ],
];
