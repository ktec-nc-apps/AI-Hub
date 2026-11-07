<?php

declare(strict_types=1);

// The language of the latest message, told by its letters (2026-11-07).

require __DIR__ . '/boot.php';

use OCA\AIHub\Service\HubService;

check('English with a Japanese name in it', str_starts_with(HubService::scriptOf('How many records does the collection 取引先 have?'), 'English'), HubService::scriptOf('How many records does the collection 取引先 have?'));
check('Japanese', HubService::scriptOf('取引先のレコードは全部で何件ですか？') === 'Japanese');
check('Japanese with English words', HubService::scriptOf('RegiBase の AI-Hub 設定を教えてください') === 'Japanese', HubService::scriptOf('RegiBase の AI-Hub 設定を教えてください'));
check('Korean', HubService::scriptOf('레코드는 몇 개입니까?') === 'Korean');
check('Chinese', HubService::scriptOf('这个集合有多少条记录？') === 'Chinese');
check('Russian', str_starts_with(HubService::scriptOf('Сколько записей в коллекции?'), 'Russian'));
check('too short to tell', HubService::scriptOf('OK') === '');
finish();
