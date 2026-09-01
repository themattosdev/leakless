<?php

declare(strict_types=1);

use TheMattos\Leakless\Attributes\AllowPersistentState;
use TheMattos\Leakless\Dev\State\ObjectStateSnapshotter;

class SimpleCleanService
{
    public string $name = 'initial';
}

class MutableService
{
    /** @var array<int, string> */
    public array $bag = [];

    public function add(string $item): void
    {
        $this->bag[] = $item;
    }
}

class ServiceWithPersistentState
{
    /** @var array<string, mixed> */
    #[AllowPersistentState]
    public array $allowedCache = [];

    public string $forbiddenState = 'clean';
}

#[AllowPersistentState]
class CompletelyAllowedService
{
    /** @var array<string, mixed> */
    public array $cache = [];
}

class UninitializedTypedService
{
    public string $uninit;

    public string $init = 'ready';
}

class CircularParent
{
    public ?CircularChild $child = null;
}

class CircularChild
{
    public ?CircularParent $parent = null;
}

test('it extracts object instances from direct object and array of objects', function () {
    $snapshotter = new ObjectStateSnapshotter;

    $s1 = new SimpleCleanService;
    $s2 = new MutableService;

    expect($snapshotter->extractInstances($s1))->toHaveCount(1)
        ->and($snapshotter->extractInstances([$s1, 'invalid_string', 123, $s2]))->toHaveCount(2);
});

test('it detects clean state when properties are not mutated', function () {
    $snapshotter = new ObjectStateSnapshotter;
    $service = new SimpleCleanService;
    $instances = [$service];

    $before = $snapshotter->snapshot($instances);
    // Do nothing or read
    $after = $snapshotter->snapshot($instances);

    $mutations = $snapshotter->compare($instances, $before, $after);
    expect($mutations)->toBeEmpty();
});

test('it detects property mutations in object instances', function () {
    $snapshotter = new ObjectStateSnapshotter;
    $service = new MutableService;
    $instances = [$service];

    $before = $snapshotter->snapshot($instances);
    $service->add('item-1');
    $after = $snapshotter->snapshot($instances);

    $mutations = $snapshotter->compare($instances, $before, $after);

    expect($mutations)->toHaveCount(1)
        ->and($mutations[0]->className)->toBe(MutableService::class)
        ->and($mutations[0]->propertyName)->toBe('bag')
        ->and($mutations[0]->beforeValue)->toBe([])
        ->and($mutations[0]->afterValue)->toBe(['item-1'])
        ->and($mutations[0]->toFormattedString())->toContain('MutableService::$bag');
});

test('it respects AllowPersistentState on property and class levels', function () {
    $snapshotter = new ObjectStateSnapshotter;

    $serviceWithProp = new ServiceWithPersistentState;
    $completelyAllowed = new CompletelyAllowedService;
    $instances = [$serviceWithProp, $completelyAllowed];

    $before = $snapshotter->snapshot($instances);

    // Mutate allowed properties
    $serviceWithProp->allowedCache['key'] = 'val';
    $completelyAllowed->cache['key'] = 'val';

    $after = $snapshotter->snapshot($instances);
    $mutations = $snapshotter->compare($instances, $before, $after);

    expect($mutations)->toBeEmpty();

    // Now mutate non-allowed property
    $serviceWithProp->forbiddenState = 'polluted';
    $after2 = $snapshotter->snapshot($instances);
    $mutations2 = $snapshotter->compare($instances, $before, $after2);

    expect($mutations2)->toHaveCount(1)
        ->and($mutations2[0]->propertyName)->toBe('forbiddenState');
});

test('it safely handles uninitialized typed properties', function () {
    $snapshotter = new ObjectStateSnapshotter;
    $service = new UninitializedTypedService;
    $instances = [$service];

    $before = $snapshotter->snapshot($instances);
    // Initialize property during cycle
    $service->uninit = 'now_initialized';
    $after = $snapshotter->snapshot($instances);

    $mutations = $snapshotter->compare($instances, $before, $after);
    expect($mutations)->toHaveCount(1)
        ->and($mutations[0]->propertyName)->toBe('uninit')
        ->and($mutations[0]->beforeValue)->toBe('__UNINITIALIZED__')
        ->and($mutations[0]->afterValue)->toBe('now_initialized');
});

test('it handles circular references without infinite recursion', function () {
    $snapshotter = new ObjectStateSnapshotter;

    $parent = new CircularParent;
    $child = new CircularChild;
    $parent->child = $child;
    $child->parent = $parent;

    $instances = [$parent, $child];

    $before = $snapshotter->snapshot($instances);
    $after = $snapshotter->snapshot($instances);

    $mutations = $snapshotter->compare($instances, $before, $after);
    expect($mutations)->toBeEmpty();
});

test('it respects custom maxDepth parameter during object inspection', function () {
    $snapshotter = new ObjectStateSnapshotter;

    $level3 = new MutableService;
    $level2 = new class($level3)
    {
        public function __construct(public MutableService $nested) {}
    };
    $level1 = new class($level2)
    {
        public function __construct(public object $nested) {}
    };

    $instances = [$level1];

    // With maxDepth 1, level 3 is not traversed deeply
    $beforeShallow = $snapshotter->snapshot($instances, maxDepth: 1);
    $level3->add('nested_mutation');
    $afterShallow = $snapshotter->snapshot($instances, maxDepth: 1);
    $mutationsShallow = $snapshotter->compare($instances, $beforeShallow, $afterShallow);
    expect($mutationsShallow)->toBeEmpty();

    // With maxDepth 3, level 3 is traversed and mutation is detected
    $beforeDeep = $snapshotter->snapshot($instances, maxDepth: 3);
    $level3->add('another_mutation');
    $afterDeep = $snapshotter->snapshot($instances, maxDepth: 3);
    $mutationsDeep = $snapshotter->compare($instances, $beforeDeep, $afterDeep);
    expect($mutationsDeep)->not->toBeEmpty();
});

test('it extracts instances from generic psr-11 container', function () {
    $container = new class implements \Psr\Container\ContainerInterface
    {
        public array $services = [];

        public array $singletons = [];

        public function get(string $id): mixed
        {
            return $this->services[$id] ?? null;
        }

        public function has(string $id): bool
        {
            return isset($this->services[$id]);
        }
    };

    $s1 = new SimpleCleanService;
    $s2 = new MutableService;
    $container->services = [$s1];
    $container->singletons = [$s2];

    $snapshotter = new ObjectStateSnapshotter;
    expect($snapshotter->extractInstances($container))->toHaveCount(2)
        ->and($snapshotter->extractInstances('non_object'))->toBeEmpty();
});

test('it serializes closures, nested containers, and resources safely', function () {
    $container = new class implements \Psr\Container\ContainerInterface
    {
        public function get(string $id): mixed
        {
            return null;
        }

        public function has(string $id): bool
        {
            return false;
        }
    };

    $fp = tmpfile();

    $complexService = new class($container, $fp)
    {
        public Closure $callback;

        public function __construct(
            public \Psr\Container\ContainerInterface $container,
            public mixed $resource,
        ) {
            $this->callback = fn () => true;
        }
    };

    $snapshotter = new ObjectStateSnapshotter;
    $instances = [$complexService];

    $before = $snapshotter->snapshot($instances);
    expect($before)->not->toBeEmpty();

    if (is_resource($fp)) {
        fclose($fp);
    }
});

