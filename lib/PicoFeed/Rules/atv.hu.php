<?php
return [
    'grabber' => [
        '%.*%' => [
            'test_url' => 'http://www.atv.hu/belfold/20171020-tobb-millio-forintot-csalt-ki-egy-idos-ferfitol-a-budapesti-no',
            'body' => [
                '//article'
            ],
            'strip' => [
                '//span[@class="date"]',
                '//div[@class="fb-like db_iframe_widget"]',
                '//div[@class="ad-wrapper dashed-border"]',
                '//div[@class="footer-meta-wrapper"]',
                '//div[@class="image-wrapper "]'
            ]
        ],
    ],
];
