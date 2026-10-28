<?php
declare(strict_types=1);

// Sample data for development and staging (fictional people only). Refuses to run in production.
// Usage: SEED_PASSWORD='choose-a-test-password1' php bin/seed.php
require dirname(__DIR__) . '/src/bootstrap.php';

use Blissful\Config;
use Blissful\Db;
use Blissful\Service\OfferService;
use Blissful\Service\ProfileService;
use Blissful\Service\RequestService;
use Blissful\Support\Clock;
use Blissful\Support\Crypto;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to seed a production database.\n");
    exit(1);
}
$password = getenv('SEED_PASSWORD') ?: Crypto::token(9) . '1';
$db = Db::get();
if ($db->value("SELECT 1 FROM users WHERE email LIKE '%@example.test' LIMIT 1")) {
    echo "Sample data already present.\n";
    exit(0);
}
$now = Clock::db();
$instr = array_column($db->all('SELECT id, slug FROM instruments'), 'id', 'slug');
$genre = array_column($db->all('SELECT id, slug FROM genres'), 'id', 'slug');

$mkUser = function (string $email, string $name, bool $musician) use ($db, $password, $now): int {
    $id = $db->insert('users', ['email' => $email, 'password_hash' => Crypto::hashPassword($password), 'display_name' => $name,
        'is_musician' => $musician ? 1 : 0, 'active_role' => $musician ? 'musician' : 'client', 'email_verified_at' => $now,
        'phone' => '+26377' . random_int(1000000, 9999999), 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('client_profiles', ['user_id' => $id, 'name' => $name, 'city' => 'Harare', 'updated_at' => $now]);
    return $id;
};

$clients = [
    ['grace.church@example.test', 'Grace Fellowship (sample)', 'church'],
    ['tendai.events@example.test', 'Tendai Events Co. (sample)', 'event_planner'],
    ['rudo.individual@example.test', 'Rudo M. (sample)', 'individual'],
];
foreach ($clients as [$email, $name, $type]) {
    $id = $mkUser($email, $name, false);
    $db->exec('UPDATE client_profiles SET org_type = ? WHERE user_id = ?', [$type, $id]);
}
$profiles = new ProfileService();
$musicians = [
    ['tafadzwa.bass@example.test', 'Tafadzwa Bass (sample)', ['bass-guitar'], ['gospel', 'jazz'], 8, 6000, -17.8292, 31.0522],
    ['nyasha.keys@example.test', 'Nyasha Keys (sample)', ['keyboard', 'piano'], ['gospel', 'worship'], 12, 8000, -17.8100, 31.0450],
    ['kuda.drums@example.test', 'Kuda on Drums (sample)', ['drums', 'percussion'], ['afro-pop', 'sungura'], 6, 7000, -17.8400, 31.0300],
    ['chipo.sax@example.test', 'Chipo Sax (sample)', ['saxophone'], ['jazz', 'rnb-soul'], 10, 12000, -17.7900, 31.0700],
    ['farai.guitar@example.test', 'Farai Strings (sample)', ['electric-guitar', 'acoustic-guitar'], ['afro-pop', 'rock'], 5, 6500, -17.8600, 31.0200],
    ['ruvimbo.violin@example.test', 'Ruvimbo Violin (sample)', ['violin'], ['classical', 'worship'], 9, 9000, -17.8000, 31.0600],
];
$mIds = [];
foreach ($musicians as [$email, $name, $ins, $gen, $years, $rate, $lat, $lng]) {
    $id = $mkUser($email, $name, true);
    $profiles->upsertMusician($id, [
        'stage_name' => $name, 'bio' => 'Sample musician profile for testing. Not a real person.', 'city' => 'Harare', 'area' => 'Avondale',
        'lat' => $lat, 'lng' => $lng, 'travel_radius_km' => 40, 'years_experience' => $years, 'typical_rate_minor' => $rate, 'currency' => 'USD',
        'equipment_provided' => 'Own instrument and cables', 'equipment_required' => 'Power outlet, DI box',
        'instrument_ids' => array_map(fn ($s) => (int) $instr[$s], $ins), 'genre_ids' => array_map(fn ($s) => (int) $genre[$s], $gen),
    ]);
    $mIds[$email] = $id;
}
$church = (int) $db->value('SELECT id FROM users WHERE email = ?', ['grace.church@example.test']);
$start = Clock::now()->modify('+10 days')->setTime(7, 0); // 09:00 Harare
$req = (new RequestService())->create($church, [
    'title' => 'Sunday service band', 'event_type' => 'church_service', 'description' => 'Two morning services with rehearsal on Saturday afternoon.',
    'starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'timezone' => 'Africa/Harare', 'duration_minutes' => 240, 'setup_minutes' => 45,
    'rehearsal_notes' => 'Saturday 14:00–16:00', 'city' => 'Harare', 'area' => 'Belgravia', 'lat' => -17.8150, 'lng' => 31.0400,
    'venue_name' => 'Grace Fellowship Hall', 'venue_address_private' => '12 Sample Road, Belgravia (sample)',
    'currency' => 'USD', 'equipment_available' => 'PA, drum kit, keyboard stand', 'travel_notes' => 'Parking on site',
    'offer_deadline' => $start->modify('-3 days')->format('Y-m-d\TH:i:s\Z'), 'publish' => true,
    'positions' => [
        ['instrument_id' => (int) $instr['bass-guitar'], 'quantity' => 1, 'budget_minor' => 5000],
        ['instrument_id' => (int) $instr['keyboard'], 'quantity' => 1, 'budget_minor' => 7000],
        ['instrument_id' => (int) $instr['drums'], 'quantity' => 1, 'budget_minor' => 6000],
    ],
]);
$offers = new OfferService();
$offers->submit($mIds['tafadzwa.bass@example.test'], $req['id'], ['position_id' => $req['positions'][0]['id'], 'accept_budget' => true, 'message' => 'Happy to play both services.']);
$offers->submit($mIds['nyasha.keys@example.test'], $req['id'], ['position_id' => $req['positions'][1]['id'], 'price_minor' => 8000, 'extras_minor' => 1000,
    'extras_note' => 'Transport from Borrowdale', 'included_services' => 'Rehearsal + 2 services', 'message' => 'I lead worship sets regularly.']);

echo "Seeded 3 clients, 6 musicians, 1 open request with 2 offers.\nAll sample accounts use the password: {$password}\n";
echo "Sample logins: grace.church@example.test (client), nyasha.keys@example.test (musician)\n";
