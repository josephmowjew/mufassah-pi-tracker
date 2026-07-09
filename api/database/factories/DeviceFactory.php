<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\IotModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Device models organized by category slug for realistic data.
     * Each category contains brands with their respective models.
     */
    public static array $devicesByCategory = [
        'smartphone' => [
            'Apple' => ['iPhone 15 Pro Max', 'iPhone 15 Pro', 'iPhone 15 Plus', 'iPhone 15', 'iPhone 14 Pro Max', 'iPhone 14 Pro', 'iPhone 14', 'iPhone 13', 'iPhone SE'],
            'Samsung' => ['Galaxy S24 Ultra', 'Galaxy S24+', 'Galaxy S24', 'Galaxy S23 Ultra', 'Galaxy S23', 'Galaxy Z Fold 5', 'Galaxy Z Flip 5', 'Galaxy A54', 'Galaxy A34', 'Galaxy A14'],
            'Google' => ['Pixel 8 Pro', 'Pixel 8', 'Pixel 7a', 'Pixel 7 Pro', 'Pixel 7', 'Pixel Fold'],
            'Xiaomi' => ['Xiaomi 14 Pro', 'Xiaomi 14', 'Redmi Note 13 Pro', 'Redmi Note 13', 'Poco X6 Pro', 'Poco F5'],
            'OnePlus' => ['OnePlus 12', 'OnePlus 12R', 'OnePlus Open', 'OnePlus Nord 3'],
            'Huawei' => ['Mate 60 Pro', 'P60 Pro', 'Nova 11'],
            'Oppo' => ['Find X7 Ultra', 'Find X7', 'Reno 11 Pro', 'Reno 11', 'A79', 'A58'],
            'Tecno' => ['Camon 20 Pro', 'Camon 20', 'Spark 20 Pro', 'Spark 20', 'Pova 5 Pro', 'Pova 5', 'Phantom X2 Pro'],
            'Infinix' => ['Note 30 Pro', 'Note 30', 'Hot 30i', 'Hot 30', 'Zero 30', 'Smart 8', 'GT 10 Pro'],
            'itel' => ['S23+', 'S23', 'A70', 'A60', 'P55+', 'P55'],
            'Sony' => ['Xperia 1 V', 'Xperia 5 V', 'Xperia 10 V'],
        ],
        'laptop' => [
            'Apple' => ['MacBook Pro 14"', 'MacBook Pro 16"', 'MacBook Air M2', 'MacBook Air M3'],
            'Dell' => ['XPS 15', 'XPS 13 Plus', 'XPS 17', 'Inspiron 15', 'Inspiron 14', 'Latitude 7430', 'Latitude 5530', 'Precision 5570', 'Alienware m16'],
            'HP' => ['Spectre x360 14', 'Spectre x360 16', 'Envy x360 15', 'Pavilion 15', 'EliteBook 840 G9', 'ProBook 450 G9', 'Omen 16', 'Victus 15'],
            'Lenovo' => ['ThinkPad X1 Carbon', 'ThinkPad T14s', 'ThinkPad E14', 'IdeaPad Slim 5', 'Yoga 9i', 'Legion 5 Pro', 'Legion 7i'],
            'Asus' => ['ROG Zephyrus G14', 'ROG Strix G16', 'ZenBook 14 OLED', 'VivoBook 15', 'ProArt StudioBook'],
            'LG' => ['Gram 17', 'Gram 16', 'Gram 14'],
            'Microsoft' => ['Surface Laptop 5', 'Surface Laptop Studio 2'],
            'Huawei' => ['MateBook X Pro', 'MateBook 14s', 'MateBook D15'],
        ],
        'tablet' => [
            'Apple' => ['iPad Pro 12.9"', 'iPad Pro 11"', 'iPad Air', 'iPad Mini', 'iPad 10th Gen'],
            'Samsung' => ['Galaxy Tab S9 Ultra', 'Galaxy Tab S9+', 'Galaxy Tab S9', 'Galaxy Tab A9+', 'Galaxy Tab A9'],
            'Microsoft' => ['Surface Pro 9', 'Surface Go 3'],
            'Lenovo' => ['Tab P12 Pro', 'Tab P11 Pro', 'Tab M10 Plus'],
            'Huawei' => ['MatePad Pro 13.2', 'MatePad 11.5', 'MatePad SE'],
            'Xiaomi' => ['Mi Pad 6 Pro', 'Mi Pad 6', 'Redmi Pad'],
            'Google' => ['Pixel Tablet'],
        ],
        'television' => [
            'Samsung' => ['Neo QLED 8K 85"', 'Neo QLED 4K 75"', 'Crystal UHD 65"', 'Crystal UHD 55"', 'The Frame 55"', 'OLED 65"'],
            'LG' => ['OLED evo G3 77"', 'OLED evo C3 65"', 'QNED 75"', 'NanoCell 65"', 'UHD 55"', 'UHD 43"'],
            'Sony' => ['Bravia XR A95L 65"', 'Bravia XR X95L 75"', 'Bravia X85L 55"', 'Bravia X80L 50"'],
            'TCL' => ['QM8 98"', 'QM8 75"', 'Q7 65"', 'S4 55"', 'S4 43"'],
            'Hisense' => ['U8K 65"', 'U7K 55"', 'A6K 50"', 'A4K 43"'],
            'Skyworth' => ['OLED 55"', 'QLED 65"', 'Android TV 43"', 'Smart TV 32"'],
        ],
        'wearable-device' => [
            'Apple' => ['Apple Watch Series 9', 'Apple Watch Ultra 2', 'Apple Watch SE'],
            'Samsung' => ['Galaxy Watch 6 Classic', 'Galaxy Watch 6', 'Galaxy Fit 3'],
            'Google' => ['Pixel Watch 2', 'Pixel Watch'],
            'Garmin' => ['Fenix 7X', 'Forerunner 965', 'Venu 3', 'Vivoactive 5'],
            'Fitbit' => ['Sense 2', 'Versa 4', 'Charge 6', 'Inspire 3'],
            'Xiaomi' => ['Xiaomi Watch S3', 'Xiaomi Band 8 Pro', 'Xiaomi Band 8'],
            'Huawei' => ['Watch GT 4', 'Watch 4 Pro', 'Band 8'],
            'OnePlus' => ['OnePlus Watch 2'],
        ],
        'audio-equipment' => [
            'Apple' => ['AirPods Pro 2', 'AirPods 3rd Gen', 'AirPods Max', 'HomePod Mini', 'HomePod'],
            'Samsung' => ['Galaxy Buds 2 Pro', 'Galaxy Buds FE', 'Galaxy Buds Live'],
            'Sony' => ['WH-1000XM5', 'WF-1000XM5', 'WH-1000XM4', 'SRS-XB43', 'HT-A7000'],
            'Bose' => ['QuietComfort Ultra', 'QuietComfort 45', 'SoundLink Flex', 'Smart Soundbar 900'],
            'JBL' => ['Tour Pro 2', 'Live Pro 2', 'Charge 5', 'Flip 6', 'PartyBox 310'],
            'LG' => ['Tone Free T90', 'Tone Free FP9', 'XBOOM Go PL7'],
            'Sonos' => ['Era 300', 'Era 100', 'Beam Gen 2', 'Arc', 'Move 2'],
        ],
        'gaming-console' => [
            'Sony' => ['PlayStation 5', 'PlayStation 5 Digital Edition', 'PlayStation VR2'],
            'Microsoft' => ['Xbox Series X', 'Xbox Series S'],
            'Nintendo' => ['Nintendo Switch OLED', 'Nintendo Switch', 'Nintendo Switch Lite'],
            'Valve' => ['Steam Deck OLED', 'Steam Deck'],
            'Asus' => ['ROG Ally'],
        ],
        'camera' => [
            'Sony' => ['Alpha A7 IV', 'Alpha A7R V', 'Alpha A6700', 'ZV-E1', 'FX30'],
            'Canon' => ['EOS R6 Mark II', 'EOS R8', 'EOS R50', 'EOS 90D', 'PowerShot G7X III'],
            'Nikon' => ['Z8', 'Z6 III', 'Z5', 'Zf', 'Z30'],
            'Fujifilm' => ['X-T5', 'X-H2S', 'X-S20', 'X100VI'],
            'GoPro' => ['Hero 12 Black', 'Hero 11 Black', 'Hero 11 Mini'],
            'DJI' => ['Osmo Pocket 3', 'Action 4', 'Mavic 3 Pro', 'Mini 4 Pro'],
        ],
        'desktop-computer' => [
            'Apple' => ['iMac 24"', 'Mac Mini M2', 'Mac Mini M2 Pro', 'Mac Studio', 'Mac Pro'],
            'Dell' => ['OptiPlex 7010', 'Precision 3660', 'XPS Desktop', 'Alienware Aurora R16'],
            'HP' => ['Pavilion Desktop', 'OMEN 45L', 'EliteDesk 800 G9', 'ProDesk 400 G9'],
            'Lenovo' => ['ThinkCentre M90q', 'IdeaCentre AIO', 'Legion Tower 7i'],
            'Asus' => ['ROG Strix GT15', 'ProArt Station'],
        ],
        'printer' => [
            'HP' => ['LaserJet Pro M404dn', 'OfficeJet Pro 9015e', 'ENVY Inspire 7955e', 'Smart Tank 7602'],
            'Canon' => ['PIXMA TR8620a', 'imageCLASS MF453dw', 'MAXIFY GX7021', 'SELPHY CP1500'],
            'Epson' => ['EcoTank ET-4850', 'WorkForce Pro WF-4830', 'Expression Photo XP-970'],
            'Brother' => ['HL-L2350DW', 'MFC-L2750DW', 'MFC-J4535DW'],
        ],
        'network-equipment' => [
            'TP-Link' => ['Archer AXE300', 'Deco XE75', 'Archer AX6000', 'TL-SG108'],
            'Netgear' => ['Nighthawk RAXE500', 'Orbi RBKE963', 'Nighthawk M6'],
            'Asus' => ['ROG Rapture GT-AXE16000', 'ZenWiFi Pro ET12', 'RT-AX88U Pro'],
            'Ubiquiti' => ['Dream Machine Pro', 'UniFi 6 Pro', 'AmpliFi Alien'],
            'Cisco' => ['Meraki MR46', 'Catalyst 1200'],
        ],
        'home-appliance' => [
            'Samsung' => ['Bespoke Refrigerator', 'Smart Washer WF8800', 'Jet Bot AI+', 'Microwave MC32K7055'],
            'LG' => ['InstaView Refrigerator', 'TurboWash Washer', 'CordZero A9', 'NeoChef Microwave'],
            'Hisense' => ['Side-by-Side Fridge', 'Front Load Washer', 'Air Conditioner'],
            'Defy' => ['Double Door Fridge', 'Automaid Washer', 'Multifunction Oven'],
            'Whirlpool' => ['French Door Fridge', 'Top Load Washer', 'Built-in Oven'],
        ],
        'other-electronics' => [
            'Anker' => ['PowerCore 26800', 'PowerCore III Elite', '737 Charger', 'Soundcore Motion+'],
            'Belkin' => ['BoostCharge Pro', 'MagSafe 3-in-1', 'USB-C Hub'],
            'Samsung' => ['Portable SSD T7', 'Galaxy SmartTag 2'],
            'SanDisk' => ['Extreme Portable SSD', 'Ultra Dual Drive'],
            'Logitech' => ['MX Master 3S', 'MX Keys S', 'G Pro X Superlight'],
        ],
    ];

    /**
     * Purchase locations in Malawi.
     */
    private array $purchaseLocations = [
        // Major Electronics Retailers
        'Game Stores Lilongwe', 'Game Stores Blantyre', 'Shoprite Lilongwe',
        'Chipiku Stores', 'Peoples Trading Centre', 'Metro Cash & Carry',
        // Mobile Phone Shops
        'Airtel Service Centre Lilongwe', 'TNM Shop City Centre', 'MTL Shop Gateway Mall',
        'CellCity Lilongwe', 'Phone World Blantyre', 'Mobile Hub Old Town Mall',
        'iStore Malawi', 'Samsung Experience Store', 'Digicel Electronics',
        // Shopping Malls
        'Gateway Mall Lilongwe', 'Old Town Mall Lilongwe', 'Crossroads Complex',
        'Chichiri Shopping Centre Blantyre', 'Limbe Leaf Mall',
        // General Retailers
        'Sana Cash & Carry', 'Bingu Stadium Shopping Complex', 'Area 3 Shopping Centre',
        'Capital City Mall', 'Mzuzu City Centre', 'Zomba Mall',
        // Online/Other
        'Amazon (International Shipping)', 'Jumia', 'Facebook Marketplace',
        'Direct from Manufacturer', 'Company Asset', 'Gift',
    ];

    /**
     * English descriptions for devices.
     */
    private array $descriptions = [
        'Purchased new in sealed box. Excellent condition.',
        'Company issued device for work purposes.',
        'Upgraded from previous model. Works perfectly.',
        'Birthday gift from family members.',
        'Won in a company raffle. Brand new.',
        'Bought for school and educational purposes.',
        'Business device used for client meetings.',
        'Personal device for everyday use.',
        'Backup device kept for emergencies.',
        'Travel companion device for trips.',
        'Entertainment device for media consumption.',
        'Work from home essential device.',
        'Graduation gift. Very special to me.',
        'Anniversary present. In pristine condition.',
        'Device for photography and creative work.',
        'Gaming device for leisure time.',
        'Kids device with parental controls enabled.',
        'Family shared device.',
        'Professional equipment for business.',
        'Secondary device for backup purposes.',
    ];

    /**
     * English notes for devices.
     */
    private array $notes = [
        'Screen protector and case installed. IMEI verified.',
        'Extended warranty purchased. Keep receipt safe.',
        'Regular software updates applied. Battery health at 95%.',
        'Registered with manufacturer for warranty tracking.',
        'Insurance coverage active until next year.',
        'Device has been factory reset and setup fresh.',
        'All accessories included: charger, cable, earphones.',
        'Minor scratch on back cover, does not affect functionality.',
        'Previously repaired screen at authorized service centre.',
        'Battery replaced 6 months ago. Performs like new.',
        'Unlocked device, works with all Malawi carriers.',
        'Corporate device - return to IT department if found.',
        'Has sentimental value - family heirloom.',
        'Custom engraving on the back.',
        'Bought with education discount.',
        'Trade-in device from previous upgrade.',
        'Refurbished unit with full warranty.',
        'Demo unit purchased at discount.',
        'Part of employee benefits package.',
        'Water resistant tested and verified.',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Default to smartphone category
        $categorySlug = 'smartphone';
        $categoryData = self::$devicesByCategory[$categorySlug];
        $brand = fake()->randomElement(array_keys($categoryData));
        $model = fake()->randomElement($categoryData[$brand]);
        $colors = ['Black', 'White', 'Silver', 'Gold', 'Blue', 'Red', 'Green', 'Space Gray', 'Midnight', 'Starlight', 'Purple', 'Graphite', 'Deep Purple', 'Titanium'];

        return [
            'unique_device_code' => 'DEV-'.strtoupper(Str::random(12)),
            'serial_number' => strtoupper(Str::random(4)).'-'.fake()->numerify('######'),
            'category_id' => DeviceCategory::factory(),
            'owner_id' => User::factory(),
            'iot_module_id' => null,
            'brand' => $brand,
            'model' => $model,
            'description' => fake()->optional(0.7)->randomElement($this->descriptions),
            'color' => fake()->randomElement($colors),
            'purchase_date' => fake()->dateTimeBetween('-3 years', '-1 day'),
            'purchase_price' => fake()->randomFloat(2, 50000, 2500000), // MWK prices
            'purchase_location' => fake()->optional(0.6)->randomElement($this->purchaseLocations),
            'status' => DeviceStatus::ACTIVE,
            'notes' => fake()->optional(0.3)->randomElement($this->notes),
            'photos' => null,
            'last_location_id' => null,
            'last_seen_at' => fake()->optional(0.8)->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Set device attributes appropriate for a specific category.
     */
    public function forCategorySlug(string $categorySlug): static
    {
        return $this->state(function (array $attributes) use ($categorySlug) {
            $categoryData = self::$devicesByCategory[$categorySlug] ?? self::$devicesByCategory['smartphone'];
            $brand = fake()->randomElement(array_keys($categoryData));
            $model = fake()->randomElement($categoryData[$brand]);

            return [
                'brand' => $brand,
                'model' => $model,
            ];
        });
    }

    /**
     * Get a random brand and model for a specific category slug.
     */
    public static function getRandomDeviceForCategory(string $categorySlug): array
    {
        $categoryData = self::$devicesByCategory[$categorySlug] ?? self::$devicesByCategory['smartphone'];
        $brand = fake()->randomElement(array_keys($categoryData));
        $model = fake()->randomElement($categoryData[$brand]);

        return ['brand' => $brand, 'model' => $model];
    }

    /**
     * Indicate that the device is stolen.
     */
    public function stolen(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeviceStatus::STOLEN,
        ]);
    }

    /**
     * Indicate that the device was recovered.
     */
    public function recovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeviceStatus::RECOVERED,
        ]);
    }

    /**
     * Indicate that the device is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeviceStatus::INACTIVE,
        ]);
    }

    /**
     * Indicate that the device is in maintenance.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeviceStatus::MAINTENANCE,
        ]);
    }

    /**
     * Attach an IoT module to the device.
     */
    public function withIotModule(): static
    {
        return $this->state(fn (array $attributes) => [
            'iot_module_id' => IotModule::factory(),
        ]);
    }

    /**
     * Set a specific owner for the device.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => $user->id,
        ]);
    }

    /**
     * Set a specific category for the device.
     */
    public function inCategory(DeviceCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    /**
     * Add photos to the device.
     */
    public function withPhotos(int $count = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'photos' => collect(range(1, $count))->map(fn () => [
                'url' => fake()->imageUrl(640, 480, 'technics'),
                'caption' => fake()->optional()->sentence(3),
            ])->toArray(),
        ]);
    }
}
