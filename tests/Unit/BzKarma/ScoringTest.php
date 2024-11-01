<?php

declare(strict_types=1);

use BzKarma\Scoring;

$path = dirname(__DIR__, 2) . '/Files/bug.json';
$data = json_decode(file_get_contents($path), true);
$obj = new Scoring($data, 130);

test('Scoring constructor', function () use ($data) {
    $obj = new Scoring($data, 130);
    expect($obj->release)
        ->toBe('130');
    expect($obj->beta)
        ->toBe('131');
    expect($obj->nightly)
        ->toBe('132');
});

test('Scoring->getBugScoreDetails()', function () use ($obj) {
    expect($obj->getBugScoreDetails(1876311))
        ->toBeArray()
        ->toHaveKeys(['priority', 'severity', 'type', 'keywords', 'duplicates', 'regressions', 'webcompat', 'perf_impact', 'cc', 'see_also', 'tracking_firefox132', 'tracking_firefox131', 'tracking_firefox130']);

    expect($obj->getBugScoreDetails(1876311)['priority'])
        ->toBe(3);
    expect(array_sum($obj->getBugScoreDetails(1)))
        ->toBe(0);
});

test('Scoring->getBugScore()', function () use ($obj) {
    expect($obj->getBugScore(1876311))
        ->toBe(4);
});

test('Scoring->getAllBugsScore()', function () use ($obj) {
    $obj->ignoreUplifts(false);
    expect($obj->getAllBugsScores(1876311))
        ->toBe([1912088 => 6, 1876311 => 4, 1876312 => 2, 1916038 => 0, 1916946 => 0, ]);
});

test('Scoring->getAllBugsScore() skip a bug', function () use ($obj) {
    $obj->karma['type']['defect'] = -100;
    expect($obj->getAllBugsScores(1876311))
        ->toBe([1876311 => 4, 1876312 => 2, 1912088 => 0, 1916038 => 0, 1916946 => 0,]);
});

test('Scoring->ignoreUplifts()', function () use ($obj) {
    expect($obj->ignoreUplifts(true))
        ->toBeNull();
});

