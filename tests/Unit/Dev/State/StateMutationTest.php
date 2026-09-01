<?php

declare(strict_types=1);

namespace Tests\Unit\Dev\State;

use stdClass;
use TheMattos\Leakless\Dev\State\StateMutation;

test('it formats uninitialized, null, bool, scalars, arrays, objects, and resources correctly', function () {
    $m1 = new StateMutation('App\Service', 'prop', '__UNINITIALIZED__', null);
    expect($m1->toFormattedString())->toContain('Before: <uninitialized>')
        ->and($m1->toFormattedString())->toContain('After:  null');

    $m2 = new StateMutation('App\Service', 'boolProp', true, false);
    expect($m2->toFormattedString())->toContain('Before: true')
        ->and($m2->toFormattedString())->toContain('After:  false');

    $longString = str_repeat('a', 100);
    $m3 = new StateMutation('App\Service', 'strProp', 'short', $longString);
    expect($m3->toFormattedString())->toContain("Before: 'short'")
        ->and($m3->toFormattedString())->toContain('...');

    $m4 = new StateMutation('App\Service', 'numProp', 42, 3.14);
    expect($m4->toFormattedString())->toContain('Before: 42')
        ->and($m4->toFormattedString())->toContain('After:  3.14');

    $longArray = range(1, 100);
    $m5 = new StateMutation('App\Service', 'arrProp', ['key' => 'val'], $longArray);
    expect($m5->toFormattedString())->toContain('Before: {"key":"val"}')
        ->and($m5->toFormattedString())->toContain('...');

    $nonJsonArray = ["\xB1\x31"];
    $m6 = new StateMutation('App\Service', 'invalidUtf8Arr', $nonJsonArray, $nonJsonArray);
    expect($m6->toFormattedString())->toContain('array(1 items)');

    $obj = new stdClass;
    $fp = tmpfile();
    $m7 = new StateMutation('App\Service', 'objAndResource', $obj, $fp);
    expect($m7->toFormattedString())->toContain('object(stdClass#')
        ->and($m7->toFormattedString())->toContain('resource(stream)');

    if (is_resource($fp)) {
        fclose($fp);
    }

    $m8 = new StateMutation('App\Service', 'closedResource', $fp, $fp);
    expect($m8->toFormattedString())->toBeString();
});
