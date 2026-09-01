<?php

declare(strict_types=1);

use TheMattos\Leakless\Attributes\ResetOnRequest;
use TheMattos\Leakless\DTOs\Config;
use TheMattos\Leakless\Leakless;
use TheMattos\Leakless\Support\StateResetter;

class DummyConventionalResetInstance
{
    /** @var array<int, string> */
    public array $items = ['initial'];

    public bool $wasReset = false;

    public function reset(): void
    {
        $this->items = [];
        $this->wasReset = true;
    }
}

class DummyConventionalResetStateInstance
{
    public bool $wasReset = false;

    public function resetState(): void
    {
        $this->wasReset = true;
    }
}

class DummyConventionalCleanupInstance
{
    public bool $wasCleaned = false;

    public function cleanup(): void
    {
        $this->wasCleaned = true;
    }
}

class DummyConventionalStaticClass
{
    public static bool $wasReset = false;

    /** @var array<int, string> */
    public static array $logs = ['boot'];

    public static function resetState(): void
    {
        self::$wasReset = true;
        self::$logs = [];
    }
}

class DummyConventionalStaticCleanupClass
{
    public static bool $cleaned = false;

    public static function cleanup(): void
    {
        self::$cleaned = true;
    }
}

class DummyAnnotatedPropertiesClass
{
    /** @var array<int, string> */
    #[ResetOnRequest]
    public static array $records = ['default1'];

    #[ResetOnRequest(default: 'custom_default')]
    public static string $name = 'initial';

    /** @var array<int, string> */
    #[ResetOnRequest(default: [])]
    public array $userCache = ['user1'];

    #[ResetOnRequest]
    public ?string $nullable = 'not_null';
}

#[ResetOnRequest(resetter: 'classResetMethod')]
class DummyClassLevelCustomResetter
{
    public static bool $called = false;

    public static function classResetMethod(): void
    {
        self::$called = true;
    }
}

#[ResetOnRequest]
class DummyClassLevelBlanketReset
{
    public static string $staticProp = 'initial';

    public string $instanceProp = 'initial';
}

class DummyMethodLevelResetter
{
    public static bool $staticMethodCalled = false;

    public bool $instanceMethodCalled = false;

    #[ResetOnRequest]
    public static function onStaticReset(): void
    {
        self::$staticMethodCalled = true;
    }

    #[ResetOnRequest]
    public function onInstanceReset(): void
    {
        $this->instanceMethodCalled = true;
    }
}

class DummyPropWithCustomResetter
{
    public static bool $customResetRan = false;

    #[ResetOnRequest(resetter: 'customPropReset')]
    public static string $prop = 'val';

    public static function customPropReset(): void
    {
        self::$customResetRan = true;
    }
}

class DummyCallableTarget
{
    public bool $called = false;

    public function handle(): void
    {
        $this->called = true;
    }
}

beforeEach(function () {
    StateResetter::clearCache();
});

test('state resetter resets callbacks and closures', function () {
    $resetter = new StateResetter;
    $state = ['dirty' => true];

    $resetter->registerTarget(function () use (&$state) {
        $state['dirty'] = false;
    });

    $resetter->resetAll();
    expect($state['dirty'])->toBeFalse();
});

test('state resetter resets objects with conventional reset, resetState, and cleanup methods', function () {
    $resetter = new StateResetter;

    $obj1 = new DummyConventionalResetInstance;
    $obj1->items = ['dirty1', 'dirty2'];

    $obj2 = new DummyConventionalResetStateInstance;
    $obj3 = new DummyConventionalCleanupInstance;

    $resetter->registerTargets([$obj1, $obj2, $obj3]);
    $resetter->resetAll();

    expect($obj1->wasReset)->toBeTrue()
        ->and($obj1->items)->toBeEmpty()
        ->and($obj2->wasReset)->toBeTrue()
        ->and($obj3->wasCleaned)->toBeTrue();
});

test('state resetter resets classes with static resetState and cleanup methods', function () {
    $resetter = new StateResetter;

    DummyConventionalStaticClass::$logs = ['dirty_log'];
    DummyConventionalStaticClass::$wasReset = false;
    DummyConventionalStaticCleanupClass::$cleaned = false;

    $resetter->registerTargets([
        DummyConventionalStaticClass::class,
        DummyConventionalStaticCleanupClass::class,
    ]);

    $resetter->resetAll();

    expect(DummyConventionalStaticClass::$wasReset)->toBeTrue()
        ->and(DummyConventionalStaticClass::$logs)->toBeEmpty()
        ->and(DummyConventionalStaticCleanupClass::$cleaned)->toBeTrue();
});

