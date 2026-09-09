<?php
declare(strict_types=1);

/**
 * 10-Minute Quick Commerce Delivery & Rider Management — Standalone Simulation
 * ------------------------------------------------------------------------
 * Pure PHP 8.2+, zero external dependencies, zero database. Everything is
 * held in in-memory repositories (plain PHP arrays wrapped behind small
 * repository classes) so this file can be run directly:
 *
 *     php quickcommerce_demo.php
 *
 * It seeds 2 dark stores, 5 riders and 1 customer order, then walks the
 * order through the full "Placed -> Packing -> Rider_Assigned ->
 * Out_For_Delivery -> Delivered" state machine, printing a step-by-step
 * log to the console exactly like a real dispatch engine would.
 */

namespace QuickCommerce;

// =========================================================================
// ENUMS
// =========================================================================

enum OrderStatus: string
{
    case Placed         = 'Placed';
    case Packing        = 'Packing';
    case RiderAssigned  = 'Rider_Assigned';
    case OutForDelivery = 'Out_For_Delivery';
    case Delivered      = 'Delivered';

    /**
     * The single legal "next" status for each current status. This is the
     * entire state machine definition — one linear happy-path pipeline,
     * exactly as specified.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Placed         => self::Packing,
            self::Packing        => self::RiderAssigned,
            self::RiderAssigned  => self::OutForDelivery,
            self::OutForDelivery => self::Delivered,
            self::Delivered      => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Placed         => 'Order placed by customer',
            self::Packing        => 'Dark store is packing the order',
            self::RiderAssigned  => 'Rider assigned and heading to store',
            self::OutForDelivery => 'Rider picked up the order, en route to customer',
            self::Delivered      => 'Order delivered to customer',
        };
    }
}

enum RiderStatus: string
{
    case Available = 'Available';
    case Busy      = 'Busy';
    case Offline   = 'Offline';
}

// =========================================================================
// VALUE OBJECTS / DTOs
// =========================================================================

/**
 * Immutable lat/lng pair with a built-in Haversine great-circle distance
 * calculator. This is the single source of truth for every distance
 * computation in the system (store lookup, rider allocation, ETA).
 */
final class Coordinates
{
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {
    }

    /**
     * Great-circle distance to another coordinate, in kilometers, using the
     * Haversine formula.
     */
    public function distanceKmTo(Coordinates $other): float
    {
        $lat1 = deg2rad($this->lat);
        $lat2 = deg2rad($other->lat);
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    public function __toString(): string
    {
        return sprintf('(%.4f, %.4f)', $this->lat, $this->lng);
    }
}

final class DarkStore
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Coordinates $location,
        /** Maximum radius (km) this store is willing to deliver to. */
        public readonly float $serviceRadiusKm = 5.0,
    ) {
    }
}

/**
 * A delivery rider. Location and status are mutable — a rider moves and
 * changes availability as orders come in, so unlike Coordinates/DarkStore
 * this is not a readonly value object.
 */
final class Rider
{
    private Coordinates $location;
    private RiderStatus $status;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        Coordinates $location,
        RiderStatus $status,
        /** The Dark Store hub this rider is attached to / stationed at. */
        public readonly string $darkStoreId,
    ) {
        $this->location = $location;
        $this->status   = $status;
    }

    public function location(): Coordinates
    {
        return $this->location;
    }

    public function status(): RiderStatus
    {
        return $this->status;
    }

    public function isAvailable(): bool
    {
        return $this->status === RiderStatus::Available;
    }

    public function markBusy(): void
    {
        $this->status = RiderStatus::Busy;
    }

    public function markAvailable(): void
    {
        $this->status = RiderStatus::Available;
    }

    public function moveTo(Coordinates $location): void
    {
        $this->location = $location;
    }
}

final class OrderItem
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {
    }

    public function subtotal(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }
}

/**
 * The order aggregate. Its status may only move forward through
 * OrderStatus::next() via OrderStateMachine::advance() — nothing else in
 * the codebase is allowed to mutate $status directly.
 */
final class Order
{
    /** @var OrderItem[] */
    private array $items;
    private OrderStatus $status;
    private ?string $assignedDarkStoreId = null;
    private ?string $assignedRiderId = null;
    private ?float $etaMinutes = null;
    /** @var array<int, array{status: string, at: string}> */
    private array $history = [];

