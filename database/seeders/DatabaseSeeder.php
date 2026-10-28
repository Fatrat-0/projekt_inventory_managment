<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        DB::transaction(fn () => $this->seedDemoData());
    }

    private function seedDemoData(): void
    {
        // 1. FELHASZNÁLÓK (6 fő)
        $admin = User::firstOrCreate(['email' => 'admin@raktar.hu'], [
            'name' => 'Kovács János (Admin)', 'password' => Hash::make('admin123'), 'role' => 'admin',
        ]);
        $manager = User::firstOrCreate(['email' => 'manager@raktar.hu'], [
            'name' => 'Nagy Éva (Raktárvezető)', 'password' => Hash::make('manager123'), 'role' => 'manager',
        ]);
        $workers = [
            User::firstOrCreate(['email' => 'worker@raktar.hu'], [
                'name' => 'Tóth Péter (Raktáros)', 'password' => Hash::make('worker123'), 'role' => 'worker',
            ]),
            User::firstOrCreate(['email' => 'szabo.gabor@raktar.hu'], [
                'name' => 'Szabó Gábor (Raktáros)', 'password' => Hash::make('worker123'), 'role' => 'worker',
            ]),
            User::firstOrCreate(['email' => 'kiss.anna@raktar.hu'], [
                'name' => 'Kiss Anna (Raktáros)', 'password' => Hash::make('worker123'), 'role' => 'worker',
            ]),
            User::firstOrCreate(['email' => 'varga.balazs@raktar.hu'], [
                'name' => 'Varga Balázs (Raktáros)', 'password' => Hash::make('worker123'), 'role' => 'worker',
            ]),
        ];

        // 2. RAKTÁRAK (5 telephely)
        $wh = [
            'bp'   => $this->warehouse(['warehouse_name' => 'Központi Elosztó Raktár (Budapest)', 'location' => '1117 Budapest, Logisztikai park 4.']),
            'pecs' => $this->warehouse(['warehouse_name' => 'Déli Regionális Telephely (Pécs)', 'location' => '7630 Pécs, Ipari út 12.']),
            'gyor' => $this->warehouse(['warehouse_name' => 'Nyugati Logisztikai Központ (Győr)', 'location' => '9027 Győr, Gyárvárosi út 8.']),
            'deb'  => $this->warehouse(['warehouse_name' => 'Keleti Tranzit Raktár (Debrecen)', 'location' => '4031 Debrecen, Határ út 19.']),
            'szeg' => $this->warehouse(['warehouse_name' => 'Dél-Alföldi Depó (Szeged)', 'location' => '6728 Szeged, Dorozsmai út 35.']),
        ];

        // 3. PARTNEREK (10 partner: 5 Beszállító és 5 Vevő)
        $partners = [
            ['name' => 'ProfiSzerszám Nagykereskedés Kft.', 'type' => 'supplier', 'email' => 'rendeles@profiszerszam.hu', 'phone' => '+36 1 555 1234'],
            ['name' => 'EuroSteel Ipari Ellátó Zrt.', 'type' => 'supplier', 'email' => 'info@eurosteel.hu', 'phone' => '+36 1 555 5678'],
            ['name' => 'HungaroSafety Munkavédelem Kft.', 'type' => 'supplier', 'email' => 'nagyker@hungarosafety.hu', 'phone' => '+36 20 987 6543'],
            ['name' => 'FixFast Rögzítéstechnika Bt.', 'type' => 'supplier', 'email' => 'ertekesites@fixfast.hu', 'phone' => '+36 30 222 3344'],
            ['name' => 'Villamosság-Net Kereskedelmi Kft.', 'type' => 'supplier', 'email' => 'raktar@villamossagnet.hu', 'phone' => '+36 1 888 9900'],
            ['name' => 'ÉpítőMester Kivitelező Kft.', 'type' => 'customer', 'email' => 'beszerzes@epitomester.hu', 'phone' => '+36 30 444 8899'],
            ['name' => 'Dél-Dunántúli Szerviz Bt.', 'type' => 'customer', 'email' => 'iroda@ddszerviz.hu', 'phone' => '+36 72 333 2211'],
            ['name' => 'Alföldi Acélszerkezet Zrt.', 'type' => 'customer', 'email' => 'logisztika@alfoldiacel.hu', 'phone' => '+36 52 666 7788'],
            ['name' => 'Győri Gépgyártó és Karbantartó Kft.', 'type' => 'customer', 'email' => 'raktar@gyorigep.hu', 'phone' => '+36 96 411 522'],
            ['name' => 'Tisza-Ép Ipari Park Kft.', 'type' => 'customer', 'email' => 'megrendeles@tiszaep.hu', 'phone' => '+36 62 777 111'],
        ];
        foreach ($partners as $p) {
            Partner::firstOrCreate(['email' => $p['email']], $p);
        }

        // 4. HIERARCHIKUS KATEGÓRIÁK (5 főkategória + 10 alkategória = 15 db)
        $catPower = $this->category(['category_name' => 'Elektromos gépek', 'parent_id' => null, 'default_item_min_stock' => 8, 'aggregate_min_stock' => 60]);
        $catHand  = $this->category(['category_name' => 'Kéziszerszámok', 'parent_id' => null, 'default_item_min_stock' => 15, 'aggregate_min_stock' => 80]);
        $catSafe  = $this->category(['category_name' => 'Munkavédelmi felszerelések', 'parent_id' => null, 'default_item_min_stock' => 20, 'aggregate_min_stock' => 100]);
        $catFast  = $this->category(['category_name' => 'Rögzítéstechnika és Segédanyagok', 'parent_id' => null, 'default_item_min_stock' => 40, 'aggregate_min_stock' => 200]);
        $catElec  = $this->category(['category_name' => 'Villanyszerelési anyagok', 'parent_id' => null, 'default_item_min_stock' => 25, 'aggregate_min_stock' => 120]);

        $cats = [
            'power'   => $catPower,
            'drills'  => $this->category(['category_name' => 'Fúrógépek és Csavarbehajtók', 'parent_id' => $catPower->category_id, 'default_item_min_stock' => 10, 'aggregate_min_stock' => 45]),
            'grind'   => $this->category(['category_name' => 'Csiszolás és Vágástechnika', 'parent_id' => $catPower->category_id, 'default_item_min_stock' => 8, 'aggregate_min_stock' => 50]), // ÖSSZ-MINIMUM RIASZTÁS LESZ
            'weld'    => $this->category(['category_name' => 'Hegesztéstechnika', 'parent_id' => $catPower->category_id, 'default_item_min_stock' => 5, 'aggregate_min_stock' => 20]),
            'hand'    => $catHand,
            'screw'   => $this->category(['category_name' => 'Csavarhúzók és Bit készletek', 'parent_id' => $catHand->category_id, 'default_item_min_stock' => 20, 'aggregate_min_stock' => 70]),
            'wrench'  => $this->category(['category_name' => 'Villáskulcsok és Nyomatékkulcsok', 'parent_id' => $catHand->category_id, 'default_item_min_stock' => 10, 'aggregate_min_stock' => 40]),
            'pliers'  => $this->category(['category_name' => 'Fogók és Vágószerszámok', 'parent_id' => $catHand->category_id, 'default_item_min_stock' => 12, 'aggregate_min_stock' => 45]),
            'foot'    => $this->category(['category_name' => 'Védőlábbelik és Ruházat', 'parent_id' => $catSafe->category_id, 'default_item_min_stock' => 12, 'aggregate_min_stock' => 60]), // ÖSSZ-MINIMUM RIASZTÁS LESZ
            'gloves'  => $this->category(['category_name' => 'Védőkesztyűk és Fejvédelem', 'parent_id' => $catSafe->category_id, 'default_item_min_stock' => 30, 'aggregate_min_stock' => 120]),
            'fast'    => $catFast,
            'chem'    => $this->category(['category_name' => 'Ipari Vegyi Anyagok és Ragasztók', 'parent_id' => $catFast->category_id, 'default_item_min_stock' => 25, 'aggregate_min_stock' => 90]),
            'cables'  => $this->category(['category_name' => 'Kábelek és Vezetékek', 'parent_id' => $catElec->category_id, 'default_item_min_stock' => 30, 'aggregate_min_stock' => 100]),
        ];

        // 5. TERMÉKEK ÉS KÉSZLETEK (45 termék -> garantáltan 2-3 oldalas PDF leltárív)
        // [Cikkszám, Megnevezés, Ár, Kategória kulcs, min_stock_override, [raktár => mennyiség]]
        $catalog = [
            // Fúrógépek
            ['GEP-FUR-18V', 'Ipari Akkus Ütvefúró 18V (2x5.0Ah)', 64900, 'drills', null, ['bp' => 14, 'pecs' => 6, 'gyor' => 5, 'deb' => 4]],
            ['GEP-BONT-1500', 'Bontókalapács SDS-Max 1500W', 145000, 'drills', 6, ['bp' => 2, 'gyor' => 1]], // HIÁNY (3 < 6)
            ['GEP-CSAV-12V', 'Kompakt Akkus Csavarbehajtó 12V', 38900, 'drills', 12, ['bp' => 10, 'deb' => 8, 'szeg' => 4]],
            ['GEP-FUR-SDS', 'Fúrókalapács SDS-Plus 850W', 49500, 'drills', null, ['bp' => 8, 'pecs' => 5, 'gyor' => 4]],
            ['GEP-MAG-1800', 'Állványos Magfúrógép 1800W', 210000, 'drills', 4, ['bp' => 3, 'deb' => 2]],

            // Csiszolás (Összesen 33 db < 50 aggregate_min_stock -> KATEGÓRIA RIASZTÁS!)
            ['GEP-CSISZ-125', 'Sarokcsiszoló 125mm 1400W', 32500, 'grind', null, ['bp' => 6, 'pecs' => 4]],
            ['GEP-CSISZ-230', 'Ipari Sarokcsiszoló 230mm 2400W', 54900, 'grind', 6, ['bp' => 5, 'gyor' => 3]],
            ['GEP-EXCEN-150', 'Excentercsiszoló 150mm 400W', 41200, 'grind', null, ['bp' => 5, 'deb' => 4]],
            ['GEP-SZALAG-900', 'Ipari Szalagcsiszoló 900W', 46800, 'grind', 10, ['bp' => 4, 'szeg' => 2]], // HIÁNY (6 < 10)

            // Egyéb elektromos és hegesztés
            ['GEP-POR-1200', 'Ipari Száraz-Nedves Porszívó 1200W', 79900, 'power', null, ['bp' => 3, 'gyor' => 2]], // HIÁNY (5 < 8 örökölt)
            ['GEP-HOLE-2000', 'Hőlégfúvó Pisztoly 2000W LCD', 19500, 'power', null, ['bp' => 9, 'pecs' => 6, 'szeg' => 4]],
            ['GEP-KORF-1600', 'Kézi Körfűrész 190mm 1600W', 52000, 'power', 8, ['bp' => 7, 'deb' => 5]],
            ['GEP-HEG-200A', 'Inverteres Hegesztőgép MMA 200A', 68000, 'weld', null, ['bp' => 6, 'gyor' => 4, 'deb' => 3]],
            ['GEP-HEG-MIG', 'CO2 Védőgázas Hegesztő MIG/MAG 250A', 185000, 'weld', 4, ['bp' => 4, 'pecs' => 2]],
            ['GEP-PAJZS-01', 'Automata Hegesztőpajzs TrueColor', 24900, 'weld', 10, ['bp' => 8, 'gyor' => 5, 'szeg' => 3]],

            // Csavarhúzók és Bitek
            ['KEZ-CSAV-007', 'Szigetelt Csavarhúzó Készlet 1000V (7 részes)', 11500, 'screw', null, ['bp' => 15, 'pecs' => 8, 'deb' => 6]],
            ['KEZ-BIT-032', 'Ütésálló Impact Bit Készlet (32 részes)', 6800, 'screw', 25, ['bp' => 20, 'gyor' => 15, 'szeg' => 10]],
            ['KEZ-IMBUSZ-09', 'Gömbvégű Imbuszkulcs Készlet (9 részes)', 5400, 'screw', null, ['bp' => 14, 'pecs' => 10, 'deb' => 8]],
            ['KEZ-TORX-08', 'Belsőkulcsnyílású Torx Készlet T9-T40', 6100, 'screw', 15, ['bp' => 12, 'gyor' => 9]],

            // Kulcsok
            ['KEZ-NYOM-200', 'Nyomatékkulcs 1/2" (40-200 Nm)', 28900, 'wrench', 8, ['bp' => 7, 'gyor' => 4, 'deb' => 3]],
            ['KEZ-KULCS-018', 'Csillag-villáskulcs Készlet (6-32mm, 18 részes)', 21400, 'wrench', null, ['bp' => 10, 'pecs' => 6, 'szeg' => 5]],
            ['KEZ-DUGO-094', 'Dugókulcs Készlet 1/4" és 1/2" (94 részes)', 34900, 'wrench', 12, ['bp' => 9, 'gyor' => 6, 'deb' => 5]],
            ['KEZ-CSO-450', 'Svéd Csőfogó 2" (450mm Cr-V)', 12800, 'wrench', null, ['bp' => 8, 'pecs' => 5]],

            // Fogók és Kéziszerszámok
            ['KEZ-KOMB-180', 'Ipari Kombinált Fogó 180mm', 4500, 'pliers', null, ['bp' => 12, 'pecs' => 8, 'gyor' => 6]],
            ['KEZ-OLDAL-160', 'Erőirtó Oldalcsípő Fogó 160mm', 5200, 'pliers', 15, ['bp' => 10, 'deb' => 8, 'szeg' => 5]],
            ['KEZ-BLANK-200', 'Automata Kábelcsupaszító Fogó 0.2-6mm²', 7900, 'pliers', null, ['bp' => 11, 'gyor' => 7]],
            ['KEZ-MERO-08M', 'Ipari Mérőszalag 8m (Mágneses)', 4200, 'hand', null, ['bp' => 14, 'pecs' => 10, 'szeg' => 6]],
            ['KEZ-VIZM-100', 'Alumínium Vízmérték 100cm (3 libellás)', 8900, 'hand', 10, ['bp' => 9, 'deb' => 6]],
            ['KEZ-KALAP-500', 'Üvegszálas Lakatos Kalapács 500g', 3600, 'hand', null, ['bp' => 16, 'pecs' => 8, 'gyor' => 6]],

            // Munkavédelem - Lábbeli és Ruházat (Összesen 32 db < 60 aggregate_min_stock -> KATEGÓRIA RIASZTÁS!)
            ['VED-BAK-S342', 'Acélbetétes Munkavédelmi Bakancs S3 (42)', 18900, 'foot', null, ['bp' => 8, 'pecs' => 6]],
            ['VED-BAK-S343', 'Acélbetétes Munkavédelmi Bakancs S3 (43)', 18900, 'foot', 15, ['pecs' => 4]], // HIÁNY (4 < 15)
            ['VED-CIPO-S1P', 'Kompozit Orrmerevítős Félcipő S1P (44)', 22500, 'foot', null, ['bp' => 4]], // HIÁNY (4 < 12 örökölt)
            ['VED-KAB-LATH', 'Láthatósági Bélelt Munkakabát (L)', 14200, 'foot', 10, ['bp' => 6, 'deb' => 4]],

            // Munkavédelem - Kesztyűk és Fejvédelem
            ['VED-KESZ-XL', 'Vágásbiztos Védőkesztyű Level-5 (XL)', 2400, 'gloves', 40, ['bp' => 30, 'pecs' => 15, 'gyor' => 10, 'deb' => 10]],
            ['VED-KESZ-NIT', 'Nitril Mártott Szerelőkesztyű (L)', 650, 'gloves', 60, ['bp' => 50, 'pecs' => 30, 'szeg' => 25]],
            ['VED-SISAK-01', 'Ipari Védősisak Állszíjjal (Fehér)', 5600, 'gloves', null, ['bp' => 20, 'gyor' => 18]],
            ['VED-SZEM-01', 'Karc- és Páramentes Védőszemüveg', 3200, 'gloves', 35, ['bp' => 25, 'deb' => 15, 'szeg' => 12]],
            ['VED-FUL-32DB', 'Aktív Fültok Hallásvédő 32dB', 8400, 'gloves', 20, ['bp' => 14, 'pecs' => 9]],

            // Rögzítéstechnika és Vegyi anyagok
            ['ROG-DUB-M10', 'Acél Dübel M10x100 (100 db/doboz)', 8900, 'fast', null, ['bp' => 60, 'pecs' => 30, 'deb' => 20]],
            ['ROG-CSAV-4X40', 'Forgácslapcsavar 4.0x40mm (500 db/doboz)', 3400, 'fast', 50, ['bp' => 45, 'gyor' => 30, 'szeg' => 25]],
            ['ROG-MENET-M12', 'Horganyzott Menetes Szár M12x1000mm', 1250, 'fast', 80, ['bp' => 70, 'pecs' => 40, 'deb' => 30]],
            ['VEGY-PUR-750', 'Tűzgátló Pisztolyos Purhab B1 (750ml)', 3800, 'chem', null, ['bp' => 25, 'gyor' => 15, 'szeg' => 10]],
            ['VEGY-FEK-500', 'Ipari Féktisztító Spray (500ml)', 1450, 'chem', 40, ['bp' => 30, 'pecs' => 20, 'deb' => 15]],

            // Villanyszerelési anyagok
            ['VIL-KOT-200', 'UV-álló Kábelkötegelő 200x4.8mm (100 db)', 1850, 'cables', 50, ['bp' => 20, 'gyor' => 10]], // HIÁNY (30 < 50)
            ['VIL-KAB-3X15', 'MBCu Tömör Rézkábel 3x1.5mm² (100m tekercs)', 36500, 'cables', null, ['bp' => 22, 'pecs' => 12, 'deb' => 10]],
            ['VIL-ELOSZ-400', 'Ipari Gumiházas Elosztó 400V 16A/32A', 29800, 'cables', 15, ['bp' => 12, 'gyor' => 8, 'szeg' => 6]],
        ];

        $productsBySku = [];

        foreach ($catalog as [$sku, $name, $price, $catKey, $override, $whStocks]) {
            $product = Product::firstOrCreate(['sku' => $sku], [
                'product_name' => $name,
                'price' => $price,
                'category_id' => $cats[$catKey]->category_id,
                'min_stock_override' => $override,
            ]);

            $productsBySku[$sku] = $product;

            // Opening stock is assigned only when this run creates the product.
            // Existing products may have real stock movements; never restore their seed quantities.
            foreach ($product->wasRecentlyCreated ? $whStocks : [] as $whKey => $qty) {
                Stock::create([
                    'product_id' => $product->product_id,
                    'warehouse_id' => $wh[$whKey]->warehouse_id,
                    'quantity' => $qty,
                ]);
            }
        }

        // 6. KÉSZLETMOZGÁSI NAPLÓ (45 tétel -> garantáltan 2 oldalas Mozgásnapló PDF)
        $skuKeys = array_keys($productsBySku);
        $whKeys = array_keys($wh);
        $supplierNames = ['ProfiSzerszám Kft.', 'EuroSteel Zrt.', 'HungaroSafety Kft.', 'FixFast Bt.', 'Villamosság-Net Kft.'];
        $customerNames = ['ÉpítőMester Kft.', 'Dél-Dunántúli Szerviz Bt.', 'Alföldi Acélszerkezet Zrt.', 'Győri Gépgyártó Kft.', 'Tisza-Ép Kft.'];

        for ($i = 1; $i <= 45; $i++) {
            $isIn = ($i % 3 !== 0); // 2/3 rész bevételezés, 1/3 rész kiadás
            $product = $productsBySku[$skuKeys[$i % count($skuKeys)]];
            $warehouse = $wh[$whKeys[$i % count($whKeys)]];
            $user = ($i % 4 === 0) ? $manager : $workers[$i % count($workers)];
            $qty = ($i % 5 + 1) * 4;
            $docNum = str_pad((string)$i, 3, '0', STR_PAD_LEFT);

            $ref = $isIn
                ? "BEV-2026/{$docNum} (" . $supplierNames[$i % count($supplierNames)] . ")"
                : "KIAD-2026/{$docNum} (" . $customerNames[$i % count($customerNames)] . ")";

            $movement = InventoryMovement::firstOrCreate([
                'reference' => $ref,
                'product_id' => $product->product_id,
                'warehouse_id' => $warehouse->warehouse_id,
            ], [
                'type' => $isIn ? 'in' : 'out',
                'quantity' => $qty,
                'reference' => $ref,
                'user_id' => $user->id,
            ]);

            // Időbélyeg eltolása az elmúlt 25 napra, hogy életszerű dátumok legyenek a PDF-ben
            if ($movement->wasRecentlyCreated) {
                $movement->created_at = now()->subDays(25 - intdiv($i, 2))->subHours($i % 12);
                $movement->saveQuietly();
            }
        }

        // 7. RAKTÁRKÖZI ÁTMOZGATÁSOK (10 tétel vegyes státusszal)
        $transfersData = [
            ['GEP-FUR-18V', 'bp', 'pecs', 4, $workers[0]->id, 'pending'],
            ['VED-KESZ-XL', 'bp', 'deb', 15, $workers[1]->id, 'pending'],
            ['KEZ-BIT-032', 'gyor', 'szeg', 8, $workers[2]->id, 'pending'],
            ['VIL-KAB-3X15', 'bp', 'gyor', 5, $workers[3]->id, 'pending'],
            ['KEZ-CSAV-007', 'bp', 'deb', 6, $manager->id, 'completed'],
            ['ROG-DUB-M10', 'bp', 'pecs', 25, $workers[0]->id, 'completed'],
            ['GEP-HEG-200A', 'bp', 'szeg', 2, $manager->id, 'completed'],
            ['KEZ-DUGO-094', 'gyor', 'pecs', 3, $workers[1]->id, 'completed'],
            ['GEP-CSISZ-125', 'pecs', 'bp', 2, $workers[0]->id, 'cancelled'],
            ['VED-SISAK-01', 'gyor', 'deb', 5, $workers[2]->id, 'cancelled'],
        ];

        foreach ($transfersData as [$sku, $fromKey, $toKey, $qty, $userId, $status]) {
            $identity = [
                'product_id' => $productsBySku[$sku]->product_id,
                'from_warehouse_id' => $wh[$fromKey]->warehouse_id,
                'to_warehouse_id' => $wh[$toKey]->warehouse_id,
                'quantity' => $qty,
                'user_id' => $userId,
            ];
            if (InventoryTransfer::where($identity)->exists()) {
                continue;
            }
            // Reserve stock just as the application does, including for pending examples.
            $inventory = app(InventoryService::class);
            $transfer = $inventory->startTransfer($identity);
            if ($status !== 'pending') {
                $inventory->finalizeTransfer($transfer, $status === 'completed');
            }
        }
        // 8. Új, valós szolgáltatáson át rögzített auditpéldák; a régi naplósorok megmaradnak.
        $this->call(MovementAuditDemoSeeder::class);
        $this->call(ShipmentDemoSeeder::class);
    }

    private function warehouse(array $data): Warehouse
    {
        return Warehouse::firstOrCreate(['warehouse_name' => $data['warehouse_name']], $data);
    }

    private function category(array $data): Category
    {
        return Category::firstOrCreate(['category_name' => $data['category_name'], 'parent_id' => $data['parent_id'] ?? null], $data);
    }
}