test('state resetter resets properties with ResetOnRequest attributes and custom defaults', function () {
    $resetter = new StateResetter;

    $instance = new DummyAnnotatedPropertiesClass;
    $instance->userCache = ['mutated_user'];
    $instance->nullable = 'mutated';

    DummyAnnotatedPropertiesClass::$records = ['mutated_record'];
    DummyAnnotatedPropertiesClass::$name = 'mutated_name';

    $resetter->registerTarget($instance);
    $resetter->registerTarget(DummyAnnotatedPropertiesClass::class);

    $resetter->resetAll();

    expect($instance->userCache)->toBe([])
        ->and($instance->nullable)->toBe('not_null')
        ->and(DummyAnnotatedPropertiesClass::$records)->toBe(['default1'])
        ->and(DummyAnnotatedPropertiesClass::$name)->toBe('custom_default');
});

test('state resetter handles class-level ResetOnRequest with and without custom resetters', function () {
    $resetter = new StateResetter;

    // 1. Class level with custom resetter
    DummyClassLevelCustomResetter::$called = false;
    $resetter->registerTarget(DummyClassLevelCustomResetter::class);

    // 2. Class level without custom resetter (blanket reset of all properties)
    $instance = new DummyClassLevelBlanketReset;
    $instance->instanceProp = 'dirty';
    DummyClassLevelBlanketReset::$staticProp = 'dirty';
    $resetter->registerTarget($instance);
    $resetter->registerTarget(DummyClassLevelBlanketReset::class);

    $resetter->resetAll();

    expect(DummyClassLevelCustomResetter::$called)->toBeTrue()
        ->and($instance->instanceProp)->toBe('initial')
        ->and(DummyClassLevelBlanketReset::$staticProp)->toBe('initial');
});

test('state resetter handles method-level ResetOnRequest', function () {
    $resetter = new StateResetter;

    DummyMethodLevelResetter::$staticMethodCalled = false;
    $instance = new DummyMethodLevelResetter;
    $instance->instanceMethodCalled = false;

    $resetter->registerTarget($instance);
    $resetter->registerTarget(DummyMethodLevelResetter::class);

    $resetter->resetAll();

    expect(DummyMethodLevelResetter::$staticMethodCalled)->toBeTrue()
        ->and($instance->instanceMethodCalled)->toBeTrue();
});

test('state resetter handles property-level custom resetter', function () {
    $resetter = new StateResetter;
    DummyPropWithCustomResetter::$customResetRan = false;

    $resetter->registerTarget(DummyPropWithCustomResetter::class);
    $resetter->resetAll();

    expect(DummyPropWithCustomResetter::$customResetRan)->toBeTrue();
});

test('state resetter manages clearTargets, duplicate registrations, and resetTarget immediately', function () {
    $resetter = new StateResetter;
    $obj = new DummyConventionalResetInstance;

    $resetter->registerTarget($obj);
    $resetter->registerTarget($obj); // duplicate

    $obj->items = ['dirty'];
    $resetter->resetTarget($obj); // immediate
    expect($obj->items)->toBeEmpty();

    $resetter->clearTargets();
    $obj->items = ['dirty_again'];
    $resetter->resetAll(); // should do nothing since cleared
    expect($obj->items)->toBe(['dirty_again']);
});

test('state resetter handles callable arrays', function () {
    $resetter = new StateResetter;

    $callableObj = new DummyCallableTarget;
    $resetter->registerTarget([$callableObj, 'handle']);

    $resetter->resetAll();

    expect($callableObj->called)->toBeTrue();
});

test('state resetter ignores non-existent class strings gracefully', function () {
    $resetter = new StateResetter;
    /** @var class-string<object> $invalidClass */
    $invalidClass = 'NonExistentClassString';
    $resetter->registerTarget($invalidClass);
    $resetter->resetAll();
    expect(true)->toBeTrue();
});

test('leakless integrates resettables from Config and manual registration', function () {
    $globalState = ['count' => 10];

    $obj = new DummyConventionalResetInstance;
    $obj->items = ['initial'];

    $config = new Config(
        resettables: [
            function () use (&$globalState) {
                $globalState['count'] = 0;
            },
            $obj,
        ],
    );

    $leakless = new Leakless($config);
    expect($leakless->getStateResetter())->toBeInstanceOf(StateResetter::class);

    $leakless->startRequest();

    $globalState['count'] = 999;
    $obj->items = ['mutated'];

    $report = $leakless->endRequest();

    expect($report->isClean())->toBeTrue()
        ->and($globalState['count'])->toBe(0)
        ->and($obj->items)->toBeEmpty();
});