    public function __construct(
        public readonly string $id,
        public readonly string $customerName,
        public readonly Coordinates $deliveryLocation,
        array $items,
    ) {
        $this->items  = $items;
        $this->status = OrderStatus::Placed;
        $this->recordHistory(OrderStatus::Placed);
    }

    /** @return OrderItem[] */
    public function items(): array
    {
        return $this->items;
    }

    public function subtotal(): float
    {
        return round(array_sum(array_map(
            static fn (OrderItem $item) => $item->subtotal(),
            $this->items
        )), 2);
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function assignedDarkStoreId(): ?string
    {
        return $this->assignedDarkStoreId;
    }

    public function assignedRiderId(): ?string
    {
        return $this->assignedRiderId;
    }

    public function etaMinutes(): ?float
    {
        return $this->etaMinutes;
    }

    /** @return array<int, array{status: string, at: string}> */
    public function history(): array
    {
        return $this->history;
    }

    public function assignDarkStore(DarkStore $store): void
    {
        $this->assignedDarkStoreId = $store->id;
    }

    public function assignRider(Rider $rider): void
    {
        $this->assignedRiderId = $rider->id;
    }

    public function setEtaMinutes(float $minutes): void
    {
        $this->etaMinutes = $minutes;
    }

    /**
     * Force-advances the order to the given status. Only ever called by
     * OrderStateMachine, which is responsible for validating the
     * transition is legal before calling this.
     */
    public function forceStatus(OrderStatus $status): void
    {
        $this->status = $status;
        $this->recordHistory($status);
    }

    private function recordHistory(OrderStatus $status): void
    {
        $this->history[] = [
            'status' => $status->value,
            'at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }
}

// =========================================================================
// EXCEPTIONS
// =========================================================================

final class NoDarkStoreInRangeException extends \RuntimeException
{
}

final class NoAvailableRiderException extends \RuntimeException
{
}

final class InvalidStateTransitionException extends \RuntimeException
{
}

// =========================================================================
// IN-MEMORY REPOSITORIES
// =========================================================================

final class DarkStoreRepository
{
    /** @var array<string, DarkStore> */
    private array $stores = [];

    public function add(DarkStore $store): void
    {
        $this->stores[$store->id] = $store;
    }

    public function find(string $id): ?DarkStore
    {
        return $this->stores[$id] ?? null;
    }

    /** @return DarkStore[] */
    public function all(): array
    {
        return array_values($this->stores);
    }
}

final class RiderRepository
{
    /** @var array<string, Rider> */
    private array $riders = [];

    public function add(Rider $rider): void
    {
        $this->riders[$rider->id] = $rider;
    }

    public function find(string $id): ?Rider
    {
        return $this->riders[$id] ?? null;
    }

    /** @return Rider[] */
    public function all(): array
    {
        return array_values($this->riders);
    }

    /** @return Rider[] All riders stationed at a given dark store hub. */
    public function findByDarkStore(string $darkStoreId): array
    {
        return array_values(array_filter(
            $this->riders,
            static fn (Rider $rider) => $rider->darkStoreId === $darkStoreId
        ));
    }

    /** @return Rider[] Available riders stationed at a given dark store hub. */
    public function findAvailableByDarkStore(string $darkStoreId): array
    {
        return array_values(array_filter(
            $this->findByDarkStore($darkStoreId),
            static fn (Rider $rider) => $rider->isAvailable()
        ));
    }
}

final class OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    public function add(Order $order): void
    {
        $this->orders[$order->id] = $order;
    }

    public function find(string $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    /** @return Order[] */
    public function all(): array
    {
        return array_values($this->orders);
    }
}

// =========================================================================
// SERVICES
// =========================================================================

/**
 * Dark Store Geofencing: finds the closest dark store to a customer's
 * coordinates that is still within that store's own service radius.
 */
final class DarkStoreLocator
{
    public function __construct(private readonly DarkStoreRepository $stores)
    {
    }

    /**
     * @throws NoDarkStoreInRangeException if no store can service this location.
     */
    public function findNearestServiceableStore(Coordinates $customerLocation): DarkStore
    {
        $nearest      = null;
        $nearestDistKm = PHP_FLOAT_MAX;

        foreach ($this->stores->all() as $store) {
            $distanceKm = $store->location->distanceKmTo($customerLocation);

            if ($distanceKm <= $store->serviceRadiusKm && $distanceKm < $nearestDistKm) {
                $nearest       = $store;
                $nearestDistKm = $distanceKm;
            }
        }

        if ($nearest === null) {
            throw new NoDarkStoreInRangeException(
                "No dark store within service radius of customer at {$customerLocation}."
            );
        }

        return $nearest;
    }
}

/**
 * Smart Rider Allocation: picks the nearest available rider that belongs
 * to the given dark store's own hub (riders idle near their assigned
 * store between deliveries, so distance is measured store -> rider).
 */
final class RiderAllocator
{
    public function __construct(private readonly RiderRepository $riders)
    {
    }

    /**
     * @throws NoAvailableRiderException if every rider at this hub is busy/offline.
     */
    public function allocateNearestRider(DarkStore $store): Rider
    {
        $candidates = $this->riders->findAvailableByDarkStore($store->id);

        if (empty($candidates)) {
            throw new NoAvailableRiderException(
                "No available riders at Dark Store '{$store->name}' (id={$store->id})."
            );
        }

        usort(
            $candidates,
            static fn (Rider $a, Rider $b) => $a->location()->distanceKmTo($store->location)
                <=> $b->location()->distanceKmTo($store->location)
        );

        return $candidates[0];
    }
}

/**
 * 10-Minute ETA Calculator: Store Packing time (fixed 2-3 min band) +
 * Transit time (store -> customer distance at an average urban speed).
 */
final class EtaCalculator
{
    private const MIN_PACKING_MINUTES = 2.0;
    private const MAX_PACKING_MINUTES = 3.0;
    private const AVERAGE_SPEED_KMPH  = 25.0;

    /**
     * @return array{packing_minutes: float, transit_minutes: float, total_minutes: float, distance_km: float}
     */
    public function calculate(DarkStore $store, Coordinates $customerLocation, ?float $packingMinutes = null): array
    {
        $distanceKm = $store->location->distanceKmTo($customerLocation);

        // Deterministic mid-point of the 2-3 min packing band unless the
        // caller supplies an explicit packing time override.
        $packing = $packingMinutes ?? (self::MIN_PACKING_MINUTES + self::MAX_PACKING_MINUTES) / 2;

        $transitMinutes = ($distanceKm / self::AVERAGE_SPEED_KMPH) * 60;

        $total = $packing + $transitMinutes;

        return [
            'packing_minutes' => round($packing, 2),
            'transit_minutes' => round($transitMinutes, 2),
            'total_minutes'   => round($total, 2),
            'distance_km'     => round($distanceKm, 3),
        ];
    }
}

/**
 * Enforces legal Order status transitions and mutates the Order + Rider
 * aggregates in lockstep (e.g. freeing the rider back to Available once
 * the order is Delivered).
 */
final class OrderStateMachine
{
    public function __construct(private readonly RiderRepository $riders)
    {
    }

    /**
     * @throws InvalidStateTransitionException if $to is not the legal next status.
     */
    public function advance(Order $order, OrderStatus $to): void
    {
        $expectedNext = $order->status()->next();

        if ($expectedNext !== $to) {
            $expectedLabel = $expectedNext?->value ?? '(no further transitions)';
            throw new InvalidStateTransitionException(
                "Cannot move order '{$order->id}' from '{$order->status()->value}' to " .
                "'{$to->value}'. Expected next status: '{$expectedLabel}'."
            );
        }

        $order->forceStatus($to);

        if ($to === OrderStatus::Delivered) {
            $riderId = $order->assignedRiderId();
            if ($riderId !== null) {
                $this->riders->find($riderId)?->markAvailable();
            }
        }
    }
}

/**
 * Top-level orchestrator: wires the geofencing, rider allocation, ETA and
 * state machine services together into a single "place an order" use case.
 */
final class OrderDispatchService
{
    public function __construct(
        private readonly DarkStoreLocator $storeLocator,
        private readonly RiderAllocator $riderAllocator,
        private readonly EtaCalculator $etaCalculator,
        private readonly OrderStateMachine $stateMachine,
        private readonly RiderRepository $riders,
        private readonly OrderRepository $orders,
        private readonly \Closure $logger,
    ) {
    }

    public function placeOrder(Order $order): void
    {
        $this->orders->add($order);
        $this->log("📦 Order '{$order->id}' PLACED for {$order->customerName} at {$order->deliveryLocation} — subtotal ₹" . number_format($order->subtotal(), 2));

        // 1. Dark Store Geofencing
        $store = $this->storeLocator->findNearestServiceableStore($order->deliveryLocation);
        $order->assignDarkStore($store);
        $distanceToStore = $store->location->distanceKmTo($order->deliveryLocation);
        $this->log("🏬 Nearest serviceable Dark Store: '{$store->name}' (id={$store->id}) — {$this->fmtKm($distanceToStore)} away");

        // 2. Move to Packing
        $this->stateMachine->advance($order, OrderStatus::Packing);
        $this->log("📋 Status -> {$order->status()->value}: {$order->status()->label()}");

        // 3. Smart Rider Allocation
        $rider = $this->riderAllocator->allocateNearestRider($store);
        $rider->markBusy();
        $order->assignRider($rider);
        $distanceRiderToStore = $rider->location()->distanceKmTo($store->location);
        $this->log("🛵 Rider allocated: '{$rider->name}' (id={$rider->id}) — {$this->fmtKm($distanceRiderToStore)} from store hub");

        // 4. Move to Rider_Assigned
        $this->stateMachine->advance($order, OrderStatus::RiderAssigned);
        $this->log("📋 Status -> {$order->status()->value}: {$order->status()->label()}");

        // 5. 10-Minute ETA Calculation
        $eta = $this->etaCalculator->calculate($store, $order->deliveryLocation);
        $order->setEtaMinutes($eta['total_minutes']);
        $this->log(sprintf(
            "⏱️  ETA calculated: packing %.1f min + transit %.1f min (%.3f km @ 25 km/h) = TOTAL %.1f min",
            $eta['packing_minutes'],
            $eta['transit_minutes'],
            $eta['distance_km'],
            $eta['total_minutes']
        ));

        // 6. Move to Out_For_Delivery
        $this->stateMachine->advance($order, OrderStatus::OutForDelivery);
        $this->log("📋 Status -> {$order->status()->value}: {$order->status()->label()}");

        // 7. Move to Delivered (simulated arrival) — frees the rider again.
        $this->stateMachine->advance($order, OrderStatus::Delivered);
        $this->log("📋 Status -> {$order->status()->value}: {$order->status()->label()}");
        $this->log("✅ Rider '{$rider->name}' is now back to status: {$rider->status()->value}");
    }

    private function fmtKm(float $km): string
    {
        return number_format($km, 3) . ' km';
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}

// =========================================================================
// SIMULATION ENTRY POINT
// =========================================================================

/**
 * Prints a timestamped line to the console. Kept outside the classes above
 * so the services stay fully unit-testable / framework-agnostic — logging
 * is injected as a plain closure.
 */
$consoleLogger = static function (string $message): void {
    $timestamp = (new \DateTimeImmutable())->format('H:i:s');
    echo "[{$timestamp}] {$message}" . PHP_EOL;
    // Small pause purely so the console log reads like a live dispatch feed.
    usleep(150_000);
};

echo str_repeat('=', 78) . PHP_EOL;
echo ' 10-MINUTE QUICK COMMERCE DISPATCH SIMULATION' . PHP_EOL;
echo str_repeat('=', 78) . PHP_EOL;

// ---- Repositories ----
$darkStoreRepo = new DarkStoreRepository();
$riderRepo     = new RiderRepository();
$orderRepo     = new OrderRepository();

// ---- Seed: 2 Dark Stores (Pune-based coordinates) ----
$storeHadapsar = new DarkStore(
    id: 'DS-HADAPSAR',
    name: 'Hadapsar Dark Store',
    location: new Coordinates(18.5011, 73.9268),
    serviceRadiusKm: 5.0,
);
$storeKharadi = new DarkStore(
    id: 'DS-KHARADI',
    name: 'Kharadi Dark Store',
    location: new Coordinates(18.5512, 73.9370),
    serviceRadiusKm: 5.0,
);
$darkStoreRepo->add($storeHadapsar);
$darkStoreRepo->add($storeKharadi);

// ---- Seed: 5 Riders (mixed locations/status, split across the 2 hubs) ----
$riderRepo->add(new Rider(
    id: 'RID-001',
    name: 'Rahul Sharma',
    location: new Coordinates(18.5040, 73.9300),
    status: RiderStatus::Available,
    darkStoreId: 'DS-HADAPSAR',
));
$riderRepo->add(new Rider(
    id: 'RID-002',
    name: 'Amit Kumar',
    location: new Coordinates(18.4950, 73.9200),
    status: RiderStatus::Busy,
    darkStoreId: 'DS-HADAPSAR',
));
$riderRepo->add(new Rider(
    id: 'RID-003',
    name: 'Suresh Patil',
    location: new Coordinates(18.5100, 73.9350),
    status: RiderStatus::Available,
    darkStoreId: 'DS-HADAPSAR',
));
$riderRepo->add(new Rider(
    id: 'RID-004',
    name: 'Vikram Singh',
    location: new Coordinates(18.5530, 73.9400),
    status: RiderStatus::Available,
    darkStoreId: 'DS-KHARADI',
));
$riderRepo->add(new Rider(
    id: 'RID-005',
    name: 'Karan Mehta',
    location: new Coordinates(18.5490, 73.9310),
    status: RiderStatus::Offline,
    darkStoreId: 'DS-KHARADI',
));

// ---- Seed: 1 customer checkout request (near the Hadapsar hub) ----
$customerOrder = new Order(
    id: 'ORD-100001',
    customerName: 'Priya Deshmukh',
    deliveryLocation: new Coordinates(18.5075, 73.9330),
    items: [
        new OrderItem(name: 'Amul Toned Milk 500ml', quantity: 2, unitPrice: 28.00),
        new OrderItem(name: 'Britannia Brown Bread', quantity: 1, unitPrice: 45.00),
        new OrderItem(name: 'Fresh Tomatoes 1kg',     quantity: 1, unitPrice: 32.00),
    ],
);

// ---- Wire up services ----
$storeLocator  = new DarkStoreLocator($darkStoreRepo);
$riderAllocator = new RiderAllocator($riderRepo);
$etaCalculator  = new EtaCalculator();
$stateMachine   = new OrderStateMachine($riderRepo);

$dispatchService = new OrderDispatchService(
    storeLocator: $storeLocator,
    riderAllocator: $riderAllocator,
    etaCalculator: $etaCalculator,
    stateMachine: $stateMachine,
    riders: $riderRepo,
    orders: $orderRepo,
    logger: $consoleLogger,
);

// ---- Run the simulation ----
try {
    $dispatchService->placeOrder($customerOrder);
} catch (NoDarkStoreInRangeException|NoAvailableRiderException|InvalidStateTransitionException $e) {
    echo '❌ Dispatch failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo str_repeat('=', 78) . PHP_EOL;
echo ' ORDER SUMMARY' . PHP_EOL;
echo str_repeat('=', 78) . PHP_EOL;

printf("Order ID          : %s\n", $customerOrder->id);
printf("Customer          : %s\n", $customerOrder->customerName);
printf("Delivery Location : %s\n", (string) $customerOrder->deliveryLocation);
printf("Dark Store        : %s\n", $customerOrder->assignedDarkStoreId());
printf("Assigned Rider    : %s\n", $customerOrder->assignedRiderId());
printf("Final Status      : %s\n", $customerOrder->status()->value);
printf("ETA (minutes)     : %.1f\n", $customerOrder->etaMinutes());
printf("Order Subtotal    : Rs. %s\n", number_format($customerOrder->subtotal(), 2));

echo PHP_EOL . 'Full status history:' . PHP_EOL;
foreach ($customerOrder->history() as $entry) {
    printf("  - %-16s at %s\n", $entry['status'], $entry['at']);
}

echo PHP_EOL . 'Simulation complete.' . PHP_EOL;
